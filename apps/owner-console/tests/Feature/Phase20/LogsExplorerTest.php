<?php

namespace Tests\Feature\Phase20;

use App\Models\AdminAuditEntry;
use App\Models\FunctionInvocation;
use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\TaskRun;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\ControlPlane\FunctionRunner;
use App\Services\ControlPlane\LogExplorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogsExplorerTest extends TestCase
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

    public function test_correlation_across_sources_by_request_id(): void
    {
        $requestId = 'cp20-corr-0001';
        $fn = ProjectFunction::create([
            'project_id' => $this->project->id, 'name' => 'Ping', 'slug' => 'ping',
            'type' => 'static', 'enabled' => true, 'methods' => ['GET'],
            'auth_mode' => 'public', 'timeout_s' => 10, 'rate_limit_per_min' => 60,
        ]);
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['pong' => true]], 'static');
        FunctionRunner::invoke($fn, ['method' => 'GET', 'query' => [], 'headers' => [], 'body' => []], 'owner:test', $requestId);

        AdminAuditEntry::create([
            'owner_user_id' => $this->admin->id, 'project_id' => $this->project->id,
            'project_slug' => 'gate-a', 'action' => 'FUNCTION_INVOKED',
            'target_type' => 'function', 'target_id' => (string) $fn->id, 'ip' => '127.0.0.1',
            'metadata' => ['request_id' => $requestId],
        ]);
        TaskRun::create([
            'task_id' => \App\Models\ProjectTask::create([
                'project_id' => $this->project->id, 'name' => 'corr-task',
                'cron' => '* * * * *', 'target_type' => 'function', 'target_ref' => 'ping',
                'enabled' => true,
            ])->id,
            'request_id' => $requestId, 'status' => 'ok',
            'duration_ms' => 5, 'output' => 'correlated',
        ]);

        $result = LogExplorerService::query($this->project, [
            'source' => null, 'severity' => null, 'q' => null,
            'request_id' => $requestId, 'limit' => 100,
        ]);
        $sources = array_column($result['rows'], 'source');
        $this->assertContains('functions', $sources);
        $this->assertContains('audit', $sources);
        $this->assertContains('scheduler', $sources);
        foreach ($result['rows'] as $row) {
            $this->assertSame($requestId, $row['request_id']);
        }
    }

    public function test_severity_and_text_filters(): void
    {
        $result = LogExplorerService::query($this->project, [
            'source' => 'sql', 'severity' => null, 'q' => null, 'request_id' => null, 'limit' => 100,
        ]);
        $this->assertSame([], $result['rows']);

        $result = LogExplorerService::query($this->project, [
            'source' => null, 'severity' => null, 'q' => 'no-such-summary-cp20', 'request_id' => null, 'limit' => 100,
        ]);
        $this->assertSame([], $result['rows']);
    }

    public function test_page_renders_with_filters_and_detail(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/logs');
        $response->assertOk();
        $response->assertSee('Logs Explorer', false);

        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/logs?source=sql&severity=error');
        $response->assertOk();
    }
}
