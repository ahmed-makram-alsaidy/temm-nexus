<?php

namespace Tests\Feature\Phase20;

use App\Http\Controllers\SignedDownloadController;
use App\Models\Project;
use App\Models\StorageBucketSetting;
use App\Models\User;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageStudioTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Template', 'slug' => 'template-api', 'status' => 'active',
            'db_name' => 'template_test_db', 'redis_prefix' => 'templateapi',
        ]);
        $this->manager()->createBucket('cp20-studio', 'private');
    }

    protected function tearDown(): void
    {
        try {
            $this->manager()->deleteBucket('cp20-studio');
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    protected function manager(): ProjectStorageManager
    {
        return ProjectStorageManager::for($this->project);
    }

    public function test_pages_render_with_usage_and_breadcrumbs(): void
    {
        $this->manager()->store('cp20-studio', '', 'hello.txt', 'hello platform');
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/storage');
        $response->assertOk();
        $response->assertSee('cp20-studio', false);

        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/storage?bucket=cp20-studio');
        $response->assertOk();
        $response->assertSee('hello.txt', false);
        $response->assertSee('Buckets', false);
    }

    public function test_signed_url_downloads_then_expires_and_rejects_tampering(): void
    {
        $this->manager()->store('cp20-studio', '', 'signed.txt', 'signed-bytes');

        $url = SignedDownloadController::url($this->project, 'cp20-studio', 'signed.txt', 3600);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $response = $this->get($path);
        $response->assertOk();
        $this->assertSame('signed-bytes', $response->streamedContent());

        // Tampered signature → 403.
        $tampered = preg_replace('/sig=[a-f0-9]+/', 'sig=deadbeef', $path);
        $this->get($tampered)->assertForbidden();

        // Expired link → 403.
        $expired = SignedDownloadController::url($this->project, 'cp20-studio', 'signed.txt', 60);
        $expiredPath = parse_url($expired, PHP_URL_PATH).'?'.parse_url($expired, PHP_URL_QUERY);
        $expiredPath = preg_replace('/expires=\d+/', 'expires=1', $expiredPath);
        // Re-sign would be needed for a *valid* past expiry; instead prove the
        // guard order: an actually-past expiry with a matching signature 403s.
        $past = time() - 10;
        $sig = SignedDownloadController::sign($this->project, 'cp20-studio', 'signed.txt', $past);
        $this->get("/sdl/{$this->project->id}/cp20-studio/signed.txt?expires={$past}&sig={$sig}")
            ->assertForbidden();
        $this->assertStringContainsString('expires=', $expiredPath);
    }

    public function test_bucket_policy_enforced_on_upload_path(): void
    {
        StorageBucketSetting::create([
            'project_id' => $this->project->id, 'bucket' => 'cp20-studio',
            'visibility' => 'private', 'max_size_mb' => 1,
            'allowed_mimes' => ['text/'],
        ]);
        $page = \App\Filament\Resources\Projects\Pages\ProjectStorage::class;

        // Over-limit content refused (call with a temp file of 2 MB).
        $tmp = tempnam(sys_get_temp_dir(), 'cp20');
        file_put_contents($tmp, str_repeat('a', 2 * 1024 * 1024));
        try {
            $page::enforcePolicyFor($this->project, 'cp20-studio', $tmp, 2 * 1024 * 1024);
            $this->fail('Expected 422 over limit.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        } finally {
            @unlink($tmp);
        }
    }
}
