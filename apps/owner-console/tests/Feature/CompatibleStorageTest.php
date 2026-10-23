<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Services\ControlPlane\CompatibleStorageManager;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CompatibleStorageTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $project = Project::create(['name' => 'Managed files', 'slug' => 'managed-files', 'status' => 'active', 'storage_disk' => 'supabase']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production',
            'type' => 'production', 'status' => 'active', 'is_default' => true, 'api_base_url' => 'https://runtime.example']);
        ProjectSecret::create(['project_id' => $project->id, 'environment_id' => $environment->id,
            'name' => 'SUPABASE_SERVICE_ROLE_KEY', 'value' => 'private-runtime-test-key', 'status' => 'active']);

        return $project;
    }

    public function test_storage_page_shows_live_buckets_nested_usage_and_files_without_local_directories(): void
    {
        $project = $this->project();
        $admin = User::factory()->create(['is_admin' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://runtime.example/storage/v1/bucket' => Http::response([['id' => 'homework', 'public' => false]]),
            'https://runtime.example/storage/v1/object/list/homework' => function (Request $request) {
                return Http::response($request['prefix'] === ''
                    ? [['id' => null, 'name' => 'students'], ['id' => 'a', 'name' => 'brief.txt', 'metadata' => ['size' => 5, 'mimetype' => 'text/plain']]]
                    : [['id' => 'b', 'name' => '<report>.pdf', 'metadata' => ['size' => 12, 'mimetype' => 'application/pdf']]]);
            },
        ]);

        $this->actingAs($admin)->get('/admin/projects/'.$project->id.'/storage')
            ->assertOk()->assertSee('homework')->assertSee('2 files')->assertSee('17 B');
        $this->get('/admin/projects/'.$project->id.'/storage?bucket=homework&prefix=students')
            ->assertOk()->assertSee('&lt;report&gt;.pdf', false)->assertDontSee('<report>.pdf', false);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer private-runtime-test-key'));
    }

    public function test_file_listing_reads_every_page(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://runtime.example/storage/v1/object/list/docs' => function (Request $request) {
            return Http::response($request['offset'] === 0
                ? array_map(fn ($i) => ['id' => (string) $i, 'name' => 'file-'.$i, 'metadata' => ['size' => 1]], range(1, 1000))
                : [['id' => '1001', 'name' => 'last.txt', 'metadata' => ['size' => 4]]]);
        }]);
        $manager = new CompatibleStorageManager(new Project, 'https://runtime.example/storage/v1', 'test-key');

        $files = $manager->files('docs');

        $this->assertCount(1001, $files);
        $this->assertSame('last.txt', $files[1000]['name']);
        Http::assertSentCount(2);
    }

    public function test_upload_read_move_delete_and_visibility_use_the_managed_api(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://runtime.example/storage/v1/object/docs/folder/hello.txt' => Http::response(['Key' => 'docs/folder/hello.txt']),
            'https://runtime.example/storage/v1/object/authenticated/docs/folder/hello.txt' => Http::response('hello', 200, ['Content-Type' => 'text/plain']),
            'https://runtime.example/storage/v1/object/move' => Http::response(['message' => 'moved']),
            'https://runtime.example/storage/v1/object/docs/archive/renamed.txt' => Http::response([]),
            'https://runtime.example/storage/v1/bucket/docs' => Http::response([]),
        ]);
        $manager = new CompatibleStorageManager(new Project, 'https://runtime.example/storage/v1', 'test-key');

        $this->assertSame('folder/hello.txt', $manager->store('docs', 'folder', 'hello.txt', 'hello'));
        $this->assertSame('hello', $manager->read('docs', 'folder/hello.txt')['contents']);
        $this->assertSame('archive/renamed.txt', $manager->move('docs', 'folder/hello.txt', 'archive', 'renamed.txt'));
        $manager->delete('docs', 'archive/renamed.txt');
        $manager->setVisibility('docs', 'private');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->body() === 'hello');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && ($request['sourceKey'] ?? null) === 'folder/hello.txt' && $request['destinationKey'] === 'archive/renamed.txt');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['public'] === false);
    }

    public function test_unconfigured_environment_never_uses_production_credentials(): void
    {
        $project = $this->project();
        $staging = $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging',
            'status' => 'active', 'api_base_url' => 'https://staging.example']);
        session()->put(EnvironmentContext::sessionKey($project), $staging->id);
        Http::preventStrayRequests();

        try {
            ProjectStorageManager::for($project);
            $this->fail('Missing environment credentials must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_traversal_is_rejected_before_a_request_is_sent(): void
    {
        Http::preventStrayRequests();
        $manager = new CompatibleStorageManager(new Project, 'https://runtime.example/storage/v1', 'test-key');

        try {
            $manager->read('docs', '../private.txt');
            $this->fail('Traversal must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_backend_errors_do_not_expose_private_responses(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://runtime.example/storage/v1/bucket' => Http::response(['message' => 'private backend diagnostic'], 500)]);
        $manager = new CompatibleStorageManager(new Project, 'https://runtime.example/storage/v1', 'test-key');

        try {
            $manager->buckets();
            $this->fail('Storage failure must be reported.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringNotContainsString('private backend diagnostic', $exception->getMessage());
        }
    }
}
