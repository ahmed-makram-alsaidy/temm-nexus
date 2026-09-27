<?php

namespace Tests\Feature\Phase25;

use App\Models\ExternalAccountConnection;
use App\Models\MigrationSource;
use App\Connectors\Supabase\SourceCapabilityService;
use App\Connectors\Supabase\SourceWriteGuard;
use App\Connectors\Supabase\SupabaseAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase25\Concerns\BuildsPhase25Fixture;
use Tests\TestCase;

/** Phase 25A/B/C — account connector, PAT safety, import, capability matrix, read-only guard. */
class SupabaseConnectorTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase25Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase25();
    }

    protected function connectAccount(): ExternalAccountConnection
    {
        return SupabaseAccountService::connect($this->admin, 'Owner Workspace', 'sbp_LIVE_TOKEN_VALUE_1234567890');
    }

    // ── 25A.2 PAT storage ───────────────────────────────────────────────

    public function test_pat_is_encrypted_at_rest(): void
    {
        $connection = $this->connectAccount();

        $raw = DB::table('external_account_connections')->where('id', $connection->id)->value('secret_encrypted');
        $this->assertStringNotContainsString('LIVE_TOKEN', $raw, 'PAT must not be stored in plaintext');
        $this->assertSame('sbp_LIVE_TOKEN_VALUE_1234567890', $connection->fresh()->secret_encrypted);
    }

    public function test_pat_is_never_listed_or_audited(): void
    {
        $connection = $this->connectAccount();
        $listing = $connection->fresh()->toArray();
        unset($listing['secret_encrypted']); // listings must exclude the column entirely
        $this->assertArrayNotHasKey('secret_encrypted', $listing);

        $auditJson = \App\Models\AdminAuditEntry::where('action', 'SUPABASE_ACCOUNT_CONNECTED')->latest('id')->value('metadata');
        $this->assertStringNotContainsString('LIVE_TOKEN', json_encode($auditJson));
    }

    // ── 25A.3 connection test ───────────────────────────────────────────

    public function test_connection_test_pass(): void
    {
        $connection = $this->connectAccount();
        Http::fake(['api.supabase.com/v1/projects' => Http::response([['id' => 'ref1', 'name' => 'Proj One']], 200)]);

        $result = SupabaseAccountService::testConnection($connection);
        $this->assertSame('PASS', $result['result']);
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_connection_test_invalid_token(): void
    {
        $connection = $this->connectAccount();
        Http::fake(['api.supabase.com/v1/projects' => Http::response([], 401)]);

        $this->assertSame('INVALID_TOKEN', SupabaseAccountService::testConnection($connection)['result']);
    }

    public function test_connection_test_network_error(): void
    {
        $connection = $this->connectAccount();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('connection refused'));

        $this->assertSame('NETWORK_ERROR', SupabaseAccountService::testConnection($connection)['result']);
    }

    // ── 25A.4/25A.5 discovery + selection ───────────────────────────────

    public function test_project_discovery_and_selection(): void
    {
        $connection = $this->connectAccount();
        Http::fake(['api.supabase.com/v1/projects' => Http::response([
            ['id' => 'refdemo01', 'name' => 'Demo Co', 'organization_id' => 'org-1', 'region' => 'eu-central-1', 'status' => 'ACTIVE_HEALTHY'],
            ['id' => 'refproject2', 'name' => 'Project Two', 'region' => null, 'status' => null],
        ], 200)]);

        $projects = SupabaseAccountService::discoverProjects($connection);
        $this->assertCount(2, $projects);
        $this->assertSame('Demo Co', $projects[0]['name']);
        $this->assertNull($projects[1]['region'], 'unavailable metadata must stay null, never faked');

        $source = SupabaseAccountService::selectProject($this->projectA, $connection, $projects[0]);
        $this->assertTrue($source->read_only);
        $this->assertSame('refdemo01', $source->management_project_ref);
        $this->assertSame('eu-central-1', $source->region);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'SUPABASE_PROJECT_SELECTED', 'project_id' => $this->projectA->id]);
    }

    // ── 25C capability matrix + probe ───────────────────────────────────

    public function test_capability_matrix_states(): void
    {
        $connection = $this->connectAccount();
        Http::fake(['api.supabase.com/v1/projects' => Http::response([['id' => 'r1', 'name' => 'X']], 200)]);
        SupabaseAccountService::testConnection($connection); // → connected
        $source = SupabaseAccountService::selectProject($this->projectA, $connection, ['ref' => 'r1', 'name' => 'X']);

        $matrix = SourceCapabilityService::matrix($source);
        $byKey = collect($matrix)->pluck('status', 'key')->all();
        $this->assertSame('CONNECTED', $byKey['account_discovery']);
        $this->assertSame('NEEDS_CREDENTIAL', $byKey['database'], 'DB credential is never pretended');
        $this->assertSame('NOT_LINKED', $byKey['source_code']);

        // DB stays NEEDS_CREDENTIAL until BOTH a credential ref AND connection
        // coordinates exist (host unknown for a management-only discovery).
        $source->update(['secret_refs' => ['password' => 'SOURCE_DB_PASSWORD']]);
        $byKey2 = collect(SourceCapabilityService::matrix($source->fresh()))->pluck('status', 'key')->all();
        $this->assertSame('NEEDS_CREDENTIAL', $byKey2['database'], 'password ref alone is not enough without coordinates');

        $source->update(['connection' => array_merge($source->connection ?? [], ['host' => 'db.host.test', 'port' => 5432, 'database' => 'postgres'])]);
        $byKey3 = collect(SourceCapabilityService::matrix($source->fresh()))->pluck('status', 'key')->all();
        $this->assertSame('CONNECTED', $byKey3['database']);
        $this->assertSame('VIA_DATABASE', $byKey3['auth']);
    }

    public function test_capability_probe_on_reachable_source(): void
    {
        // Probe against the local demo_source_rehearsal snapshot when reachable.
        $host = env('CP_PG_HOST', 'postgres');
        try {
            $pdo = new \PDO("pgsql:host={$host};port=5432;dbname=demo_source_rehearsal", 'postgres', (string) env('POSTGRES_PASSWORD', ''), [\PDO::ATTR_TIMEOUT => 3]);
            $pdo->exec('SET default_transaction_read_only = on');
        } catch (\Throwable) {
            $this->markTestSkipped('demo_source_rehearsal not reachable');
        }

        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'supabase', 'display_name' => 'probe test',
            'connection' => ['host' => $host, 'port' => 5432, 'database' => 'demo_source_rehearsal', 'username' => 'postgres'],
            'secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'],
            'read_only' => true, 'status' => 'pending',
        ]);
        \App\Models\ProjectSecret::create(['project_id' => $this->projectA->id, 'name' => 'SOURCE_DB_PASSWORD', 'value' => (string) env('POSTGRES_PASSWORD', ''), 'category' => 'database']);

        $probe = SourceCapabilityService::probe($source);
        $this->assertSame('PASS', $probe['domains']['database']);
        $this->assertSame('PASS', $probe['domains']['functions']);
        $this->assertSame('PROBED', $probe['overall']);
    }

    // ── 25C.2 application-level read-only guard ─────────────────────────

    public function test_source_write_guard_blocks_writes(): void
    {
        foreach ([
            'INSERT INTO users VALUES (1)',
            "UPDATE accounts SET balance = 0",
            'DELETE FROM logs',
            'MERGE INTO t USING s ON 1=1',
            'CREATE TABLE evil (id int)',
            'ALTER TABLE users DROP COLUMN email',
            'DROP TABLE users',
            'TRUNCATE TABLE users',
            'GRANT ALL ON users TO evil',
            'REVOKE SELECT ON users FROM public',
        ] as $sql) {
            try {
                SourceWriteGuard::assertReadOnly($sql);
                $this->fail("guard accepted: {$sql}");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        // SELECT with keywords inside strings/comments must pass.
        SourceWriteGuard::assertReadOnly("SELECT * FROM notes WHERE body = 'please DELETE me' -- UPDATE users");
        SourceWriteGuard::assertReadOnly('SELECT * FROM /* INSERT trick */ audit_log');
    }

    // ── 25C.3 source/target guard strengthened across all sources ──────

    public function test_target_matching_any_project_source_is_refused(): void
    {
        $analysis = $this->makeAnalysisWithSource(['host' => 'pg-internal', 'port' => 5432, 'database' => 'src_db']);
        $plan = (new \App\Services\ControlPlane\Migration\MigrationCenterService)->generatePlan($analysis);

        // A DIFFERENT read-only source of the same project uses the same DSN.
        MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'supabase', 'display_name' => 'Second source',
            'connection' => ['host' => 'other-host', 'port' => 5432, 'database' => 'src_db'],
            'read_only' => true, 'status' => 'pending',
        ]);

        try {
            (new \App\Services\ControlPlane\Migration\MigrationRunManager)->start($plan, [
                'mode' => 'rehearsal',
                'target' => ['host' => 'other-host', 'port' => 5432, 'database' => 'src_db'],
                'target_disposable' => true, 'reset' => true,
            ]);
            $this->fail('target matching a project source must be refused');
        } catch (HttpException $e) {
            $this->assertStringContainsString('must never be a source', $e->getMessage());
        }
    }

    protected function makeAnalysisWithSource(array $connection): \App\Models\MigrationAnalysis
    {
        $path = storage_path('framework/testing/phase24/engine-source-'.uniqid().'.sqlite');
        @mkdir(dirname($path), 0775, true);
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE company (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO company (name) VALUES ('a')");

        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'sqlite', 'display_name' => 'fixture',
            'connection' => $connection + ['path' => $path],
            'read_only' => true, 'status' => 'pending',
        ]);
        $service = new \App\Services\ControlPlane\Migration\MigrationCenterService;
        $analysis = $service->analyze($source);
        $service->classify($analysis);

        return $analysis;
    }
}
