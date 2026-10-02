<?php

namespace App\Services\ControlPlane\Ai\Conversion;

/**
 * Phase 33A/33C — parse the AI's unified-diff output into PatchWorkspace
 * patch proposals. Parsing is defensive: a diff that does not parse yields
 * an empty proposal list (the conversion loop reports it and can retry),
 * never a partial write.
 */
class DiffParser
{
    /** @return list<array{path: string, action: string, diff: string, reason: string}> */
    public function parse(string $diffOutput): array
    {
        $patches = [];
        $current = null;
        $pendingOldPath = null;
        $lines = preg_split('/\r?\n/', $diffOutput) ?: [];

        foreach ($lines as $line) {
            if (preg_match('#^--- (.*?)(?:\t.*)?$#', $line, $m)) {
                $old = trim($m[1]);
                $pendingOldPath = $old === '/dev/null' ? null : preg_replace('#^a/#', '', $old);

                continue;
            }
            if (preg_match('#^\+\+\+ (.*?)(?:\t.*)?$#', $line, $m)) {
                $new = trim($m[1]);
                $newPath = $new === '/dev/null' ? null : preg_replace('#^b/#', '', $new);
                $path = $newPath ?? $pendingOldPath;
                if ($path === null || $path === '') {
                    continue; // malformed header — skip honestly
                }
                if ($current !== null) {
                    $patches[] = $current;
                }
                $current = [
                    'path' => (string) $path,
                    'action' => ($newPath === null || $pendingOldPath === null) ? 'create' : 'modify',
                    'diff' => $line."\n",
                    'reason' => 'AI client-code conversion',
                ];

                continue;
            }
            if ($current !== null) {
                $current['diff'] .= $line."\n";
            }
        }
        if ($current !== null) {
            $patches[] = $current;
        }

        return $patches;
    }
}
