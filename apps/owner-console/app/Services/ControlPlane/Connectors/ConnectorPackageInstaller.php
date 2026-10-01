<?php

namespace App\Services\ControlPlane\Connectors;

use App\Services\ControlPlane\Connectors\Support\Platform;
use Illuminate\Support\Str;

/**
 * Phase 35B/35C/35E — controlled connector-package lifecycle.
 *
 * Discover → Inspect → Verify → Install → Enable → Disable → Update →
 * Remove. A package is a DIRECTORY containing connector.json + its
 * entrypoint; installation verifies INTEGRITY (sha256 checksums for every
 * file against a checksums.json manifest) and COMPATIBILITY (platform
 * requirement gate) before anything is copied.
 *
 * Trust honesty (35C): checksums prove the package was not corrupted in
 * transit. PUBLISHER identity is metadata only; CRYPTOGRAPHIC signature
 * verification is PLANNED and is never claimed as implemented. Non-
 * first_party packages are NOT auto-enabled (27K.1 remains in force).
 */
class ConnectorPackageInstaller
{
    /** Malformed/oversized files are refused before parsing (27T lineage). */
    public const MAX_FILE_BYTES = 262144; // 256 KB per package file

    public function __construct(protected string $installRoot)
    {
    }

    /**
     * Full inspect step: validate the package WITHOUT installing. Returns
     * the parsed manifest + integrity verdict.
     *
     * @return array{ok: bool, manifest?: ConnectorManifest, files?: int, reason?: string}
     */
    public function inspect(string $packageDir): array
    {
        if (! is_dir($packageDir)) {
            return ['ok' => false, 'reason' => 'package directory not found'];
        }
        $manifestPath = $packageDir.'/connector.json';
        if (! is_file($manifestPath)) {
            return ['ok' => false, 'reason' => 'connector.json missing'];
        }
        if (filesize($manifestPath) > ConnectorManifest::MAX_BYTES) {
            return ['ok' => false, 'reason' => 'connector.json exceeds the manifest size cap'];
        }
        try {
            $manifest = ConnectorManifest::parseFile($manifestPath);
        } catch (ConnectorManifestInvalid $e) {
            // The exception message embeds the full manifest path; on deep
            // install roots a 200-char cap would cut away the actual error
            // tail (e.g. the platform requirement). Shorten the path, keep
            // the reason intact.
            $message = str_replace($packageDir.'/', '<package>/', $e->getMessage());
            $message = str_replace($packageDir, '<package>', $message);

            return ['ok' => false, 'reason' => 'manifest invalid: '.mb_substr($message, 0, 200)];
        }

        // 35E — platform compatibility gate.
        $requirement = (string) ($manifest->data['platform_requirement'] ?? '*');
        if (! $this->platformSatisfies($requirement)) {
            return ['ok' => false, 'reason' => "package requires platform {$requirement}; running ".Platform::version()];
        }

        // 35C — integrity: checksums.json must cover every package file.
        $checksumsPath = $packageDir.'/checksums.json';
        if (! is_file($checksumsPath)) {
            return ['ok' => false, 'reason' => 'checksums.json missing — packages without integrity metadata are refused'];
        }
        $checksums = json_decode((string) file_get_contents($checksumsPath), true);
        if (! is_array($checksums)) {
            return ['ok' => false, 'reason' => 'checksums.json is not valid JSON'];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($packageDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isDir()) {
                continue;
            }
            if ($file->getSize() > self::MAX_FILE_BYTES) {
                return ['ok' => false, 'reason' => 'package file exceeds the size cap: '.$file->getFilename()];
            }
            $relative = ltrim(str_replace('\\', '/', substr((string) $file->getPathname(), strlen($packageDir))), '/');
            if ($relative === 'checksums.json') {
                continue;
            }
            $files[$relative] = hash_file('sha256', (string) $file->getPathname());
        }
        foreach ($files as $relative => $hash) {
            $expected = $checksums[$relative] ?? null;
            if (! is_string($expected) || ! hash_equals($expected, $hash)) {
                return ['ok' => false, 'reason' => "checksum mismatch for {$relative} — package refused"];
            }
        }
        $extra = array_diff(array_keys($checksums), array_keys($files));
        if ($extra !== []) {
            return ['ok' => false, 'reason' => 'checksums.json lists missing files: '.implode(', ', array_slice($extra, 0, 3))];
        }

        return ['ok' => true, 'manifest' => $manifest, 'files' => count($files)];
    }

    /** Install a verified package into the connector package root. */
    public function install(string $packageDir): array
    {
        $inspection = $this->inspect($packageDir);
        if (! $inspection['ok']) {
            return $inspection;
        }
        /** @var ConnectorManifest $manifest */
        $manifest = $inspection['manifest'];
        $key = $manifest->key();

        // 35B — duplicate-key guard across the registry.
        if (ConnectorRegistry::instance()->has($key)) {
            return ['ok' => false, 'reason' => "a connector with key '{$key}' is already installed"];
        }

        $destination = $this->installRoot.'/'.Str::studly($key);
        if (! is_dir($this->installRoot)) {
            mkdir($this->installRoot, 0775, true);
        }
        if (is_dir($destination)) {
            return ['ok' => false, 'reason' => 'install destination already exists'];
        }
        // Copy the VERIFIED package files only.
        mkdir($destination, 0775, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($packageDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr((string) $file->getPathname(), strlen($packageDir))), '/');
            if ($relative === 'checksums.json') {
                continue;
            }
            $target = $destination.'/'.$relative;
            @mkdir(dirname($target), 0775, true);
            copy((string) $file->getPathname(), $target);
        }

        return ['ok' => true, 'key' => $key, 'destination' => $destination, 'trust' => $manifest->trust()];
    }

    /** Remove an installed package directory (connector must be disabled). */
    public function remove(string $key): array
    {
        $destination = $this->installRoot.'/'.Str::studly($key);
        if (! is_dir($destination)) {
            return ['ok' => false, 'reason' => "package '{$key}' is not installed here"];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($destination);

        return ['ok' => true];
    }

    /** 35E — naive but strict >=X.Y.Z gate (reuses Platform core semantics). */
    protected function platformSatisfies(string $requirement): bool
    {
        $requirement = trim($requirement);
        if ($requirement === '' || $requirement === '*') {
            return true;
        }
        $required = ltrim($requirement, '>=');
        $running = Platform::core();

        return version_compare($running, $required, '>=');
    }
}
