<?php

namespace Tests\Feature\Phase261;

use App\Models\PlatformDoctorRun;
use App\Models\User;
use App\Services\Platform\Doctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 26.1D — deployment doctor: coverage, statuses, exit codes, secret safety. */
class DoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_reports_all_expected_surfaces(): void
    {
        User::factory()->create(['is_admin' => true]);
        $report = Doctor::run();
        $keys = array_column($report['checks'], 'key');

        foreach ([
            'version', 'environment', 'app_debug', 'app_key', 'database', 'redis',
            'storage', 'directories', 'queue_worker', 'scheduler', 'reverb', 'proxy',
            'public_url', 'https', 'backup_config', 'last_backup', 'restore_drill',
            'disk', 'migrations', 'initialization', 'first_admin', 'setup_lock',
            'ai', 'mail',
        ] as $expected) {
            $this->assertContains($expected, $keys, "missing doctor check: {$expected}");
        }
    }

    public function test_doctor_statuses_are_valid_and_exit_code_consistent(): void
    {
        User::factory()->create(['is_admin' => true]);
        $report = Doctor::run();

        foreach ($report['checks'] as $c) {
            $this->assertContains($c['status'], ['PASS', 'WARNING', 'FAIL', 'NOT_CONFIGURED', 'NOT_APPLICABLE'], $c['label'].' has invalid status');
            $this->assertNotSame('', $c['detail'], $c['label'].' must carry a detail');
        }

        $s = $report['summary'];
        $expectedExit = $s['fail'] > 0 ? 2 : ($s['warning'] > 0 ? 1 : 0);
        $this->assertSame($expectedExit, $s['exit_code']);
    }

    public function test_doctor_run_is_recorded_for_history(): void
    {
        User::factory()->create(['is_admin' => true]);
        Doctor::run();

        $run = PlatformDoctorRun::latest('id')->first();
        $this->assertNotNull($run, 'doctor run must be recorded');
        $this->assertSame((string) config('platform.version'), $run->platform_version);
    }

    public function test_doctor_output_is_secret_safe(): void
    {
        User::factory()->create(['is_admin' => true]);
        \Illuminate\Support\Facades\Artisan::call('platform:doctor', ['--json' => true]);
        $json = \Illuminate\Support\Facades\Artisan::output();

        foreach ([
            'APP_KEY value' => (string) config('app.key'),
            'DB password' => (string) config('database.connections.pgsql.password'),
            'Redis password' => (string) config('database.redis.default.password'),
        ] as $label => $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $json, "{$label} leaked into doctor output");
            }
        }
    }

    public function test_doctor_command_exit_code_is_documented(): void
    {
        User::factory()->create(['is_admin' => true]);
        $exit = \Illuminate\Support\Facades\Artisan::call('platform:doctor');
        $this->assertContains($exit, [0, 1, 2]);

        $s = Doctor::run(persist: false)['summary'];
        $this->assertSame($s['exit_code'], $exit);
    }
}
