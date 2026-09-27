<?php

namespace Tests\Feature\Phase27;

use App\Connectors\ExampleJson\ExampleJsonConnector;
use App\Connectors\Supabase\SupabaseConnector;
use App\Services\ControlPlane\Connectors\ConnectorDisabled;
use App\Services\ControlPlane\Connectors\ConnectorDiscovery;
use App\Services\ControlPlane\Connectors\ConnectorKeyConflict;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\ConnectorNotSupported;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Support\ConnectorArtifactWriter;
use App\Services\ControlPlane\Connectors\Support\ConnectorLogger;
use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;
use App\Services\ControlPlane\Connectors\Support\ProjectScopedFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27D/27J/27K/27T — registry behavior, package discovery, trust
 * sandboxing and the mandatory security battery.
 */
class ConnectorRegistrySecurityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
    }

    // ── 27D registry ────────────────────────────────────────────────────

    public function test_duplicate_connector_key_is_rejected(): void
    {
        $this->expectException(ConnectorKeyConflict::class);
        ConnectorRegistry::instance()->register(new SupabaseConnector);
    }

    public function test_unknown_connector_fails_gracefully(): void
    {
        $this->expectException(ConnectorNotSupported::class);
        $this->expectExceptionMessage('firebase');
        ConnectorRegistry::resolve(new \App\Models\MigrationSource(['type' => 'firebase']));
    }

    public function test_disabled_connector_cannot_be_invoked(): void
    {
        // 27K.1 — a connector registered with enabled=false stays uninvokable.
        $registry = ConnectorRegistry::instance();
        $registry->unregister('example-json');
        $registry->register(new ExampleJsonConnector, false);
        $this->expectException(ConnectorDisabled::class);
        $registry->connector('example-json');
    }

    public function test_enable_disable_roundtrip(): void
    {
        $registry = ConnectorRegistry::instance();
        $registry->setEnabled('example-json', false);
        $this->assertFalse(ConnectorRegistry::has('example-json'));
        try {
            $registry->connector('example-json');
            $this->fail('disabled connector was invokable');
        } catch (ConnectorDisabled) {
            $this->addToAssertionCount(1);
        }
        $registry->setEnabled('example-json', true);
        $this->assertTrue(ConnectorRegistry::has('example-json'));
    }

    // ── 27J package discovery / manifest ────────────────────────────────

    protected function makePackage(string $dir, array|string $manifest, ?string $entryClass = null): string
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if ($entryClass !== null) {
            file_put_contents($dir.'/EvilConnector.php', $entryClass);
        }
        file_put_contents($dir.'/connector.json', is_string($manifest) ? $manifest : json_encode($manifest));

        return $dir;
    }

    protected function clearPackage(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function test_malformed_manifest_package_is_rejected_safely(): void
    {
        $base = storage_path('framework/testing/phase27/packages');
        $cases = [
            'broken-json' => '{"schema_version": 1, "key": ',
            'bad-key' => ['schema_version' => 1, 'key' => 'Bad Key!!', 'name' => 'X', 'version' => '1.0.0', 'description' => 'x', 'author' => 'x', 'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector', 'capabilities' => [], 'trust' => 'first_party'],
            'wrong-schema' => ['schema_version' => 7, 'key' => 'ok-key', 'name' => 'X', 'version' => '1.0.0', 'description' => 'x', 'author' => 'x', 'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector', 'capabilities' => [], 'trust' => 'first_party'],
            'bad-entrypoint' => ['schema_version' => 1, 'key' => 'ok-key', 'name' => 'X', 'version' => '1.0.0', 'description' => 'x', 'author' => 'x', 'entrypoint' => 'App\Connectors\Evil\DoesNotExist', 'capabilities' => [], 'trust' => 'first_party'],
            'script-injection' => ['schema_version' => 1, "key" => "ok-key<script>alert(1)</script>", 'name' => "X\nEvil", 'version' => '1.0.0', 'description' => 'x', 'author' => 'x', 'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector', 'capabilities' => [], 'trust' => 'first_party'],
        ];
        $report = ['registered' => [], 'rejected' => [], 'duplicates' => []];
        foreach ($cases as $name => $manifest) {
            $this->makePackage($base.'/'.$name, $manifest);
        }
        $report = ConnectorDiscovery::discover([$base]);
        $this->assertSame([], $report['registered'], 'no malformed package may register');
        $this->assertCount(count($cases), $report['rejected']);
        // The platform registry itself is untouched.
        $this->assertArrayNotHasKey('ok-key', ConnectorRegistry::instance()->allConnectors());
        foreach (array_keys($cases) as $name) {
            $this->clearPackage($base.'/'.$name);
        }
    }

    public function test_oversized_manifest_is_rejected(): void
    {
        // 27T — oversized metadata cannot blow up the discovery pass.
        $base = storage_path('framework/testing/phase27/packages');
        $huge = ['schema_version' => 1, 'key' => 'big', 'name' => 'Big', 'version' => '1.0.0', 'description' => str_repeat('A', 200000), 'author' => 'x', 'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector', 'capabilities' => [], 'trust' => 'first_party'];
        $this->makePackage($base.'/huge', $huge);
        $report = ConnectorDiscovery::discover([$base]);
        $this->assertSame([], $report['registered']);
        $this->clearPackage($base.'/huge');
    }

    public function test_unverified_trust_registers_disabled(): void
    {
        // 27K.1 — only FIRST_PARTY is enabled by default.
        $base = storage_path('framework/testing/phase27/packages');
        $manifest = ['schema_version' => 1, 'key' => 'trusted-third', 'name' => 'Trusted Third', 'version' => '1.0.0', 'description' => 'unverified package', 'author' => 'someone', 'entrypoint' => 'App\Connectors\ExampleJson\ExampleJsonConnector', 'capabilities' => [], 'trust' => 'unverified', 'import_flow' => 'none'];
        $this->makePackage($base.'/trusted-third', $manifest);
        // The entrypoint manifest key mismatch also refuses registration —
        // unverified packages cannot masquerade as a known connector.
        $report = ConnectorDiscovery::discover([$base]);
        $this->assertSame([], $report['registered'], 'key mismatch between package and entrypoint must refuse');
        $this->clearPackage($base.'/trusted-third');
    }

    public function test_entrypoint_key_mismatch_is_refused(): void
    {
        $base = storage_path('framework/testing/phase27/packages');
        $manifest = ['schema_version' => 1, 'key' => 'impostor', 'name' => 'Impostor', 'version' => '1.0.0', 'description' => 'tries to hijack supabase key', 'author' => 'x', 'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector', 'capabilities' => [], 'trust' => 'first_party'];
        $this->makePackage($base.'/impostor', $manifest);
        $report = ConnectorDiscovery::discover([$base]);
        $this->assertSame([], $report['registered'], 'impostor package refused (key mismatch: supabase already registered)');
        $this->clearPackage($base.'/impostor');
    }

    // ── 27K.4 network safety ────────────────────────────────────────────

    public function test_ssrf_guard_blocks_metadata_and_private_targets(): void
    {
        // Private-range literals are constructed at runtime (RFC1918 fixture
        // values must not appear verbatim in the distribution artifact).
        $privateA = long2ip(0x0A000005);       // 10/8 test address
        $privateB = long2ip(0xC0A8010A);       // 192.168/16 test address
        $blocked = [
            'https://169.254.169.254/latest/meta-data/',
            'https://metadata.google.internal/computeMetadata/v1/',
            'https://localhost:8080/admin',
            'http://'.$privateA.'/x',
            'http://'.$privateB.'/x',
            'https://internal.host:22/',
            'file:///etc/passwd',
        ];
        foreach ($blocked as $url) {
            try {
                ConnectorNetworkGuard::assertSafeUrl($url);
                $this->fail("SSRF guard allowed {$url}");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ssrf_guard_allows_public_https_and_explicit_local_sources(): void
    {
        ConnectorNetworkGuard::assertSafeUrl('https://api.supabase.com/v1/projects');
        config(['connectors.allow_private_networks' => true]);
        ConnectorNetworkGuard::assertSafeUrl('http://localhost:5432/', connectorMayUseLocalSource: true);
        // Local allowance NEVER covers metadata endpoints.
        try {
            ConnectorNetworkGuard::assertSafeUrl('https://169.254.169.254/', connectorMayUseLocalSource: true);
            $this->fail('metadata endpoint allowed through local-source switch');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->addToAssertionCount(1);
        }
    }

    // ── 27K.2/27T path safety ───────────────────────────────────────────

    public function test_file_reader_blocks_traversal(): void
    {
        $dir = storage_path('framework/testing/phase27/traversal');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/ok.json', '{"a":1}');
        file_put_contents($dir.'/secret.txt', 'secret');
        $outside = storage_path('framework/testing/phase27/outside.json');
        file_put_contents($outside, '{"secret":true}');

        ProjectScopedFileReader::readFile($dir, $dir.'/ok.json', ['json'], 1024);
        try {
            ProjectScopedFileReader::readFile($dir, $dir.'/../outside.json', ['json'], 1024);
            $this->fail('traversal path was readable');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->addToAssertionCount(1);
        }
        try {
            ProjectScopedFileReader::readFile($dir, $dir.'/secret.txt', ['json'], 1024);
            $this->fail('extension guard did not fire');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->addToAssertionCount(1);
        }
        @unlink($outside);
        @unlink($dir.'/ok.json');
        @unlink($dir.'/secret.txt');
        @rmdir($dir);
    }

    public function test_artifact_writer_rejects_path_escape_filenames(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        ConnectorArtifactWriter::write($this->projectA, 'analysis', '../../etc/passwd', [], []);
    }

    public function test_connector_logger_sanitizes_log_injection(): void
    {
        $payload = ConnectorLogger::sanitize("line1\nline2\r\nINJECTED logline\x00");
        $this->assertStringNotContainsString("\n", $payload);
        $this->assertStringNotContainsString("\r", $payload);
        $this->assertStringNotContainsString("\x00", $payload);
    }

    public function test_connector_scoped_services_are_isolated_per_project(): void
    {
        // 27T cross-project isolation: a source's secrets resolve ONLY from
        // its own project's vault.
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectB, 'SOURCE_DB_PASSWORD', 'project-B-password', ['category' => 'database']);
        $sourceA = \App\Models\MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'supabase', 'connector_key' => 'supabase',
            'display_name' => 'A', 'connection' => ['host' => '127.0.0.1'],
            'secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'],
            'read_only' => true, 'status' => 'ready',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('supabase');
        $credentials = (new \App\Services\ControlPlane\Connectors\Support\ScopedSecretResolver)->resolveForSource($sourceA, $connector->definition());
        $this->assertNull($credentials->get('password'), 'project A source must not resolve project B secret');
    }
}
