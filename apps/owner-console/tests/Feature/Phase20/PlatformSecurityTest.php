<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\ProjectSecret;
use App\Models\ProjectWebhook;
use App\Models\User;
use App\Services\ControlPlane\FunctionRunner;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\SqlRunner;
use App\Services\ControlPlane\WebhookService;
use App\Http\Controllers\SignedDownloadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 20Y security review: the residual adversarial matrix beyond the
 * per-feature suites (timeout, redirect-SSRF, secret rendering, signed-URL
 * traversal, cross-project function isolation).
 */
class PlatformSecurityTest extends TestCase
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
        \App\Services\ControlPlane\CpAccess::seedDefaults();
    }

    public function test_sql_statement_timeout_kills_runaway_query(): void
    {
        $started = microtime(true);
        $result = SqlRunner::run($this->project, 'SELECT pg_sleep(30)');
        $elapsed = microtime(true) - $started;

        $this->assertSame('timeout', $result['status'], 'expected statement timeout, got: '.json_encode($result['error']));
        $this->assertLessThan(25, $elapsed, 'timeout did not bound execution');
    }

    public function test_webhook_redirect_is_never_followed(): void
    {
        // A public fixture that 302s to the cloud metadata endpoint: with
        // redirects disabled the delivery records the 302 and stops there.
        $webhook = ProjectWebhook::create([
            'project_id' => $this->project->id, 'name' => 'redirect-probe',
            'url' => 'http://caddy:80/cp-webhook-fixture/'.'redir'.Str::random(8).'?redirect=http://169.254.169.254/latest/meta-data/',
            'events' => ['custom.ping'], 'enabled' => true,
            'signing_secret' => Str::random(48), 'max_attempts' => 1, 'timeout_s' => 5,
        ]);
        $delivery = WebhookService::dispatch($webhook, 'custom.ping', []);
        $this->assertSame(302, $delivery->http_code);
        // Delivery is "not 2xx", recorded as exhausted after max attempts=1.
        $this->assertSame('exhausted', $delivery->status);
        // Proof it was NOT followed: the captured body is the local redirect
        // stub itself (one request), not the fetched private target.
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('Redirecting', (string) $delivery->response);
    }

    public function test_secrets_page_never_renders_values(): void
    {
        ProjectSecret::create([
            'project_id' => $this->project->id, 'name' => 'CP20_UI_LEAK',
            'value' => 'very-secret-value-xyz', 'description' => 'probe',
        ]);
        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/secrets');
        $response->assertOk();
        $response->assertSee('CP20_UI_LEAK', false);      // name visible
        $response->assertDontSee('very-secret-value-xyz'); // value never rendered
    }

    public function test_signed_url_traversal_refused(): void
    {
        $url = SignedDownloadController::url($this->project, 'bucket', '../../etc/passwd', 300);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $status = $this->get($path)->status();
        // 422 = storage manager validation refusal; 403/404 also acceptable.
        $this->assertContains($status, [403, 404, 422], 'traversal via signed URL returned '.$status);
    }

    public function test_cross_project_function_invocation_impossible(): void
    {
        $other = Project::create([
            'name' => 'Probe B', 'slug' => 'gate-b', 'status' => 'active',
            'db_name' => 'gate_b_db', 'redis_prefix' => 'gateb',
        ]);
        $fn = ProjectFunction::create([
            'project_id' => $other->id, 'name' => 'B only', 'slug' => 'cp20-b-only',
            'type' => 'static', 'enabled' => true, 'methods' => ['GET'],
            'auth_mode' => 'public', 'timeout_s' => 10, 'rate_limit_per_min' => 60,
        ]);
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['ok' => true]], 'static');

        // Same slug under project A's URL: 404 (function ids are project-scoped).
        $this->getJson('/f/gate-a/cp20-b-only')->assertNotFound();
        // And through project B it works (sanity).
        $this->getJson('/f/gate-b/cp20-b-only')->assertOk();
    }

    public function test_sql_history_never_stores_raw_literals(): void
    {
        $this->actingAs($this->admin)->postJson('/cp-sql/'.$this->project->id.'/run', [
            'sql' => "SELECT 'top-secret-literal' AS v, 424242 AS n",
        ])->assertOk();

        $history = \App\Models\SqlQueryHistory::query()->where('project_id', $this->project->id)->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertStringNotContainsString('top-secret-literal', (string) $history->redacted_sql);
        $this->assertStringNotContainsString('424242', (string) $history->redacted_sql);
    }
}
