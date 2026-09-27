<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\ProjectWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\ControlPlane\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
    }

    protected function makeWebhook(string $token, array $over = []): ProjectWebhook
    {
        return ProjectWebhook::create(array_merge([
            'project_id' => $this->project->id,
            'name' => 'fixture-hook',
            'url' => "http://caddy:80/cp-webhook-fixture/{$token}",
            'events' => ['custom.ping'],
            'enabled' => true,
            'signing_secret' => Str::random(48),
            'max_attempts' => 3,
            'timeout_s' => 10,
        ], $over));
    }

    public function test_signed_delivery_verified_by_fixture(): void
    {
        $token = 'cp20ok'.Str::random(10);
        $webhook = $this->makeWebhook($token);

        // Fixture needs the secret to verify — tests may pass it; GUI never does.
        $webhook->url .= '?secret='.$webhook->signing_secret;
        $webhook->save();

        $delivery = WebhookService::dispatch($webhook, 'custom.ping', ['ping' => true]);
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(200, $delivery->http_code);
        $this->assertSame(1, $delivery->attempts);

        // The fixture echoes its signature verdict; the delivery row captured it.
        // (The fixture's cache receipt lives in the FPM process cache, so the
        // recorded response body is the cross-process proof here.)
        $this->assertStringContainsString('"signature_valid":true', (string) $delivery->response);
    }

    public function test_retry_then_exhaustion_on_unreachable_target(): void
    {
        $webhook = $this->makeWebhook('cp20x'.Str::random(9), [
            // Closed port on the proxy host: connection refused, retryable.
            'url' => 'http://caddy:81/cp-webhook-fixture/closed',
            'max_attempts' => 2,
        ]);
        $delivery = WebhookService::dispatch($webhook, 'custom.ping', []);
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->next_retry_at);

        // Force the retry sweep to pick it up now.
        $delivery->forceFill(['next_retry_at' => now()->subMinute()])->save();
        WebhookService::processRetries();
        $delivery->refresh();
        $this->assertSame('exhausted', $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    public function test_ssrf_guard_refuses_dangerous_targets(): void
    {
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:8000/hook',
            'http://10.1.2.3/hook',
            'http://192.168.1.9/hook',
            'ftp://caddy/hook',
        ] as $url) {
            try {
                WebhookService::validateUrl($this->project, $url);
                $this->fail("Expected refusal for {$url}");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertContains($e->getStatusCode(), [403, 422], $url);
            }
        }
        // Allowlisted platform hosts pass validation.
        $this->assertSame(
            'http://caddy:80/cp-webhook-fixture/abc123DEF',
            WebhookService::validateUrl($this->project, 'http://caddy:80/cp-webhook-fixture/abc123DEF')
        );
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/webhooks')
            ->assertOk();
    }
}
