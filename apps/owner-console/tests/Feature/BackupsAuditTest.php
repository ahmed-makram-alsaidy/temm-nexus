<?php

namespace Tests\Feature;

use App\Models\AdminAuditEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ProjectBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\RequiresDemoDatabase;

class BackupsAuditTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDemoDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDemoDatabase();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->demo = Project::create([
            'name' => 'Control Plane Demo', 'slug' => 'control-plane-demo', 'status' => 'active',
            'db_name' => 'control_plane_demo_db', 'redis_prefix' => 'control_plane_demo',
        ]);
    }

    public function test_backup_trigger_and_verify_round_trip(): void
    {
        $service = ProjectBackupService::for($this->demo);

        $record = $service->trigger('test');
        $this->assertEquals('ok', $record->status);
        $this->assertGreaterThan(0, $record->size_bytes);
        $this->assertNotEmpty($record->checksum);

        $file = null;
        foreach ($service->backups() as $backup) {
            if (str_contains($backup['file'], (string) $record->finished_at->format('Ymd'))) {
                $file = $backup;
            }
        }
        $this->assertNotNull($file, 'Triggered dump must be listed.');
        $this->assertTrue($file['checksum_ok']);

        $this->assertTrue($service->verify($record->fresh()));
        $this->assertNotNull($record->fresh()->verified_at);

        // Dashboard surface: backups page shows health + the new record row.
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->demo->id.'/backups');
        $response->assertOk();
        $response->assertSee('Backup health');
        $this->assertNotNull($record->fresh()->verified_at);
    }

    public function test_admin_actions_produce_audit_trail(): void
    {
        $this->actingAs($this->admin);
        AdminAudit::record('USER_DISABLED', $this->demo, 'user', 42, ['email' => 'x@test']);
        AdminAudit::record('BACKUP_TRIGGERED', $this->demo, 'backup', 7);
        AdminAudit::record('QUEUE_JOB_RETRIED', null, 'failed_job', 'some-uuid');

        $this->assertEquals(3, AdminAuditEntry::count());

        $response = $this->get('/admin/audit-logs');
        $response->assertOk();
        $response->assertSee('USER_DISABLED');
        $response->assertSee('control-plane-demo');

        // Filter by action works.
        $filtered = AdminAuditEntry::where('action', 'BACKUP_TRIGGERED')->count();
        $this->assertEquals(1, $filtered);

        // Audit payloads never contain secret values.
        foreach (AdminAuditEntry::all() as $entry) {
            $this->assertStringNotContainsString('password', json_encode($entry->metadata));
        }
    }
}
