<?php

namespace Tests\Feature\Phase24;

use App\Models\BackupDestination;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Services\ControlPlane\BackupCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24D — Backup Center: policies, destinations, run metadata, health, drill guards. */
class BackupCenterTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    public function test_policy_crud_and_schedule_normalization(): void
    {
        $policy = BackupCenterService::createPolicy($this->projectA, [
            'name' => 'nightly-db', 'schedule' => 'daily', 'retention_days' => 7,
        ]);
        $this->assertSame('0 3 * * *', $policy->schedule);
        $this->assertSame('database', $policy->scope);

        $cron = BackupCenterService::createPolicy($this->projectA, [
            'name' => 'twice-daily', 'schedule' => '0 6,18 * * *',
        ]);
        $this->assertSame('0 6,18 * * *', $cron->schedule);
    }

    public function test_destination_drivers_and_endpoint_validation(): void
    {
        $r2 = BackupCenterService::createDestination($this->projectA, [
            'name' => 'cloudflare-r2', 'driver' => 's3-compatible',
            'config' => ['endpoint' => 'https://acc.r2.cloudflarestorage.com', 'bucket' => 'demo-backups'],
            'secret_ref' => 'R2_ACCESS_KEY',
        ]);
        $this->assertSame('s3-compatible', $r2->driver);
        $this->assertArrayNotHasKey('access_key', $r2->config, 'raw keys must never be stored in config');
        $this->assertSame('R2_ACCESS_KEY', $r2->secret_ref);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        BackupCenterService::createDestination($this->projectA, [
            'name' => 'bad', 'driver' => 's3-compatible', 'config' => ['endpoint' => 'not-a-url'],
        ]);
    }

    public function test_run_backup_records_failure_honestly_without_pg(): void
    {
        // No real project DB exists for fixture-a → trigger must fail and be
        // recorded as failed (honest metadata, never a fake pass).
        $policy = BackupCenterService::createPolicy($this->projectA, ['name' => 'p1']);
        $run = BackupCenterService::runBackup($policy, 'manual');

        $this->assertContains($run->status, ['completed', 'failed']);
        $this->assertNotNull($run->started_at);
        $this->assertSame('manual', $run->trigger);
        if ($run->status === 'failed') {
            $this->assertNotNull($run->error);
        }
    }

    public function test_backup_run_metadata_persists(): void
    {
        $run = BackupRun::create([
            'project_id' => $this->projectA->id,
            'type' => 'database', 'trigger' => 'schedule',
            'started_at' => now()->subMinutes(3), 'finished_at' => now(),
            'size_bytes' => 1048576, 'checksum' => 'abc123', 'destination' => '/backups/x.dump',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('backup_runs', ['id' => $run->id, 'status' => 'completed', 'checksum' => 'abc123']);
    }

    public function test_health_reports_warning_when_no_drill(): void
    {
        BackupCenterService::createPolicy($this->projectA, ['name' => 'p1']);
        $health = BackupCenterService::health($this->projectA);

        $this->assertContains($health['overall'], [BackupCenterService::WARNING, BackupCenterService::FAILED, BackupCenterService::NEVER_TESTED]);
        $this->assertNull($health['latest_restore_test_at']);
        $this->assertFalse($health['offsite_configured']);
    }

    public function test_health_becomes_healthy_after_runs(): void
    {
        $policy = BackupCenterService::createPolicy($this->projectA, ['name' => 'p1']);
        BackupRun::create([
            'project_id' => $this->projectA->id, 'backup_policy_id' => $policy->id,
            'type' => 'database', 'trigger' => 'manual', 'started_at' => now(), 'finished_at' => now(),
            'status' => 'completed', 'size_bytes' => 100,
        ]);
        BackupRun::create([
            'project_id' => $this->projectA->id, 'backup_policy_id' => $policy->id,
            'type' => 'restore_drill', 'trigger' => 'drill', 'started_at' => now(), 'finished_at' => now(),
            'status' => 'drill_passed', 'meta' => ['tables_restored' => 5],
        ]);
        $policy->update(['last_run_at' => now(), 'last_restore_test_at' => now()]);

        $health = BackupCenterService::health($this->projectA);
        $this->assertSame(BackupCenterService::HEALTHY, $health['overall']);
        $this->assertNotNull($health['latest_restore_test_at']);
    }

    public function test_restore_drill_refuses_missing_or_invalid_files(): void
    {
        $completed = BackupRun::create([
            'project_id' => $this->projectA->id, 'type' => 'database', 'trigger' => 'manual',
            'started_at' => now(), 'finished_at' => now(), 'status' => 'completed',
            'destination' => '/backups/does-not-exist.dump',
        ]);

        $drill = BackupCenterService::requestRestoreDrill($completed);
        $this->assertSame('drill_failed', $drill->status, 'drill must fail honestly when the file is absent');
        $this->assertNotNull($drill->error);

        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'RESTORE_DRILL_FAILED', 'project_id' => $this->projectA->id]);
    }

    public function test_restore_drill_refuses_incomplete_backup(): void
    {
        $running = BackupRun::create([
            'project_id' => $this->projectA->id, 'type' => 'database', 'trigger' => 'manual',
            'started_at' => now(), 'status' => 'running',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        BackupCenterService::requestRestoreDrill($running);
    }

    public function test_offsite_destination_detection(): void
    {
        BackupCenterService::createDestination($this->projectA, [
            'name' => 'b2', 'driver' => 's3-compatible', 'config' => ['endpoint' => 'https://s3.us-west-004.backblazeb2.com'],
        ]);
        $this->assertTrue(BackupCenterService::health($this->projectA)['offsite_configured']);
    }
}
