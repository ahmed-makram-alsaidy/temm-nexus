<?php

namespace Tests\Feature\Phase261;

use App\Services\Platform\Doctor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Scheduler starvation regression (Phase 26.1D heartbeat guarantee).
 *
 * `schedule:run` executes events sequentially: a scheduled command that
 * never exits blocks the loop, and every task registered after it starves.
 * `pulse:check` in its daemon form does exactly that — the heartbeat and
 * the control-plane sweeps silently stopped running while the daemon
 * accumulated one process per minute. Guarded here:
 *  - pulse:check may only be scheduled in the bounded `--once` form;
 *  - cp-scheduler-heartbeat stays registered on the 5-minute cadence;
 *  - running the heartbeat task must write the state the doctor reads;
 *  - the doctor's scheduler check must WARN when the heartbeat is missing
 *    and PASS when it is fresh (honest signal, never suppressed).
 */
class SchedulerHeartbeatTest extends TestCase
{
    /** @var array<int, \Illuminate\Console\Scheduling\Event> */
    private array $events;

    protected function setUp(): void
    {
        parent::setUp();

        // Boots the console kernel so routes/console.php registrations load.
        Artisan::call('schedule:list');
        $this->events = app(Schedule::class)->events();
    }

    public function test_pulse_check_is_only_scheduled_in_bounded_once_form(): void
    {
        $pulseEvents = array_values(array_filter(
            $this->events,
            fn ($e) => str_contains((string) $e->command, 'pulse:check'),
        ));

        $this->assertNotEmpty($pulseEvents, 'pulse:check must be scheduled');
        foreach ($pulseEvents as $event) {
            $this->assertStringContainsString(
                '--once',
                (string) $event->command,
                'pulse:check daemon form blocks schedule:run forever — only the --once form may be scheduled'
            );
            $this->assertSame('* * * * *', $event->expression);
        }
    }

    public function test_scheduler_heartbeat_task_is_registered_exactly_once_on_five_minute_cadence(): void
    {
        $heartbeat = array_values(array_filter(
            $this->events,
            fn ($e) => str_contains($e->getSummaryForDisplay(), 'cp-scheduler-heartbeat'),
        ));

        $this->assertCount(1, $heartbeat, 'cp-scheduler-heartbeat must be registered exactly once');
        $this->assertSame('*/5 * * * *', $heartbeat[0]->expression);
    }

    public function test_heartbeat_task_execution_writes_doctor_visible_state(): void
    {
        $this->assertNull(
            Cache::get('platform.scheduler.heartbeat'),
            'test preconditions: heartbeat must not be present before the task runs'
        );

        Artisan::call('schedule:test', ['--name' => 'cp-scheduler-heartbeat']);

        $this->assertNotNull(
            Cache::get('platform.scheduler.heartbeat'),
            'running the heartbeat task must write the cache key the doctor reads'
        );
    }

    public function test_doctor_scheduler_check_reflects_heartbeat_state(): void
    {
        $scheduler = new \ReflectionMethod(Doctor::class, 'scheduler');

        $missing = $scheduler->invoke(null);
        $this->assertSame(
            'WARNING',
            $missing['status'],
            'a missing heartbeat must WARN (honest signal), never FAIL and never be suppressed'
        );

        Cache::put('platform.scheduler.heartbeat', now(), now()->addMinutes(6));
        $fresh = $scheduler->invoke(null);
        $this->assertSame('PASS', $fresh['status']);
        $this->assertStringContainsString('last tick', $fresh['detail']);
    }
}
