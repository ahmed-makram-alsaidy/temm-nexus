<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\ControlPlane\ControlPlanePaths;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Production regression: with CONTROL_PLANE_REPO_ROOT unset the per-project
 * root fell back to "/", which the web server cannot write to, so Storage
 * Studio answered HTTP 500 (mkdir(): Permission denied) for every project.
 */
class ControlPlaneStorageRootTest extends TestCase
{
    use RefreshDatabase;

    private ?string $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = getenv('CONTROL_PLANE_REPO_ROOT') === false ? null : (string) getenv('CONTROL_PLANE_REPO_ROOT');
    }

    protected function tearDown(): void
    {
        $this->setRoot($this->previous);
        parent::tearDown();
    }

    private function setRoot(?string $value): void
    {
        if ($value === null) {
            putenv('CONTROL_PLANE_REPO_ROOT');
            unset($_ENV['CONTROL_PLANE_REPO_ROOT'], $_SERVER['CONTROL_PLANE_REPO_ROOT']);

            return;
        }
        putenv('CONTROL_PLANE_REPO_ROOT='.$value);
        $_ENV['CONTROL_PLANE_REPO_ROOT'] = $_SERVER['CONTROL_PLANE_REPO_ROOT'] = $value;
    }

    private function localProject(): Project
    {
        return Project::create(['name' => 'Local files', 'slug' => 'local-files-'.uniqid(), 'status' => 'active', 'storage_disk' => 'local']);
    }

    public function test_without_a_configured_root_project_folders_live_in_app_storage(): void
    {
        $this->setRoot(null);
        $this->assertSame(storage_path('app/control-plane'), ControlPlanePaths::repoRoot());

        $project = $this->localProject();
        $storage = ProjectStorageManager::for($project);
        try {
            $this->assertDirectoryExists($storage->root());
            $this->assertStringStartsWith(realpath(storage_path('app')) ?: storage_path('app'), $storage->root());
            $this->assertSame([], $storage->buckets());
        } finally {
            @rmdir($storage->root());
            @rmdir(dirname($storage->root()));
            @rmdir(dirname($storage->root(), 2));
        }
    }

    public function test_an_unwritable_root_is_a_clear_503_not_a_crash(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cp-root');
        $this->setRoot($file);
        try {
            ProjectStorageManager::for($this->localProject());
            $this->fail('An unusable storage root must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('Storage is not available on this installation.', $e->getMessage());
        } finally {
            @unlink($file);
        }
    }
}
