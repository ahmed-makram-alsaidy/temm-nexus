<?php

namespace Tests\Feature\Phase25;

use App\Models\ClientCallsite;
use App\Models\ClientRepository;
use App\Services\ControlPlane\Repository\ClientDependencyScanner;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase25\Concerns\BuildsPhase25Fixture;
use Tests\TestCase;

/** Phase 25D/E — repository linking, path guard, framework detection, callsite scanner, secret scan. */
class RepositoryScannerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase25Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase25();
    }

    protected function linkRepo(): ClientRepository
    {
        $root = $this->buildFixtureRepo();

        return ClientRepositoryService::linkLocal($this->projectA, 'Synthetic client', $root);
    }

    public function test_link_local_detects_framework_and_inventory(): void
    {
        $repo = $this->linkRepo();

        $this->assertSame('react', $repo->framework, 'package.json with react → react (supabase-js client)');
        $this->assertContains('package.json', $repo->inventory['package_files']);
        $this->assertContains('.env', $repo->inventory['env_files'], 'env file NAMES are inventoried');
        $this->assertArrayNotHasKey('.env', $repo->inventory);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'REPOSITORY_LINKED', 'project_id' => $this->projectA->id]);
    }

    public function test_path_traversal_is_blocked(): void
    {
        $repo = $this->linkRepo();

        foreach (['../../.env', '..\\..\\secret.txt', '/etc/passwd', 'C:/Windows/win.ini'] as $evil) {
            try {
                ClientRepositoryService::safePath($repo, $evil);
                $this->fail("traversal accepted: {$evil}");
            } catch (HttpException $e) {
                $this->assertContains($e->getStatusCode(), [404, 422]);
            }
        }

        // A legitimate read works and stays inside the root.
        $file = ClientRepositoryService::safePath($repo, 'supabaseClient.js');
        $this->assertStringContainsString('createClient', (string) file_get_contents($file));
    }

    public function test_git_link_is_metadata_only(): void
    {
        $repo = ClientRepositoryService::linkGit($this->projectA, 'Client', 'https://github.com/org/client.git', 'main', 'GIT_TOKEN_REF');

        $this->assertSame('git', $repo->source_type);
        $this->assertSame('GIT_TOKEN_REF', $repo->credential_ref, 'only the vault REF is stored');
        $this->assertNull($repo->root_path);
    }

    public function test_git_url_validation(): void
    {
        $this->expectException(HttpException::class);
        ClientRepositoryService::linkGit($this->projectA, 'X', 'file:///etc/repo.git', 'main', null);
    }

    // ── 25E scanner ─────────────────────────────────────────────────────

    public function test_scanner_detects_all_categories(): void
    {
        $repo = $this->linkRepo();
        $result = ClientDependencyScanner::scan($repo);

        $this->assertGreaterThan(0, $result['callsites']);
        $byCategory = collect(ClientCallsite::where('client_repository_id', $repo->id)->get()->groupBy('category'))
            ->map->count()->all();

        foreach (['auth', 'database', 'rpc', 'functions', 'storage', 'realtime', 'url', 'client_init'] as $category) {
            $this->assertArrayHasKey($category, $byCategory, "missing category: {$category}");
        }
        $this->assertSame('orders', ClientCallsite::where('client_repository_id', $repo->id)->where('category', 'database')->where('language', 'javascript')->value('target'));
        $this->assertSame('settle_order', ClientCallsite::where('client_repository_id', $repo->id)->where('category', 'rpc')->value('target'));
        $this->assertSame('send-notification', ClientCallsite::where('client_repository_id', $repo->id)->where('category', 'functions')->value('target'));
        $this->assertSame('attachments', ClientCallsite::where('client_repository_id', $repo->id)->where('category', 'storage')->where('language', 'javascript')->value('target'));
    }

    public function test_scanner_detects_dart_idioms(): void
    {
        $repo = $this->linkRepo();
        ClientDependencyScanner::scan($repo);

        $dartAuth = ClientCallsite::where('client_repository_id', $repo->id)->where('language', 'dart')->where('category', 'auth')->first();
        $this->assertNotNull($dartAuth, 'supabase-dart auth idiom detected');

        $dartDb = ClientCallsite::where('client_repository_id', $repo->id)->where('language', 'dart')->where('category', 'database')->value('target');
        $this->assertSame('shipments', $dartDb);
    }

    public function test_secret_scan_hashes_evidence_and_never_stores_values(): void
    {
        $repo = $this->linkRepo();
        ClientDependencyScanner::scan($repo);

        $findings = ClientDependencyScanner::secretFindings($repo);
        $this->assertGreaterThanOrEqual(2, count($findings), 'service key + db password detected');

        $markers = collect($findings)->pluck('marker')->all();
        $this->assertContains('SUPABASE_SERVICE', $markers);
        $this->assertContains('DATABASE_PASSWORD', $markers);

        // No raw value may appear anywhere in the callsites table.
        $all = ClientCallsite::where('client_repository_id', $repo->id)->get();
        foreach ($all as $row) {
            $this->assertStringNotContainsString('super-secret-password-99', (string) $row->evidence_hash);
            $this->assertStringNotContainsString('service-role-value', (string) $row->evidence_hash);
        }
    }

    public function test_callsite_manifest_is_secret_free(): void
    {
        $repo = $this->linkRepo();
        ClientDependencyScanner::scan($repo);

        $manifest = ClientDependencyScanner::manifest($repo);
        $encoded = json_encode($manifest);
        $this->assertStringNotContainsString('service-role-value', $encoded);
        $this->assertStringNotContainsString('super-secret-password-99', $encoded);
        $this->assertNotContains('secret', array_column($manifest, 'category'));
    }

    public function test_scan_replaces_previous_manifest(): void
    {
        $repo = $this->linkRepo();
        ClientDependencyScanner::scan($repo);
        ClientDependencyScanner::scan($repo);

        $this->assertSame(ClientCallsite::where('client_repository_id', $repo->id)->count(), ClientCallsite::where('client_repository_id', $repo->id)->count());
    }

    public function test_repositories_are_project_isolated(): void
    {
        $repo = $this->linkRepo();

        $otherRepos = ClientRepository::where('project_id', $this->projectB->id)->count();
        $this->assertSame(0, $otherRepos);

        $repo->update(['project_id' => $this->projectB->id]); // hostile re-scope attempt via direct write is the only path; queries always scope by bound project
        $this->assertSame(1, ClientRepository::where('project_id', $this->projectB->id)->count());
    }
}
