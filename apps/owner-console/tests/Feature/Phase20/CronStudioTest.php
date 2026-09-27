<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\ProjectTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ControlPlane\CronService;
use App\Services\ControlPlane\FunctionRunner;
use App\Services\ControlPlane\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronStudioTest extends TestCase
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

    public function test_cron_validation_and_description(): void
    {
        foreach (['* * * * *', '*/5 * * * *', '0 2 * * *', '0 9 * * 1', '0 0 1 * *', '15 14 1 * *'] as $expr) {
            $this->assertTrue(CronService::valid($expr), $expr);
        }
        foreach (['* * *', '61 * * * *', '*/0 * * * *', 'x y z w v', ''] as $expr) {
            $this->assertFalse(CronService::valid($expr), "'{$expr}'");
        }
        $this->assertSame('Every 5 minutes', CronService::describe('*/5 * * * *'));
    }

    public function test_next_runs_known_cases(): void
    {
        $from = new \DateTimeImmutable('2026-01-05 10:07:00', new \DateTimeZone('UTC')); // a Monday
        $next = CronService::nextRuns('0 2 * * *', 1, $from)[0];
        $this->assertSame('2026-01-06 02:00', $next->format('Y-m-d H:i'));

        $next = CronService::nextRuns('*/15 * * * *', 1, $from)[0];
        $this->assertSame('2026-01-05 10:15', $next->format('Y-m-d H:i'));

        $next = CronService::nextRuns('0 9 * * 1', 1, $from)[0];
        // Next Monday 09:00 (today 10:07 already passed).
        $this->assertSame('2026-01-12 09:00', $next->format('Y-m-d H:i'));
    }

    public function test_create_execute_inspect_disable_task(): void
    {
        $fn = ProjectFunction::create([
            'project_id' => $this->project->id, 'name' => 'Ping', 'slug' => 'ping',
            'type' => 'static', 'enabled' => true, 'methods' => ['POST'],
            'auth_mode' => 'internal', 'timeout_s' => 10, 'rate_limit_per_min' => 60,
        ]);
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['pong' => true]], 'static');

        $task = ProjectTask::create([
            'project_id' => $this->project->id, 'name' => 'nightly ping',
            'cron' => '* * * * *', 'target_type' => 'function', 'target_ref' => 'ping',
            'enabled' => true,
        ]);
        TaskRunner::scheduleNext($task);
        $this->assertNotNull($task->fresh()->next_run_at);

        $result = TaskRunner::run($task->fresh(), 'owner:test');
        $this->assertSame('ok', $result['status']);
        $run = TaskRun::query()->where('task_id', $task->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('ok', $run->status);
        $this->assertNotEmpty($run->request_id);

        // Disable → due sweep skips it.
        $task->forceFill(['enabled' => false])->save();
        $task->forceFill(['next_run_at' => now()->subMinute()])->save();
        $this->assertSame(0, TaskRunner::runDue());
        $this->assertSame(1, TaskRun::query()->where('task_id', $task->id)->count());
    }

    public function test_artisan_target_allowlist_enforced(): void
    {
        $task = ProjectTask::create([
            'project_id' => $this->project->id, 'name' => 'evil',
            'cron' => '* * * * *', 'target_type' => 'artisan', 'target_ref' => 'db:wipe',
            'enabled' => true,
        ]);
        $result = TaskRunner::run($task);
        $this->assertSame('error', $result['status']);
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/scheduler')
            ->assertOk();
    }
}
