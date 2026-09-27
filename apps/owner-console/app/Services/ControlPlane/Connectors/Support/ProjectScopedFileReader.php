<?php

namespace App\Services\ControlPlane\Connectors\Support;

/**
 * Phase 27K.2/27K.3 — constrained local file access for connectors.
 *
 * Connectors never receive raw filesystem access. When a connector's
 * declared configuration points at local files (e.g. an operator-provided
 * dataset directory), reads go through this reader: paths must resolve
 * INSIDE the operator-configured root, with extension and size guards
 * (27T path traversal / artifact path escape defenses).
 */
class ProjectScopedFileReader
{
    /**
     * Resolve a path under $root and assert it is a readable file within
     * the guards. Returns the resolved absolute path.
     *
     * @param  list<string>  $allowedExtensions  lowercase, without dot (e.g. ['json'])
     */
    public static function assertReadableFile(string $root, string $path, array $allowedExtensions, int $maxBytes): string
    {
        $rootReal = realpath($root);
        abort_if($rootReal === false || ! is_dir($rootReal), 422, 'Connector file root does not exist.');

        // Absolute paths must already be inside the root; relative resolve under it.
        $candidate = $path;
        if (! preg_match('/^([A-Za-z]:[\/\\\\]|\/|\\\\)/', $candidate)) {
            $candidate = $rootReal.DIRECTORY_SEPARATOR.ltrim($candidate, '/\\');
        }

        // Guard before realpath: reject explicit traversal segments outright.
        $normalized = str_replace('\\', '/', $candidate);
        if (str_contains($normalized, '/../') || str_contains($normalized, '/..\\') || preg_match('/(^|\/)\.\.($|\/)/', $normalized)) {
            abort(422, 'Connector file path escapes its configured root.');
        }

        $real = realpath($candidate);
        abort_if($real === false, 422, 'Connector file not found.');
        $realNormalized = str_replace('\\', '/', $real);
        $rootNormalized = str_replace('\\', '/', $rootReal);
        abort_if(! str_starts_with($realNormalized, $rootNormalized.'/') && $realNormalized !== $rootNormalized, 422, 'Connector file path escapes its configured root.');

        abort_if(! is_file($real), 422, 'Connector target is not a file.');
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        abort_if($allowedExtensions !== [] && ! in_array($extension, $allowedExtensions, true), 422, "Connector file extension '{$extension}' is not allowed.");
        $size = (int) filesize($real);
        abort_if($size > $maxBytes, 422, 'Connector file exceeds the size limit ('.round($maxBytes / 1024).' kB).');

        return $real;
    }

    /** Read a guarded file's contents (never larger than $maxBytes). */
    public static function readFile(string $root, string $path, array $allowedExtensions, int $maxBytes): string
    {
        $real = self::assertReadableFile($root, $path, $allowedExtensions, $maxBytes);
        $content = file_get_contents($real);
        abort_if($content === false, 422, 'Connector file could not be read.');

        return $content;
    }

    /** List guarded JSON dataset files directly inside a root directory. */
    public static function datasetFiles(string $root, string $extension = 'json', int $maxFiles = 50): array
    {
        $rootReal = realpath($root);
        abort_if($rootReal === false || ! is_dir($rootReal), 422, 'Connector dataset root does not exist.');
        $files = glob($rootReal.DIRECTORY_SEPARATOR.'*.'. $extension) ?: [];
        sort($files);
        abort_if(count($files) > $maxFiles, 422, "Connector dataset exceeds {$maxFiles} files.");

        return $files;
    }
}
