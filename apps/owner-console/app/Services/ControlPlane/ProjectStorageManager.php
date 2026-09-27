<?php

namespace App\Services\ControlPlane;

use App\Models\Project;

/**
 * Storage administration confined to ONE project's control-plane root:
 *   <repo>/projects/<slug>/storage/control-plane/
 * Logical "buckets" are top-level directories. Path traversal outside the root
 * is impossible: every path is resolved with realpath() and prefix-checked.
 * The UI never touches host paths — only this root, addressed by bucket + key.
 */
class ProjectStorageManager
{
    public const MAX_UPLOAD_BYTES = 24 * 1024 * 1024;

    public const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/';

    public function __construct(protected Project $project, protected string $root) {}

    public static function for(Project $project, ?string $repoRoot = null): self
    {
        $repoRoot ??= ControlPlanePaths::repoRoot();
        $root = $repoRoot.'/projects/'.$project->slug.'/storage/control-plane';
        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }

        return new self($project, realpath($root) ?: $root);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<array{name:string,files:int,bytes:int,visibility:string}> */
    public function buckets(): array
    {
        $out = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->root.'/'.$entry;
            if (! is_dir($path)) {
                continue;
            }
            [$files, $bytes] = $this->usage($path);
            $out[] = [
                'name' => $entry,
                'files' => $files,
                'bytes' => $bytes,
                'visibility' => is_file($path.'/.public') ? 'public' : 'private',
            ];
        }

        return $out;
    }

    public function createBucket(string $name, string $visibility = 'private'): void
    {
        abort_unless(preg_match(self::FILENAME_PATTERN, $name) === 1, 422, 'Invalid bucket name.');
        $path = $this->resolve($name, false);
        abort_if(is_dir($path), 422, 'Bucket already exists.');
        mkdir($path, 0755, true);
        if ($visibility === 'public') {
            touch($path.'/.public');
        }
    }

    public function deleteBucket(string $name): void
    {
        $path = $this->resolve($name);
        abort_unless(is_dir($path), 404);
        $this->removeRecursive($path);
    }

    /** @return list<array{name:string,type:string,size:int|null,mime:?string,modified:?string}> */
    public function files(string $bucket, string $prefix = ''): array
    {
        $dir = $this->resolve($bucket.($prefix !== '' ? '/'.$prefix : ''));
        abort_unless(is_dir($dir), 404);
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.public') {
                continue;
            }
            $full = $dir.'/'.$entry;
            $rel = ltrim($prefix.'/'.$entry, '/');
            $out[] = is_dir($full)
                ? ['name' => $entry, 'path' => $rel, 'type' => 'folder', 'size' => null, 'mime' => null, 'modified' => date('c', filemtime($full))]
                : ['name' => $entry, 'path' => $rel, 'type' => 'file', 'size' => filesize($full), 'mime' => mime_content_type($full) ?: null, 'modified' => date('c', filemtime($full))];
        }

        return $out;
    }

    public function store(string $bucket, string $prefix, string $filename, string $contents): string
    {
        abort_unless(preg_match(self::FILENAME_PATTERN, $filename) === 1, 422, 'Invalid filename.');
        abort_if(strlen($contents) > self::MAX_UPLOAD_BYTES, 422, 'File exceeds 24 MB limit.');
        $dir = $this->resolve($bucket.($prefix !== '' ? '/'.$prefix : ''));
        abort_unless(is_dir($dir), 404, 'Unknown bucket or folder.');
        $target = $this->resolve($bucket.($prefix !== '' ? '/'.$prefix.'/'.$filename : '/'.$filename), false);
        file_put_contents($target, $contents);

        return ltrim($prefix.'/'.$filename, '/');
    }

    public function read(string $bucket, string $key): array
    {
        $path = $this->resolve($bucket.'/'.$key);
        abort_unless(is_file($path), 404);

        return ['contents' => file_get_contents($path), 'mime' => mime_content_type($path) ?: 'application/octet-stream', 'size' => filesize($path), 'name' => basename($path)];
    }

    public function move(string $bucket, string $from, string $toFolder, ?string $newName = null): string
    {
        $src = $this->resolve($bucket.'/'.$from);
        abort_unless(is_file($src) || is_dir($src), 404);
        $destDir = $this->resolve($bucket.($toFolder !== '' ? '/'.$toFolder : ''), false);
        if (! is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $name = $newName ?? basename($src);
        abort_unless(preg_match(self::FILENAME_PATTERN, $name) === 1, 422, 'Invalid filename.');
        $dest = $this->resolve($bucket.(($toFolder !== '' ? '/'.$toFolder : '').'/'.$name), false);
        abort_if(file_exists($dest), 422, 'Destination already exists.');
        rename($src, $dest);

        return ltrim(($toFolder !== '' ? $toFolder.'/' : '').$name, '/');
    }

    public function delete(string $bucket, string $key): void
    {
        $path = $this->resolve($bucket.'/'.$key);
        abort_unless(file_exists($path), 404);
        is_dir($path) ? $this->removeRecursive($path) : unlink($path);
    }

    /** Total bytes under the project root. */
    public function totalBytes(): int
    {
        return $this->usage($this->root)[1];
    }

    /**
     * Resolve a root-relative path, enforcing containment.
     * $mustExist=false allows resolving not-yet-created destinations.
     */
    public function resolve(string $relative, bool $mustExist = true): string
    {
        abort_if(str_contains($relative, "\0"), 422, 'Invalid path.');
        $parts = explode('/', str_replace('\\', '/', $relative));
        $clean = [];
        foreach ($parts as $part) {
            abort_if($part === '..', 422, 'Path traversal blocked.');
            if ($part === '' || $part === '.') {
                continue;
            }
            $clean[] = $part;
        }
        $candidate = $this->root.'/'.implode('/', $clean);
        $realRoot = realpath($this->root) ?: $this->root;

        if ($mustExist) {
            $real = realpath($candidate);
            abort_unless($real !== false, 404);
            // Trailing separator blocks sibling-prefix confusion (root2 vs root).
            abort_unless($real === $realRoot || str_starts_with($real, $realRoot.'/'), 403, 'Outside project storage.');

            return $real;
        }

        abort_unless(str_starts_with($candidate, $realRoot.'/'), 403, 'Outside project storage.');

        return $candidate;
    }

    /** @return array{int,int} [files, bytes] */
    protected function usage(string $dir): array
    {
        $files = 0;
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files++;
                $bytes += $file->getSize();
            }
        }

        return [$files, $bytes];
    }

    protected function removeRecursive(string $dir): void
    {
        $real = realpath($dir);
        $realRoot = realpath($this->root) ?: $this->root;
        abort_unless($real && str_starts_with($real, $realRoot.'/'), 403, 'Outside project storage.');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($real);
    }
}
