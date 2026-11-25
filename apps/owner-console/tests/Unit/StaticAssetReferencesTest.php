<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every static file a page loads by absolute path (/js/..., /css/...) must exist
 * in public/ and must survive every way the app ships. The SQL editor once
 * loaded /vendor/codemirror/*: the generic `vendor` exclusions of git, Docker
 * and the release package dropped the files, production answered 404, the
 * page script stopped and Run did nothing.
 */
class StaticAssetReferencesTest extends TestCase
{
    /** Folder names that .gitignore, .dockerignore or scripts/release/package-dist.sh drop at any depth. */
    private const DROPPED_FOLDER_NAMES = ['vendor', 'node_modules', 'dist', 'build', '.next', 'coverage', 'storage', 'hot'];

    /** @return array<string, list<string>> referenced path => files that reference it */
    private function references(): array
    {
        $base = dirname(__DIR__, 2);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/app'));
        $referenced = [];
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all(
                '#(?:src|href)=["\'](/(?:vendor|js|css|fonts|images|img)/[^"\'?\#\s>]+)#',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );
            foreach ($matches[1] as $path) {
                if (! str_contains($path, '__') && ! str_contains($path, '{')) {
                    $referenced[$path][] = substr($file->getPathname(), strlen($base) + 1);
                }
            }
        }

        return $referenced;
    }

    public function test_every_referenced_static_file_exists_in_public(): void
    {
        $base = dirname(__DIR__, 2);
        $referenced = $this->references();
        $this->assertArrayHasKey('/js/codemirror/codemirror.min.js', $referenced, 'sanity: the SQL editor reference is found');

        $missing = [];
        foreach ($referenced as $path => $users) {
            if (! is_file($base.'/public'.$path)) {
                $missing[] = $path.' (used by '.implode(', ', array_unique($users)).')';
            }
        }
        $this->assertSame([], $missing, "Static files referenced by pages but missing from public/:\n".implode("\n", $missing));
    }

    public function test_referenced_static_files_avoid_folder_names_that_shipping_drops(): void
    {
        $dropped = [];
        foreach (array_keys($this->references()) as $path) {
            $folders = array_slice(explode('/', trim($path, '/')), 0, -1);
            if (array_intersect($folders, self::DROPPED_FOLDER_NAMES) !== []) {
                $dropped[] = $path;
            }
        }
        $this->assertSame([], $dropped, "These static files sit in a folder that git, Docker or the release package leaves out:\n".implode("\n", $dropped));
    }
}
