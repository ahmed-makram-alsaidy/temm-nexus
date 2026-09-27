<?php

namespace App\Services\ControlPlane\Repository;

use App\Models\ClientRepository;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Support\Str;

/**
 * Phase 25D — client repository linking.
 *
 * LOCAL PATH: the operator explicitly approves a root; every filesystem
 * operation in the scanner/patcher must resolve under it (realpath guard).
 * GIT: metadata model only (url/branch/credential ref) — no credential
 * handling is invented in this phase.
 */
class ClientRepositoryService
{
    /** Link a local repository root (approved by the operator). */
    public static function linkLocal(Project $project, string $displayName, string $rootPath): ClientRepository
    {
        $resolved = realpath($rootPath);
        abort_if($resolved === false, 422, 'Path does not exist: '.$rootPath);
        abort_if(! is_dir($resolved), 422, 'Approved root must be a directory.');
        // The root must exist on disk; traversal beyond it is rejected at use
        // time (self::safePath). Obvious system roots are refused at link time.
        $normalized = str_replace('\\', '/', $resolved);
        foreach (['/etc', '/proc', '/sys', '/dev', '/bin', '/boot', 'C:/Windows', 'C:/Windows/System32'] as $forbidden) {
            abort_if($normalized === $forbidden || str_starts_with($normalized.'/', $forbidden.'/'), 422, 'Refusing system root as repository.');
        }
        abort_if(preg_match('#^/[a-z]$#', $normalized) || preg_match('#^[a-zA-Z]:/$#', $normalized), 422, 'Refusing filesystem root as repository.');

        $repo = ClientRepository::create([
            'project_id' => $project->id,
            'display_name' => $displayName,
            'source_type' => 'local',
            'root_path' => $resolved,
            'framework' => self::detectFramework($resolved),
            'status' => 'linked',
            'inventory' => self::inventory($resolved),
            'created_by' => auth()->id(),
        ]);
        AdminAudit::record('REPOSITORY_LINKED', $project, 'client_repository', $repo->id, [
            'display_name' => $displayName, 'framework' => $repo->framework, 'source_type' => 'local',
        ]);

        return $repo;
    }

    /** Link a git repository (metadata model only — no clone/credentials here). */
    public static function linkGit(Project $project, string $displayName, string $url, string $branch, ?string $credentialRef): ClientRepository
    {
        abort_if(! preg_match('#^https://|^git@|^ssh://#', $url), 422, 'Git URL must be https/ssh.');
        $repo = ClientRepository::create([
            'project_id' => $project->id,
            'display_name' => $displayName,
            'source_type' => 'git',
            'git_url' => $url,
            'git_branch' => $branch ?: 'main',
            'credential_ref' => $credentialRef, // vault ref name only
            'framework' => 'unknown',
            'status' => 'linked',
            'created_by' => auth()->id(),
        ]);
        AdminAudit::record('REPOSITORY_LINKED', $project, 'client_repository', $repo->id, [
            'display_name' => $displayName, 'source_type' => 'git', 'branch' => $repo->git_branch,
        ]);

        return $repo;
    }

    /**
     * 25D.1 — resolve a path INSIDE the approved root (or the AI sandbox).
     * Rejects traversal (`..`), absolute unrelated paths and missing files.
     */
    public static function safePath(ClientRepository $repo, string $relative): string
    {
        abort_if($repo->root_path === null, 422, 'Repository root unavailable.');

        return self::safePathForRoot($repo->root_path, $relative);
    }

    /** Root-based variant of the same guard (repos, target trees, sandboxes). */
    public static function safePathForRoot(string $root, string $relative, bool $createIfMissing = false): string
    {
        abort_if($root === null || ! is_dir($root), 422, 'Approved root unavailable.');
        $relative = str_replace('\\', '/', $relative);
        abort_if(str_contains($relative, '..'), 422, 'Path traversal rejected.');
        abort_if(str_starts_with($relative, '/') || preg_match('#^[a-zA-Z]:#', $relative), 422, 'Absolute paths rejected.');

        $full = rtrim($root, '\\/').DIRECTORY_SEPARATOR.$relative;
        $real = realpath($full);
        if ($real === false) {
            if (! $createIfMissing) {
                abort(404, 'File not found: '.$relative);
            }
            // Guard each path segment before creating (no symlink escape).
            $partial = rtrim($root, '\\/');
            foreach (explode('/', $relative) as $segment) {
                $partial .= DIRECTORY_SEPARATOR.$segment;
                if (! file_exists($partial)) {
                    break;
                }
                $realPartial = realpath($partial);
                abort_if($realPartial !== false && ! str_starts_with(str_replace('\\', '/', $realPartial), str_replace('\\', '/', realpath($root)).'/'), 422, 'Path escaped the approved root.');
            }

            return $full;
        }

        $realRoot = realpath($root);
        abort_if(! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $realRoot).'/'), 422, 'Path escaped the approved repository root.');

        return $real;
    }

    /** List readable files under the approved root, capped + filtered. */
    public static function candidateFiles(ClientRepository $repo, int $maxFiles = 2000): array
    {
        abort_if($repo->source_type !== 'local' || ! is_dir((string) $repo->root_path), 422, 'Local repository required.');
        $root = $repo->root_path;
        $extensions = ['dart', 'js', 'jsx', 'ts', 'tsx', 'php', 'env', 'json', 'yaml', 'yml', 'toml'];
        $skipDirs = ['node_modules', '.git', 'build', '.dart_tool', 'vendor', 'dist', '.next', '.idea'];
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (count($files) >= $maxFiles) {
                break;
            }
            $path = str_replace('\\', '/', (string) $file->getPathname());
            $parts = explode('/', $path);
            foreach ($skipDirs as $skip) {
                if (in_array($skip, $parts, true)) {
                    continue 2;
                }
            }
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, $extensions, true)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /** 25D.3 — repository inventory: frameworks, manifests, env files (names only). */
    public static function inventory(string $root): array
    {
        $has = fn (string $p) => file_exists($root.DIRECTORY_SEPARATOR.$p);
        $framework = self::detectFramework($root);
        $packageFiles = [];
        foreach (['pubspec.yaml', 'package.json', 'composer.json', 'go.mod', 'requirements.txt', 'Cargo.toml'] as $f) {
            if ($has($f)) {
                $packageFiles[] = $f;
            }
        }
        $envFiles = [];
        foreach (['.env', '.env.local', '.env.production', '.env.example'] as $f) {
            if ($has($f)) {
                $envFiles[] = $f; // NAMES ONLY — never values
            }
        }
        $tests = [];
        foreach (['test', 'tests', '__tests__', 'integration_test'] as $d) {
            if (is_dir($root.DIRECTORY_SEPARATOR.$d)) {
                $tests[] = $d;
            }
        }

        return [
            'framework' => $framework,
            'package_files' => $packageFiles,
            'env_files' => $envFiles,     // names only, never contents
            'test_dirs' => $tests,
            // Phase 27B — connector-provided config directory inventory.
            'provider_config_dirs' => self::providerConfigDirs($root),
        ];
    }

    /** Connector-declared provider config directories present in the repo. */
    protected static function providerConfigDirs(string $root): array
    {
        $found = [];
        foreach (ClientDependencyScanner::providers() as $provider) {
            foreach ($provider->configDirNames() as $dir) {
                if (is_dir($root."/".$dir)) {
                    $found[] = $dir."/";
                }
            }
        }

        return $found;
    }

    /** Framework detection from manifest files. */
    public static function detectFramework(string $root): string
    {
        if (file_exists($root.'/pubspec.yaml')) {
            return 'flutter';
        }
        if (file_exists($root.'/composer.json')) {
            return 'php';
        }
        if (file_exists($root.'/package.json')) {
            $pkg = json_decode((string) file_get_contents($root.'/package.json'), true) ?: [];
            $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);
            if (isset($deps['next'])) {
                return 'react';
            }
            if (isset($deps['react'])) {
                return 'react';
            }

            return 'javascript';
        }

        return 'unknown';
    }
}
