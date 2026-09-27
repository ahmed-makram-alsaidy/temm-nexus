<?php

namespace App\Connectors\Mongodb;

/**
 * Phase 28N — GridFS bucket detection.
 *
 * GridFS buckets (<prefix>.files + <prefix>.chunks pairs) are FILE STORAGE,
 * not ordinary relational collections. They are reported under the
 * normalized inventory's storage domain (buckets) and are never treated as
 * ordinary relational tables by default (28N). Metadata preservation maps
 * the files collection; content streaming to platform storage is DEFERRED
 * and reported honestly (28V.8 — no faking).
 */
class GridFsInspector
{
    /** @param list<string> $collectionNames */
    public function detectBuckets(array $collectionNames, callable $countFn, callable $bytesFn): array
    {
        $buckets = [];
        foreach ($collectionNames as $name) {
            if (! str_ends_with((string) $name, '.files')) {
                continue;
            }
            $prefix = substr((string) $name, 0, -6);
            if (! in_array($prefix.'.chunks', $collectionNames, true)) {
                continue; // a .files without .chunks is an ordinary collection
            }
            $buckets[] = [
                'name' => $prefix === 'fs' ? 'fs' : $prefix,
                'files_collection' => $prefix.'.files',
                'chunks_collection' => $prefix.'.chunks',
                'files' => $countFn($prefix.'.files'),
                'bytes' => $bytesFn($prefix.'.files'),
                'kind' => 'gridfs',
            ];
        }

        return $buckets;
    }

    /**
     * 28N.1 — per-bucket migration strategy recommendation (metadata +
     * validation now; content copy to platform storage is DEFERRED).
     */
    public function strategyFor(array $bucket): array
    {
        return [
            'strategy' => 'PRESERVE_METADATA',
            'content_migration' => 'DEFERRED',
            'reason' => 'Files metadata (name, length, md5, uploadDate) maps to a relational table; binary content copy to platform storage is deferred and reported honestly (28V.8).',
            'validation' => ['files_count', 'total_bytes', 'md5_metadata_present'],
        ];
    }
}
