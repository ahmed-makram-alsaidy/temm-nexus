<?php

namespace Tests\Feature\Phase42;

use App\Models\AiProviderConfig;
use App\Services\ControlPlane\Ai\OpenAiCompatibleDriver;
use App\Services\ControlPlane\Ai\TransportTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 0.4.0-rc.7 — transport instrumentation regression.
 *
 * Proves, at the RAW WIRE level (a loopback socket beyond the reach of
 * Http::fake), that:
 *
 *   - the effective outgoing User-Agent is exactly the configured
 *     codex_cli_rs/0.149.1 — Laravel does not override it after the custom
 *     header merge, and Guzzle does not override it on transfer;
 *   - without a configured User-Agent, Guzzle stamps its own default — the
 *     exact failure mode AgentRouter-class gateways refuse;
 *   - custom provider headers apply LAST while the Authorization credential
 *     is re-asserted after the merge;
 *   - TransportTelemetry records URL, method, status, Content-Type,
 *     effective User-Agent, request JSON field names and the stream value —
 *     and can never contain the API key or the Authorization value.
 */
class TransportTelemetryTest extends TestCase
{
    use RefreshDatabase;
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

    protected function successBody(string $content = 'HELLO', ?string $finishReason = 'stop'): array
    {
        $choice = ['message' => ['role' => 'assistant', 'content' => $content]];
        if ($finishReason !== null) {
            $choice['finish_reason'] = $finishReason;
        }

        return [
            'choices' => [$choice],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 2],
        ];
    }

    // ── 1 — the effective outgoing User-Agent, on the raw wire ─────────

    public function test_effective_outgoing_user_agent_is_codex_cli_rs_0_149_1_on_the_raw_wire(): void
    {
        $captures = $this->captureWireRequests(1);

        try {
            $result = json_decode((string) file_get_contents($captures['dir'].'/client_result.json'), true);
            $this->assertSame('CONNECTED', $result['test'], 'driver test() must succeed over the loopback wire');
            $this->assertSame('WIRE-OK', $result['complete'], 'driver complete() must succeed over the loopback wire');

            foreach ([1, 2] as $i) {
                $raw = (string) file_get_contents($captures['dir']."/capture_{$i}.txt");

                // The configured UA reaches the wire VERBATIM — neither Laravel
                // nor Guzzle replaces it after the custom header merge.
                $this->assertStringContainsString('User-Agent: codex_cli_rs/0.149.1', $raw, "capture_{$i}");
                $this->assertStringNotContainsString('GuzzleHttp', $raw, "capture_{$i}");

                // Exactly one credential header, and it is the vault token.
                $this->assertSame(1, preg_match_all('/^Authorization: /mi', $raw), "capture_{$i}");
                $this->assertStringContainsString('Authorization: Bearer sk-wire-capture-fake-key', $raw, "capture_{$i}");

                // Request JSON shape: explicit stream value on both probes.
                [, $body] = explode("\r\n\r\n", $raw, 2);
                $json = json_decode($body, true);
                $this->assertSame(['model', 'messages', 'max_tokens', 'stream'], array_keys($json), "capture_{$i}");
                $this->assertFalse($json['stream'], "capture_{$i}");
            }
        } finally {
            $this->cleanupCapture($captures);
        }
    }

    public function test_without_a_configured_user_agent_guzzle_stamps_its_own_default(): void
    {
        // Documents the exact failure mode: a provider row WITHOUT the custom
        // UA goes out as GuzzleHttp/7 — the shape AgentRouter refuses. This
        // is why the header merge contract matters.
        $captures = $this->captureWireRequests(0);

        try {
            $raw = (string) file_get_contents($captures['dir'].'/capture_1.txt');
            $this->assertStringContainsString('User-Agent: GuzzleHttp/', $raw);
            $this->assertStringNotContainsString('codex_cli_rs', $raw);
        } finally {
            $this->cleanupCapture($captures);
        }
    }

    // ── 2 — merge order contract: custom last, credential re-asserted ──

    public function test_custom_headers_apply_last_and_authorization_is_re_asserted(): void
    {
        $method = new ReflectionMethod(OpenAiCompatibleDriver::class, 'client');
        $pending = $method->invoke(new OpenAiCompatibleDriver, $this->agentRouterConfig());
        $headers = $pending->getOptions()['headers'];

        // The ORDER is the contract: framework default first, custom header
        // last over the defaults, protected credential re-asserted after.
        $this->assertSame(['Accept', 'User-Agent', 'Authorization'], array_keys($headers));
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('codex_cli_rs/0.149.1', $headers['User-Agent']);
        $this->assertSame('Bearer sk-real-key-never-displayed', $headers['Authorization']);
    }

    // ── 3 — telemetry records the required wire facts ───────────────────

    public function test_telemetry_records_url_method_status_content_type_user_agent_and_json_fields(): void
    {
        $logPath = storage_path('logs/telemetry-facts-'.uniqid().'.log');
        $this->enableTestTelemetryChannel($logPath);

        Http::fake(['*' => Http::response($this->successBody('ok'))]);
        $config = $this->agentRouterConfig();
        $driver = new OpenAiCompatibleDriver;

        $driver->test($config);
        $driver->complete($config, [['role' => 'user', 'content' => 'ping']], []);

        $contents = (string) file_get_contents($logPath);

        $this->assertStringContainsString('ai_transport.request', $contents);
        $this->assertStringContainsString('ai_transport.response', $contents);
        $this->assertStringContainsString('"method":"POST"', $contents);
        $this->assertStringContainsString('"status":200', $contents);
        $this->assertStringContainsString('"content_type":"application/json"', $contents);
        $this->assertStringContainsString('"user_agent":"codex_cli_rs/0.149.1"', $contents);
        $this->assertStringContainsString('"request_json_fields":["model","messages","max_tokens","stream"]', $contents);
        $this->assertStringContainsString('"stream":false', $contents);
        // Both wire kinds are observable — Test Connection AND real chat
        // (each kind appears in the request AND the response entry).
        $this->assertSame(2, substr_count($contents, '"kind":"test_connection"'));
        $this->assertSame(2, substr_count($contents, '"kind":"chat"'));

        @unlink($logPath);
    }

    public function test_telemetry_records_the_final_url(): void
    {
        $logPath = storage_path('logs/telemetry-url-'.uniqid().'.log');
        $this->enableTestTelemetryChannel($logPath);

        Http::fake(['*' => Http::response($this->successBody('ok'))]);
        (new OpenAiCompatibleDriver)->test($this->agentRouterConfig());

        $contents = (string) file_get_contents($logPath);
        $this->assertStringContainsString('"url":"https://agentrouter.example/v1/chat/completions"', $contents);

        @unlink($logPath);
    }

    // ── 4 — telemetry is structurally incapable of leaking secrets ──────

    public function test_telemetry_never_contains_the_api_key_or_authorization_value(): void
    {
        $logPath = storage_path('logs/telemetry-leak-'.uniqid().'.log');
        $this->enableTestTelemetryChannel($logPath);

        // The provider "echoes" the key back in a 403 body — telemetry must
        // still never contain it.
        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'refused key sk-real-key-never-displayed']],
            403
        )]);
        $config = $this->agentRouterConfig();
        $driver = new OpenAiCompatibleDriver;

        $this->assertSame(OpenAiCompatibleDriver::RESULT_AUTH_FAILED, $driver->test($config));
        try {
            $driver->complete($config, [['role' => 'user', 'content' => 'ping']], []);
            $this->fail('complete() must fail on 403');
        } catch (\App\Services\ControlPlane\Ai\AiProviderException) {
            // expected — the interesting assertion is what got logged
        }

        $contents = (string) file_get_contents($logPath);

        $this->assertStringNotContainsString('sk-real-key-never-displayed', $contents);
        $this->assertStringNotContainsString('"Authorization":"Bearer', $contents);
        $this->assertStringContainsString('"Authorization":"[protected]"', $contents);
        $this->assertStringContainsString('[redacted]', $contents, 'the echoed key in the 403 body must be redacted');
        // The effective User-Agent stays visible (it is the fact under test).
        $this->assertStringContainsString('"user_agent":"codex_cli_rs/0.149.1"', $contents);

        @unlink($logPath);
    }

    public function test_connection_failures_are_telemetered_without_secrets(): void
    {
        $logPath = storage_path('logs/telemetry-conn-'.uniqid().'.log');
        $this->enableTestTelemetryChannel($logPath);

        $config = $this->agentRouterConfig();
        TransportTelemetry::connectionFailure(
            $config,
            'test_connection',
            ConnectionException::class,
            'cURL error 28 while talking to agentrouter.example (key sk-real-key-never-displayed)'
        );

        $contents = (string) file_get_contents($logPath);
        $this->assertStringContainsString('ai_transport.connection_failure', $contents);
        $this->assertStringContainsString('ConnectionException', $contents);
        $this->assertStringNotContainsString('sk-real-key-never-displayed', $contents);

        @unlink($logPath);
    }

    public function test_telemetry_can_be_disabled(): void
    {
        $logPath = storage_path('logs/telemetry-off-'.uniqid().'.log');
        $this->enableTestTelemetryChannel($logPath);
        config(['nexus-ai.telemetry.enabled' => false]);

        Http::fake(['*' => Http::response($this->successBody('ok'))]);
        $this->assertSame(OpenAiCompatibleDriver::RESULT_CONNECTED, (new OpenAiCompatibleDriver)->test($this->agentRouterConfig()));

        clearstatcache();
        $this->assertFileDoesNotExist($logPath);

        @unlink($logPath);
    }

    // ── 5 — the probe can no longer misclassify a starved reasoning model ─

    public function test_probe_that_hits_its_token_budget_still_classifies_connected(): void
    {
        // Reasoning models spend a tiny budget on reasoning and answer 200
        // with EMPTY content + finish_reason "length". Auth, model and the
        // completion pipeline are all proven — this is CONNECTED, not an
        // error.
        Http::fake(['*' => Http::response($this->successBody('', 'length'))]);

        $result = (new OpenAiCompatibleDriver)->test($this->agentRouterConfig());

        $this->assertSame(OpenAiCompatibleDriver::RESULT_CONNECTED, $result);
    }

    // ── 6 — the SSRF guard keeps refusing loopback unless explicitly allowed ─

    public function test_ssrf_guard_still_refuses_loopback_by_default(): void
    {
        $config = $this->agentRouterConfig(['base_url' => 'http://127.0.0.1:9000/v1']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        \App\Services\ControlPlane\Ai\AiNetworkGuard::assertSafeBaseUrl($config);
    }

    public function test_ssrf_guard_allows_loopback_only_with_the_explicit_dev_flag(): void
    {
        config(['nexus-ai.allow_loopback_endpoints' => true]);
        $config = $this->agentRouterConfig(['base_url' => 'http://127.0.0.1:9000/v1']);

        \App\Services\ControlPlane\Ai\AiNetworkGuard::assertSafeBaseUrl($config);

        $this->expectNotToPerformAssertions();
    }

    public function test_probe_uses_a_small_sane_token_budget_not_one(): void
    {
        Http::fake(['*' => Http::response($this->successBody('ok'))]);
        $config = $this->agentRouterConfig(); // max_output_tokens: 64

        (new OpenAiCompatibleDriver)->test($config);

        Http::assertSent(fn ($request) => $request->data()['max_tokens'] === 32
            && $request->data()['stream'] === false
            && $request->data()['model'] === 'deepseek-v4-flash');
    }

    public function test_chat_and_test_connection_both_send_stream_false(): void
    {
        Http::fake(['*' => Http::response($this->successBody('ok'))]);
        $config = $this->agentRouterConfig();
        $driver = new OpenAiCompatibleDriver;

        $driver->test($config);
        $driver->complete($config, [['role' => 'user', 'content' => 'ping']], []);

        Http::assertSent(fn ($request) => $request->data()['stream'] === false);
        $this->assertSame(2, count(Http::recorded()));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Boot the loopback capture server + the real driver in a child process
     * and return the capture directory (caller cleans up).
     *
     * @return array{dir: string, server: resource|array, pipes: array}
     */
    protected function captureWireRequests(int $withCustomUa): array
    {
        if (! function_exists('proc_open') || ! function_exists('stream_socket_server')) {
            $this->markTestSkipped('proc_open/stream_socket_server are not available on this platform');
        }

        $dir = storage_path('framework/testing/wire-'.uniqid());
        @mkdir($dir, 0777, true);
        $php = escapeshellarg(PHP_BINARY);
        $fixtures = escapeshellarg(__DIR__.'/Fixtures');

        // The server prints its bound port on stdout as its first line.
        $serverCmd = "$php {$fixtures}/wire_server.php ".escapeshellarg($dir).' 2';
        $server = proc_open($serverCmd, [
            1 => ['pipe', 'w'],
            2 => ['file', $dir.'/server_err.txt', 'w'],
        ], $serverPipes);
        $this->assertIsResource($server, 'wire capture server failed to start');

        stream_set_timeout($serverPipes[1], 15);
        $port = trim((string) fgets($serverPipes[1]));
        $this->assertMatchesRegularExpression('/^\d+$/', $port, 'wire server did not report a port: '.$port);

        $clientCmd = "$php {$fixtures}/wire_client.php ".escapeshellarg($port)." {$withCustomUa} ".escapeshellarg($dir);
        $client = proc_open($clientCmd, [
            1 => ['file', $dir.'/client_out.txt', 'w'],
            2 => ['file', $dir.'/client_err.txt', 'w'],
        ], $clientPipes);

        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline && ! is_file($dir.'/client_result.json')) {
            if (is_resource($client)) {
                $status = proc_get_status($client);
                if ($status === false || ! $status['running']) {
                    break;
                }
            }
            usleep(100000);
        }

        $this->assertFileExists($dir.'/client_result.json', 'wire client did not finish: '
            .(is_file($dir.'/client_err.txt') ? (string) file_get_contents($dir.'/client_err.txt') : 'no stderr'));

        if (is_resource($client)) {
            proc_close($client);
        }
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }

        return ['dir' => $dir, 'server' => $server, 'pipes' => $serverPipes];
    }

    protected function cleanupCapture(array $captures): void
    {
        foreach (glob($captures['dir'].'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($captures['dir']);
    }

    protected function enableTestTelemetryChannel(string $logPath): void
    {
        config([
            'nexus-ai.telemetry.channel' => 'nexus-ai-test',
            'logging.channels.nexus-ai-test' => [
                'driver' => 'single',
                'path' => $logPath,
            ],
        ]);
    }
}
