<?php

namespace App\Services\ControlPlane\Repository;

use App\Models\ClientCallsite;
use App\Models\ClientRepository;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;
use Illuminate\Support\Str;

/**
 * Phase 25E — client dependency scanner (Phase 27B: provider-agnostic).
 *
 * Detects real SDK idioms, hard-coded provider URLs and accidentally
 * embedded secrets in the operator's application repository. Pattern sets
 * come from registered CONNECTORS implementing ClientScannerProvider (27B)
 * — the scanner itself is provider-neutral. Evidence is stored HASHED —
 * raw secret material never enters the database, logs, or AI context.
 */
class ClientDependencyScanner
{
    /** Generic secret markers every repository scan applies (beyond connector-provided ones). */
    public const BASE_SECRET_MARKERS = ['DATABASE_PASSWORD', 'DB_PASSWORD', 'CLIENT_SECRET', 'WHATSAPP_TOKEN', 'STRIPE_SECRET', 'PAYMENT_SECRET'];

    /** Connector-provided scanner providers, lazily resolved from the registry. */
    public static function providers(): array
    {
        $providers = [];
        foreach (ConnectorRegistry::instance()->connectors() as $connector) {
            if ($connector instanceof ClientScannerProvider) {
                $providers[] = $connector;
            }
        }

        return $providers;
    }

    /** Effective pattern set for a language: union of all provider patterns. */
    public static function patterns(string $language): array
    {
        $patterns = [];
        foreach (self::providers() as $provider) {
            foreach ($provider->patternsFor($language) as $pattern) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /** Scan a repository and persist the callsite manifest (25E.1). */
    public static function scan(ClientRepository $repo, int $maxFiles = 2000): array
    {
        $files = ClientRepositoryService::candidateFiles($repo, $maxFiles);
        $languageOf = fn (string $f) => match (strtolower(pathinfo($f, PATHINFO_EXTENSION))) {
            'dart' => 'dart', 'js', 'jsx' => 'javascript',
            'ts', 'tsx' => 'typescript', 'php' => 'php',
            default => 'other',
        };
        ClientCallsite::where('client_repository_id', $repo->id)->delete();

        $counts = [];
        $total = 0;
        foreach ($files as $file) {
            $language = $languageOf($file);
            if ($language === 'other') {
                // env/json/yaml: secret scan by name/content markers only.
                $hits = self::scanEnvOrConfigFile($repo, $file);
                $total += $hits;
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false || $content === '') {
                continue;
            }
            $relative = ltrim(str_replace([str_replace('\\', '/', $repo->root_path).'/', str_replace('\\', '/', $repo->root_path)], '', str_replace('\\', '/', $file)), '/');
            foreach (self::patterns($language) as $pattern) {
                if (! preg_match_all($pattern['regex'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $category = $pattern['category'];
                foreach ($matches[0] as $i => $hit) {
                    $line = substr_count(substr($content, 0, $hit[1]), "\n") + 1;
                    $target = null;
                    if ($pattern['target'] !== null && isset($matches[$pattern['target']][$i])) {
                        $target = Str::limit((string) $matches[$pattern['target']][$i][0], 120);
                    }
                    ClientCallsite::create([
                        'client_repository_id' => $repo->id,
                        'file' => $relative,
                        'line' => $line,
                        'category' => $category,
                        'target' => $target,
                        'language' => $language,
                        'confidence' => $category === 'url' || $category === 'secret' ? 'high' : 'high',
                        'status' => 'DISCOVERED',
                        'evidence_hash' => hash('sha256', $hit[0]),
                    ]);
                    $counts[$category] = ($counts[$category] ?? 0) + 1;
                    $total++;
                }
            }
        }

        $repo->update(['status' => 'scanned', 'last_scanned_at' => now()]);
        AdminAudit::record('CLIENT_SCAN_RUN', $repo->project, 'client_repository', $repo->id, [
            'files' => count($files), 'callsites' => $total, 'by_category' => $counts,
        ]);

        return ['files' => count($files), 'callsites' => $total, 'by_category' => $counts];
    }

    /** Env/config files: detect secrets BY NAME/marker — values never stored. */
    protected static function scanEnvOrConfigFile(ClientRepository $repo, string $file): int
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return 0;
        }
        $relative = ltrim(str_replace([str_replace('\\', '/', $repo->root_path).'/', str_replace('\\', '/', $repo->root_path)], '', str_replace('\\', '/', $file)), '/');
        $hits = 0;
        $markers = self::BASE_SECRET_MARKERS;
        foreach (self::providers() as $provider) {
            $markers = array_merge($markers, $provider->secretMarkers());
        }
        foreach ($markers as $marker) {
            if (preg_match_all('/^\s*'.preg_quote($marker, '/').'[A-Z_]*\s*=\s*\S+/mi', $content, $m)) {
                foreach ($m[0] as $hit) {
                    // Evidence is hashed; the raw line is never persisted.
                    ClientCallsite::create([
                        'client_repository_id' => $repo->id,
                        'file' => $relative,
                        'line' => null,
                        'category' => 'secret',
                        'target' => $marker,
                        'language' => 'env',
                        'confidence' => 'high',
                        'status' => 'REVIEW',
                        'evidence_hash' => hash('sha256', $hit),
                    ]);
                    $hits++;
                }
            }
        }

        return $hits;
    }

    /**
     * 25E.3 — secret findings for a repository (masked, hash-anchored).
     * Raw values are never returned — only file + marker + evidence hash.
     */
    public static function secretFindings(ClientRepository $repo): array
    {
        return ClientCallsite::where('client_repository_id', $repo->id)
            ->where('category', 'secret')
            ->get(['id', 'file', 'line', 'target', 'evidence_hash', 'status'])
            ->map(fn ($c) => [
                'id' => $c->id, 'file' => $c->file, 'line' => $c->line,
                'marker' => $c->target, 'evidence' => substr($c->evidence_hash, 0, 12).'…',
                'status' => $c->status,
            ])->all();
    }

    /** Callsite manifest for AI consumption — structured, secret-free. */
    public static function manifest(ClientRepository $repo, int $limit = 2000): array
    {
        return ClientCallsite::where('client_repository_id', $repo->id)
            ->where('category', '!=', 'secret')
            ->orderBy('file')->orderBy('line')
            ->limit($limit)
            ->get(['file', 'line', 'category', 'target', 'language', 'status'])
            ->toArray();
    }
}
