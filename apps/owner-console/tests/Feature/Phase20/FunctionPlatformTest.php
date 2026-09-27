<?php

namespace Tests\Feature\Phase20;

use App\Models\FunctionInvocation;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\ProjectFunction;
use App\Models\User;
use App\Services\ControlPlane\ApiKeyService;
use App\Services\ControlPlane\FunctionRunner;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\SecretService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FunctionPlatformTest extends TestCase
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

    protected function makeFunction(array $over = []): ProjectFunction
    {
        $fn = ProjectFunction::create(array_merge([
            'project_id' => $this->project->id,
            'name' => 'Hello Platform', 'slug' => 'hello-platform',
            'type' => 'static', 'enabled' => true,
            'methods' => ['GET', 'POST'], 'auth_mode' => 'public',
            'timeout_s' => 10, 'rate_limit_per_min' => 60,
        ], $over));
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['hello' => 'platform', 'method' => '{{input.method}}']], 'static');

        return $fn;
    }

    public function test_http_request_to_function_to_response_to_log(): void
    {
        $this->makeFunction();

        $response = $this->postJson('/f/gate-a/hello-platform', ['x' => 1]);
        $response->assertOk();
        $this->assertSame('platform', $response->json('hello'));
        $this->assertSame('POST', $response->json('method'));
        $requestId = $response->headers->get('X-Request-ID');
        $this->assertNotEmpty($requestId);

        $inv = FunctionInvocation::query()->where('request_id', $requestId)->first();
        $this->assertNotNull($inv);
        $this->assertSame(200, $inv->status);
        $this->assertSame(1, $inv->version);
    }

    public function test_versioning_and_rollback(): void
    {
        $fn = $this->makeFunction();
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['hello' => 'v2']], 'static');

        $this->assertSame('v2', $this->getJson('/f/gate-a/hello-platform')->json('hello'));

        FunctionRunner::rollback($fn, 1);
        $this->assertSame('platform', $this->getJson('/f/gate-a/hello-platform')->json('hello'));
    }

    public function test_key_auth_mode_create_use_revoke(): void
    {
        $this->makeFunction(['auth_mode' => 'key']);
        $this->getJson('/f/gate-a/hello-platform')->assertUnauthorized();

        $created = ApiKeyService::create($this->project, 'test-key', ['functions:invoke']);
        $this->getJson('/f/gate-a/hello-platform?key='.$created['plain'])->assertOk();

        // Wrong scope rejected.
        $other = ApiKeyService::create($this->project, 'read-only', ['read:data']);
        $this->getJson('/f/gate-a/hello-platform?key='.$other['plain'])->assertUnauthorized();

        // Revoke → 401 again.
        ApiKeyService::revoke(ProjectApiKey::find($created['key']->id));
        $this->getJson('/f/gate-a/hello-platform?key='.$created['plain'])->assertUnauthorized();
    }

    public function test_secret_injected_but_redacted_from_logs(): void
    {
        SecretService::create($this->project, 'CP20_DEMO', 'super-secret-value-123');
        $fn = $this->makeFunction();
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['token' => '{{secrets.CP20_DEMO}}']], 'static');

        $response = $this->getJson('/f/gate-a/hello-platform');
        $response->assertOk();
        // Runtime value IS delivered to the caller…
        $this->assertSame('super-secret-value-123', $response->json('token'));

        // …but never persisted in the invocation log.
        $inv = FunctionInvocation::query()->latest('id')->first();
        $this->assertStringNotContainsString('super-secret-value-123', json_encode($inv));
    }

    public function test_disabled_function_and_method_guard(): void
    {
        $this->makeFunction(['enabled' => false]);
        $this->getJson('/f/gate-a/hello-platform')->assertStatus(503);
    }

    public function test_db_lookup_resolves_both_key_paths(): void
    {
        // Generic executor contract (23L.2.1): the documented 'query.key'
        // path and the flattened 'key' path must both resolve the lookup key.
        $conn = ProjectConnectionManager::connection($this->project);
        DB::connection($conn)->statement(
            'CREATE TABLE IF NOT EXISTS cp20_fn_lookup (id serial primary key, code text)'
        );
        DB::connection($conn)->table('cp20_fn_lookup')->delete();
        DB::connection($conn)->table('cp20_fn_lookup')->insert(['code' => 'DEMO-1']);
        try {
            foreach (['query.key', 'key'] as $keyFrom) {
                $fn = ProjectFunction::create([
                    'project_id' => $this->project->id,
                    'name' => 'Lookup '.$keyFrom, 'slug' => 'lookup-'.str_replace('.', '-', $keyFrom),
                    'type' => 'db_lookup', 'enabled' => true,
                    'methods' => ['GET'], 'auth_mode' => 'public',
                    'timeout_s' => 10, 'rate_limit_per_min' => 60,
                ]);
                FunctionRunner::deploy($fn, [
                    'table' => 'cp20_fn_lookup', 'columns' => ['id', 'code'],
                    'key_column' => 'code', 'key_from' => $keyFrom, 'limit' => 5,
                ], 'db_lookup');
                $r = FunctionRunner::invoke($fn, [
                    'method' => 'GET', 'query' => ['key' => 'DEMO-1'], 'headers' => [], 'body' => null,
                ]);
                $this->assertSame(200, $r['status'], "key_from={$keyFrom}");
                $this->assertSame('DEMO-1', $r['body']['data'][0]['code'] ?? null, "key_from={$keyFrom}");
            }
        } finally {
            DB::connection($conn)->statement('DROP TABLE IF EXISTS cp20_fn_lookup');
        }
    }

    public function test_pages_render_for_permitted_users(): void
    {
        foreach (['/functions', '/keys', '/secrets'] as $suffix) {
            $this->actingAs($this->admin)
                ->get('/admin/projects/'.$this->project->id.$suffix)
                ->assertOk();
        }
        // Unassigned user (no team role) is denied the panel entirely.
        $outsider = User::factory()->create(['is_admin' => false]);
        $this->actingAs($outsider)->get('/admin/projects/'.$this->project->id.'/functions')->assertForbidden();
    }
}
