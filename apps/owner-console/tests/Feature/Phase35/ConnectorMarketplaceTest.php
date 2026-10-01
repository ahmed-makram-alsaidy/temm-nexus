<?php

namespace Tests\Feature\Phase35;

use App\Connectors\ExampleJson\ExampleJsonConnector;
use App\Services\ControlPlane\Connectors\ConnectorDiscovery;
use App\Services\ControlPlane\Connectors\ConnectorPackageInstaller;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 35 — connector marketplace FOUNDATION (technical only): package
 * install lifecycle with integrity + compatibility verification (35B/35C/
 * 35E), trust vocabulary (35A), permission vocabulary already manifest-
 * declared (35D), and malicious-package rejection (35G). No remote
 * marketplace, no billing (35F).
 */
class ConnectorMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected string $packageRoot;
    protected string $installRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packageRoot = storage_path('framework/testing/phase35/packages/'.uniqid());
        $this->installRoot = storage_path('framework/testing/phase35/installed/'.uniqid());
    }

    /** Build a syntactically valid example package. */
    protected function buildPackage(string $dir, array $overrides = []): string
    {
        @mkdir($dir, 0775, true);
        $manifest = array_merge([
            'schema_version' => 1,
            'key' => 'community-weather',
            'name' => 'Community Weather Connector',
            'version' => '0.1.0',
            'description' => 'Read-only example community connector (sandbox fixture).',
            'author' => 'Community Author',
            'publisher' => 'weather-collective',
            'license' => 'MIT',
            'platform_requirement' => '>=0.2.0',
            // The manifest gate requires an entrypoint class that EXISTS and
            // implements the Connector contract — reuse the real example.
            'entrypoint' => 'App\\Connectors\\ExampleJson\\ExampleJsonConnector',
            'capabilities' => ['database_metadata', 'read_only_enforcement'],
            'permissions' => ['filesystem.dataset.read'],
            'trust' => 'community',
            'import_flow' => 'credentials-form',
            'category' => 'source',
        ], $overrides);
        file_put_contents($dir.'/connector.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }

    /** Write checksums.json covering all package files. */
    protected function writeChecksums(string $dir): void
    {
        $checksums = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getFilename() === 'checksums.json') {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr((string) $file->getPathname(), strlen($dir))), '/');
            $checksums[$relative] = hash_file('sha256', (string) $file->getPathname());
        }
        file_put_contents($dir.'/checksums.json', json_encode($checksums));
    }

    protected function installer(): ConnectorPackageInstaller
    {
        return new ConnectorPackageInstaller($this->installRoot);
    }

    public function test_trust_vocabulary_covers_the_35a_surface(): void
    {
        $levels = ['first_party', 'trusted', 'community', 'private', 'unverified'];
        foreach ($levels as $level) {
            $package = $this->buildPackage($this->packageRoot.'/pkg-'.$level, ['trust' => $level]);
            $this->writeChecksums($package);
            $inspection = $this->installer()->inspect($package);
            $this->assertTrue($inspection['ok'], $level.': '.($inspection['reason'] ?? 'ok'));
        }
        // A bogus trust level is refused by the manifest validator.
        $bogus = $this->buildPackage($this->packageRoot.'/pkg-bogus', ['trust' => 'definitely-safe']);
        $this->assertFalse($this->installer()->inspect($bogus)['ok']);
    }

    public function test_package_install_lifecycle(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/weather');
        $this->writeChecksums($package);
        $installer = $this->installer();

        // Install.
        $result = $installer->install($package);
        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        $this->assertSame('community-weather', $result['key']);
        $this->assertSame('community', $result['trust']);

        // Remove.
        $removed = $installer->remove('community-weather');
        $this->assertTrue($removed['ok']);
    }

    public function test_checksum_mismatch_is_refused(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/tampered');
        $this->writeChecksums($package);
        // Tamper AFTER signing the checksums.
        file_put_contents($package.'/connector.json', str_replace('"0.1.0"', '"9.9.9-evil"', (string) file_get_contents($package.'/connector.json')));

        $inspection = $this->installer()->inspect($package);
        $this->assertFalse($inspection['ok']);
        $this->assertStringContainsString('checksum mismatch', $inspection['reason']);
    }

    public function test_package_without_checksums_is_refused(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/no-checksums');
        $inspection = $this->installer()->inspect($package);
        $this->assertFalse($inspection['ok']);
        $this->assertStringContainsString('checksums.json missing', $inspection['reason']);
    }

    public function test_unsupported_platform_requirement_is_refused(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/future', ['platform_requirement' => '>=99.0.0']);
        $this->writeChecksums($package);
        $inspection = $this->installer()->inspect($package);
        $this->assertFalse($inspection['ok']);
        $this->assertStringContainsString('>=99.0.0', $inspection['reason'], '35E — platform gate refuses future requirements');
    }

    public function test_duplicate_key_is_refused(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/dup', ['key' => 'example-json']);
        $this->writeChecksums($package);
        $result = $this->installer()->install($package);
        $this->assertFalse($result['ok'], '35B — duplicate registry key refused');
        $this->assertStringContainsString('already installed', $result['reason']);
    }

    public function test_missing_entrypoint_class_is_refused_by_the_manifest_gate(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/ghost', [
            'entrypoint' => 'App\\Connectors\\Does\\Not\\Exist\\GhostConnector',
        ]);
        $this->writeChecksums($package);
        $inspection = $this->installer()->inspect($package);
        $this->assertFalse($inspection['ok'], '35G — bad entrypoint must fail inspection');
    }

    public function test_traversal_in_entrypoint_is_refused(): void
    {
        $package = $this->buildPackage($this->packageRoot.'/traversal', [
            'entrypoint' => 'App\\Connectors\\..\\..\\Evil\\EvilConnector',
        ]);
        $this->writeChecksums($package);
        $inspection = $this->installer()->inspect($package);
        $this->assertFalse($inspection['ok'], '35G — path traversal in the entrypoint must be rejected');
    }

    public function test_installed_non_first_party_packages_are_not_auto_enabled(): void
    {
        // Discovery honours the 27K.1 policy: only first_party auto-enables.
        $registry = ConnectorRegistry::instance();
        ConnectorRegistry::flushInstance(); $registry = ConnectorRegistry::instance();
        ConnectorDiscovery::discover();
        foreach ($registry->connectors() as $connector) {
            $trust = $connector->manifest()->trust();
            $enabled = $registry->isEnabled($connector->manifest()->key());
            if ($trust === 'first_party') {
                $this->assertTrue($enabled, $connector->manifest()->key());
            } else {
                $this->assertFalse($enabled, "non-first-party package '{$trust}' must not auto-enable");
            }
        }
        $this->assertTrue($registry->isEnabled('supabase'));
        $this->assertTrue($registry->isEnabled('firebase'));
        $this->assertTrue($registry->isEnabled('postgres'));
        $this->assertTrue($registry->isEnabled('mysql'));
    }
}
