<?php

namespace Tests\Feature\Phase42;

use App\Filament\Pages\NexusAiSettings;
use App\Models\AiProviderConfig;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Roles;
use App\Services\Ai\AiContext;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\AiProviderException;
use App\Services\ControlPlane\Ai\OpenAiCompatibleDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 0.4.0-rc.7 — OpenAI-compatible custom HTTP headers + AgentRouter
 * compatibility + safe provider-failure handling.
 *
 * Covers: custom User-Agent on every wire call, Test Connection / chat
 * header parity, forbidden Authorization/Host/Content-Length/Cookie
 * overrides, safe classified errors for 401/403/non-2xx and the
 * unauthorized_client_error envelope, choices[0].message.content
 * extraction (reasoning content never promoted), no blank assistant
 * replies, encrypted write-only header values, and the AgentRouter preset.
 */
class ProviderCustomHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    protected function agentRouterConfig(array $overrides = []): AiProviderConfig
    {
        return AiProviderConfig::create(array_merge([
            'provider' => 'openai_compatible',
            'display_name' => 'AgentRouter',
            'base_url' => 'https://agentrouter.example/v1',
            'model' => 'deepseek-v4-flash',
            'secret_encrypted' => 'sk-real-key-never-displayed',
            'enabled' => true,
            'status' => 'ready',
            'timeout_seconds' => 10,
            'max_output_tokens' => 64,
            'custom_headers' => ['User-Agent' => 'codex_cli_rs/0.149.1'],
        ], $overrides));
    }

    protected function successBody(string $content = 'HELLO'): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 2],
        ];
    }

    protected function engine(User $user): ConversationEngine
    {
        return new ConversationEngine(AiContext::platform(Access::for($user)), new ModelRouter);
    }

    // ── 1 — the custom User-Agent reaches the wire ─────────────────────

    public function test_custom_user_agent_header_is_sent_on_chat(): void
    {
        Http::fake(['*' => Http::response($this->successBody())]);
        $config = $this->agentRouterConfig();

        $result = (new AiGateway)->complete(null, ['config' => $config, 'model' => $config->model], [
            ['role' => 'user', 'content' => 'Reply exactly with HELLO'],
        ], []);

        $this->assertSame('HELLO', $result['text']);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'codex_cli_rs/0.149.1'));
    }

    // ── 2 — Test Connection and chat share ONE HTTP configuration ──────

    public function test_test_connection_uses_the_same_headers_as_chat(): void
    {
        Http::fake(['*' => Http::response($this->successBody('ok'))]);
        $config = $this->agentRouterConfig();
        $driver = new OpenAiCompatibleDriver;

        $this->assertSame(OpenAiCompatibleDriver::RESULT_CONNECTED, $driver->test($config));
        $driver->complete($config, [['role' => 'user', 'content' => 'ping']], []);

        Http::assertSent(function ($request): bool {
            // EVERY request — test and completion alike — carries the exact
            // same custom header. Parity is structural, not accidental.
            return $request->hasHeader('User-Agent', 'codex_cli_rs/0.149.1');
        });
        $this->assertSame(2, count(Http::recorded()), 'both test() and complete() must have hit the wire');
    }

    public function test_a_real_chat_turn_sends_the_custom_headers(): void
    {
        Http::fake(['*' => Http::response($this->successBody('HELLO'))]);
        $config = $this->agentRouterConfig();
        $user = $this->owner();

        $result = $this->engine($user)->turn('Reply exactly with HELLO');

        $this->assertTrue($result['ok']);
        $this->assertSame('HELLO', $result['reply']);
        $this->assertSame('AgentRouter', $result['provider']);
        $this->assertSame('deepseek-v4-flash', $result['model']);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'codex_cli_rs/0.149.1'));
    }

    // ── 3 — forbidden overrides can never reach the wire ───────────────

    public function test_forbidden_authorization_override_is_rejected_in_settings(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->set('providerForm.display_name', 'Gateway')
            ->set('providerForm.api_key', 'sk-key-1234567890')
            ->set('providerForm.custom_headers', [
                ['name' => 'Authorization', 'value' => 'Bearer forged-token'],
            ])
            ->call('saveProvider')
            ->assertHasErrors(['providerForm.custom_headers.0.name']);

        $this->assertSame(0, AiProviderConfig::count(), 'an invalid header map must not save the provider');
    }

    public function test_forbidden_headers_are_filtered_at_the_transport_boundary(): void
    {
        Http::fake(['*' => Http::response($this->successBody())]);
        // Stored state is never trusted blindly — even a hand-forged row
        // with a forbidden header cannot override the vault credential.
        $config = $this->agentRouterConfig([
            'custom_headers' => [
                'Authorization' => 'Bearer forged',
                'Host' => 'evil.example',
                'Content-Length' => '9999',
                'Cookie' => 'session=forged',
                'X-Legit' => 'allowed',
            ],
        ]);

        $driver = new OpenAiCompatibleDriver;
        $this->assertSame(['X-Legit' => 'allowed'], $driver->safeCustomHeaders($config));

        $driver->complete($config, [['role' => 'user', 'content' => 'ping']], []);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('X-Legit', 'allowed')
                && $request->header('Authorization') === ['Bearer sk-real-key-never-displayed']
                && $request->header('Host') !== ['evil.example']
                && $request->header('Content-Length') !== ['9999']
                && ! $request->hasHeader('Cookie');
        });
    }

    public function test_host_content_length_and_cookie_are_rejected_in_settings(): void
    {
        $this->actingAs($this->owner());

        foreach (['Host', 'Content-Length', 'Cookie'] as $forbidden) {
            Livewire::test(NexusAiSettings::class)
                ->set('providerForm.provider', 'openai_compatible')
                ->set('providerForm.display_name', 'Gateway')
                ->set('providerForm.api_key', 'sk-key-1234567890')
                ->set('providerForm.custom_headers', [['name' => $forbidden, 'value' => 'x']])
                ->call('saveProvider')
                ->assertHasErrors(['providerForm.custom_headers.0.name']);
        }
    }

    // ── 4 — provider failures become safe messages, never blank bubbles ─

    public function test_http_401_creates_a_safe_error_and_no_assistant_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        $config = $this->agentRouterConfig();
        $user = $this->owner();

        $result = $this->engine($user)->turn('Reply exactly with HELLO');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['reply']);
        $this->assertStringContainsString('Provider authentication/client identification failed', $result['error']);
        // No provider body, no key material in the surfaced message.
        $this->assertStringNotContainsString('bad key', $result['error']);
        $this->assertStringNotContainsString('sk-real-key', $result['error']);
    }

    public function test_http_403_creates_a_safe_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);
        $config = $this->agentRouterConfig();

        $result = $this->engine($this->owner())->turn('Reply exactly with HELLO');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Provider authentication/client identification failed', $result['error']);
    }

    public function test_unauthorized_client_error_envelope_creates_a_safe_error(): void
    {
        // AgentRouter's observed shape: the gateway refuses the client with
        // an unauthorized_client_error body. It must never look like success.
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'unauthorized_client_error',
                'message' => 'unauthorized client detected',
            ],
        ])]);
        $config = $this->agentRouterConfig();

        $result = $this->engine($this->owner())->turn('Reply exactly with HELLO');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['reply']);
        $this->assertStringContainsString('Provider authentication/client identification failed', $result['error']);
        $this->assertStringNotContainsString('unauthorized client detected', $result['error']);
    }

    public function test_http_500_creates_a_safe_classified_error(): void
    {
        Http::fake(['*' => Http::response('upstream explosion', 500)]);
        $config = $this->agentRouterConfig();

        $result = $this->engine($this->owner())->turn('Reply exactly with HELLO');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 500', $result['error']);
        $this->assertStringNotContainsString('upstream explosion', $result['error']);
    }

    // ── 5 — extraction: content only, reasoning never promoted ─────────

    public function test_successful_response_extracts_choices_message_content(): void
    {
        Http::fake(['*' => Http::response($this->successBody('HELLO'))]);
        $config = $this->agentRouterConfig();

        $result = (new AiGateway)->complete(null, ['config' => $config, 'model' => $config->model], [
            ['role' => 'user', 'content' => 'Reply exactly with HELLO'],
        ], []);

        $this->assertSame('HELLO', $result['text']);
        $this->assertSame(11, $result['usage']['input_tokens']);
        $this->assertSame(2, $result['usage']['output_tokens']);
    }

    public function test_reasoning_content_is_never_promoted_to_the_answer(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'HELLO',
                    'reasoning_content' => 'internal chain of thought that must stay private',
                ],
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ])]);
        $config = $this->agentRouterConfig();

        $result = (new OpenAiCompatibleDriver)->complete($config, [['role' => 'user', 'content' => 'ping']], []);

        $this->assertSame('HELLO', $result['text']);
        $this->assertStringNotContainsString('chain of thought', $result['text']);
    }

    public function test_reasoning_only_response_is_not_passed_off_as_content(): void
    {
        // A model that returns ONLY reasoning content has NOT answered.
        Http::fake(['*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_content' => 'a wall of hidden thinking',
                ],
            ]],
        ])]);
        $config = $this->agentRouterConfig();

        $result = $this->engine($this->owner())->turn('Reply exactly with HELLO');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['reply']);
        $this->assertStringContainsString('empty response', $result['error']);
        $this->assertStringNotContainsString('wall of hidden thinking', $result['error']);
    }

    // ── 6 — a blank reply can never look like a successful turn ────────

    public function test_empty_content_creates_a_safe_error_not_a_blank_reply(): void
    {
        Http::fake(['*' => Http::response($this->successBody(''))]);
        $config = $this->agentRouterConfig();

        $result = $this->engine($this->owner())->turn('Reply exactly with HELLO');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['reply']);
        $this->assertStringContainsString('empty response', $result['error']);
    }

    // ── 7 — storage: encrypted at rest, write-only in the form ─────────

    public function test_custom_header_values_are_encrypted_at_rest_and_never_repopulate(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->set('providerForm.display_name', 'AgentRouter')
            ->set('providerForm.base_url', 'https://agentrouter.org/v1')
            ->set('providerForm.model', 'deepseek-v4-flash')
            ->set('providerForm.api_key', 'sk-live-key-value')
            ->set('providerForm.custom_headers', [
                ['name' => 'User-Agent', 'value' => 'codex_cli_rs/0.149.1'],
            ])
            ->call('saveProvider')
            ->assertHasNoErrors();

        $config = AiProviderConfig::query()->where('provider', 'openai_compatible')->firstOrFail();

        // Encrypted at rest: the raw column never contains the plaintext.
        $raw = DB::table('ai_provider_configs')->where('id', $config->id)->value('custom_headers');
        $this->assertStringNotContainsString('codex_cli_rs', (string) $raw);
        $this->assertSame('codex_cli_rs/0.149.1', $config->refresh()->custom_headers['User-Agent']);

        // The form repopulates NAMES only — values never travel back.
        $component = Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible');
        $rows = $component->get('providerForm.custom_headers');
        $this->assertSame('User-Agent', $rows[0]['name']);
        $this->assertSame('', $rows[0]['value']);
    }

    public function test_leaving_a_header_value_blank_keeps_the_stored_value(): void
    {
        $this->actingAs($this->owner());
        $this->agentRouterConfig();

        // Mount loads the row with an empty value; saving without retyping
        // the value must keep the stored header, not wipe it.
        Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->call('saveProvider')
            ->assertHasNoErrors();

        $config = AiProviderConfig::query()->where('provider', 'openai_compatible')->firstOrFail();
        $this->assertSame('codex_cli_rs/0.149.1', $config->custom_headers['User-Agent']);
    }

    public function test_removing_a_header_row_deletes_it_on_save(): void
    {
        $this->actingAs($this->owner());
        $config = $this->agentRouterConfig(['custom_headers' => ['User-Agent' => 'codex_cli_rs/0.149.1', 'X-Extra' => 'v']]);

        Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->set('providerForm.custom_headers', [
                ['name' => 'User-Agent', 'value' => ''],
            ])
            ->call('saveProvider')
            ->assertHasNoErrors();

        $map = $config->refresh()->custom_headers;
        $this->assertSame(['User-Agent' => 'codex_cli_rs/0.149.1'], $map);
    }

    public function test_crlf_in_header_value_is_rejected(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->set('providerForm.display_name', 'Gateway')
            ->set('providerForm.api_key', 'sk-key-1234567890')
            ->set('providerForm.custom_headers', [
                ['name' => 'X-Injection', 'value' => "v\r\nX-Evil: 1"],
            ])
            ->call('saveProvider')
            ->assertHasErrors(['providerForm.custom_headers.0.value']);
    }

    // ── 8 — the AgentRouter preset ─────────────────────────────────────

    public function test_agentrouter_preset_fills_the_openai_compatible_form(): void
    {
        $this->actingAs($this->owner());

        $component = Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->call('applyAgentRouterPreset');

        $this->assertSame('AgentRouter', $component->get('providerForm.display_name'));
        $this->assertSame('https://agentrouter.org/v1', $component->get('providerForm.base_url'));
        $this->assertSame('deepseek-v4-flash', $component->get('providerForm.model'));
        $rows = $component->get('providerForm.custom_headers');
        $this->assertSame('User-Agent', $rows[0]['name']);
        $this->assertSame('codex_cli_rs/0.149.1', $rows[0]['value']);
    }

    public function test_agentrouter_preset_does_not_duplicate_an_existing_user_agent_row(): void
    {
        $this->actingAs($this->owner());

        $component = Livewire::test(NexusAiSettings::class)
            ->set('providerForm.provider', 'openai_compatible')
            ->call('applyAgentRouterPreset')
            ->call('applyAgentRouterPreset');

        $this->assertCount(1, $component->get('providerForm.custom_headers'));
    }
}
