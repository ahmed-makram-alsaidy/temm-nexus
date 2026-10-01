<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29G — Firebase Storage inventory and migration strategy.
 *
 * Inventory: bucket, objects, sizes, content types, custom metadata and
 * checksums (md5) where available. Migration strategy is classified
 * deterministically and honestly:
 *   STREAM_TO_PLATFORM_STORAGE — object bytes streamed to the target's
 *                                platform storage on rehearsal (default);
 *   PRESERVE_METADATA          — custom metadata carried alongside;
 *   SKIP                       — zero-byte objects need no transfer;
 *   NEEDS_REVIEW               — no checksum available, so byte-fidelity
 *                                cannot be verified after copy.
 */
class FirebaseStorageAnalyzer
{
    /**
     * Analyze one storage listing (list of raw REST object resources).
     *
     * @param  list<array>  $items  raw REST object payloads
     * @return array{present: bool, bucket: string, object_count: int, total_bytes: int, content_types: array<string,int>, prefixes: list<string>, objects: list<array>, strategies: array<string,int>}
     */
    public function analyze(array $items, string $bucket, array $prefixes = []): array
    {
        $objects = [];
        $contentTypes = [];
        $totalBytes = 0;
        $strategies = ['STREAM_TO_PLATFORM_STORAGE' => 0, 'PRESERVE_METADATA' => 0, 'SKIP' => 0, 'NEEDS_REVIEW' => 0];

        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $size = (int) ($item['size'] ?? 0);
            $contentType = (string) ($item['contentType'] ?? 'application/octet-stream');
            $md5 = (string) ($item['md5Hash'] ?? '');
            $customMetadata = is_array($item['metadata'] ?? null) ? array_keys($item['metadata']) : [];
            $strategy = $this->decideStrategy($size, $md5, $customMetadata);
            $strategies[$strategy]++;
            $contentTypes[$contentType] = ($contentTypes[$contentType] ?? 0) + 1;
            $totalBytes += $size;
            $objects[] = [
                'name' => $name,
                'size' => $size,
                'content_type' => $contentType,
                'md5_available' => $md5 !== '',
                'custom_metadata_keys' => $customMetadata,
                'updated' => (string) ($item['updated'] ?? ''),
                'strategy' => $strategy,
            ];
        }
        uasort($objects, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'present' => $items !== [],
            'bucket' => $bucket,
            'object_count' => count($objects),
            'total_bytes' => $totalBytes,
            'content_types' => $contentTypes,
            'prefixes' => array_values($prefixes),
            'objects' => $objects,
            'strategies' => $strategies,
        ];
    }

    /** Deterministic, honest strategy decision (29G). */
    public function decideStrategy(int $size, string $md5, array $customMetadataKeys = []): string
    {
        if ($size === 0) {
            return 'SKIP'; // nothing to transfer
        }
        if ($md5 === '') {
            return 'NEEDS_REVIEW'; // no checksum → copy fidelity unverifiable
        }
        if ($customMetadataKeys !== []) {
            return 'PRESERVE_METADATA'; // custom metadata must carry alongside the bytes
        }

        return 'STREAM_TO_PLATFORM_STORAGE';
    }
}
