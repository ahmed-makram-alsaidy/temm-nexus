<?php

namespace App\Services\ControlPlane\Ai\Conversion;

use App\Models\ClientCallsite;
use App\Models\ClientRepository;

/**
 * Phase 33A/33B — deterministic conversion planning.
 *
 * The scanner (29I/25E) knows WHAT must change; the plan builder turns
 * callsites into an ordered, reviewable conversion plan per stack
 * (Supabase client code, Firebase client code) BEFORE any AI is involved.
 * Planning is deterministic — the AI only drafts the patch inside the
 * approved plan's bounds.
 */
class ConversionPlanBuilder
{
    /** 33B — supported conversions (everything else is out of scope, honestly). */
    public const STACKS = [
        'supabase-js' => ['provider' => 'supabase', 'language' => 'javascript'],
        'supabase-dart' => ['provider' => 'supabase', 'language' => 'dart'],
        'firebase-js' => ['provider' => 'firebase', 'language' => 'javascript'],
        'firebase-dart' => ['provider' => 'firebase', 'language' => 'dart'],
    ];

    /** @return array{stack: string, items: list<array>, skipped: list<array>} */
    public function plan(ClientRepository $repo): array
    {
        $stack = $this->detectStack($repo);
        if ($stack === null) {
            return ['stack' => null, 'items' => [], 'skipped' => []];
        }
        ['provider' => $provider, 'language' => $language] = self::STACKS[$stack];

        $callsites = ClientCallsite::query()
            ->where('client_repository_id', $repo->id)
            ->where('language', $language)
            ->where('status', 'DISCOVERED')
            ->orderBy('file')->orderBy('line')
            ->get();

        $items = [];
        $skipped = [];
        foreach ($callsites as $callsite) {
            $strategy = $this->strategyFor($provider, (string) $callsite->category);
            if ($strategy === null) {
                // Categories with no deterministic mapping are recorded, not
                // silently dropped (33G manual-review surface).
                $skipped[] = ['file' => $callsite->file, 'line' => $callsite->line, 'category' => $callsite->category];
                continue;
            }
            $items[] = [
                'file' => (string) $callsite->file,
                'line' => (int) $callsite->line,
                'category' => (string) $callsite->category,
                'target' => $callsite->target !== null ? (string) $callsite->target : '',
                'strategy' => $strategy,
            ];
        }

        return ['stack' => $stack, 'items' => $items, 'skipped' => $skipped];
    }

    /** Detect the client stack from the repository inventory + callsites. */
    protected function detectStack(ClientRepository $repo): ?string
    {
        $languages = ClientCallsite::query()
            ->where('client_repository_id', $repo->id)
            ->distinct()->pluck('language')->all();
        $hasFirebase = ClientCallsite::query()
            ->where('client_repository_id', $repo->id)
            ->whereIn('category', ['import', 'auth', 'firestore', 'storage', 'functions', 'admin', 'messaging'])
            ->exists();
        $hasSupabase = ClientCallsite::query()
            ->where('client_repository_id', $repo->id)
            ->whereIn('category', ['auth', 'database', 'rpc', 'realtime'])
            ->where(function ($q) use ($languages) {
                $q->whereIn('language', array_intersect($languages, ['javascript', 'typescript']))
                    ->orWhereNull('language');
            })
            ->exists();

        if ($hasFirebase && in_array('dart', $languages, true)) {
            return 'firebase-dart';
        }
        if ($hasFirebase) {
            return 'firebase-js';
        }
        if ($hasSupabase && in_array('dart', $languages, true)) {
            return 'supabase-dart';
        }
        if ($hasSupabase) {
            return 'supabase-js';
        }

        return null;
    }

    /** Deterministic strategy per provider + callsite category (33B). */
    protected function strategyFor(string $provider, string $category): ?string
    {
        $supabase = [
            'import' => 'convert_imports',
            'client_init' => 'replace_client_init',
            'url' => 'replace_project_url',
            'auth' => 'convert_auth_calls',
            'database' => 'convert_db_from',
            'rpc' => 'convert_rpc_invoke',
            'functions' => 'convert_functions_invoke',
            'storage' => 'convert_storage_from',
            'realtime' => 'convert_channel',
        ];
        $firebase = [
            'import' => 'convert_imports',
            'client_init' => 'replace_initialize_app',
            'url' => 'replace_hosting_url',
            'auth' => 'convert_auth_calls',
            'firestore' => 'convert_firestore_calls',
            'storage' => 'convert_storage_calls',
            'functions' => 'convert_functions_calls',
            'messaging' => 'convert_messaging',
            'admin' => 'convert_admin_sdk',
        ];

        return match ($provider) {
            'supabase' => $supabase[$category] ?? null,
            'firebase' => $firebase[$category] ?? null,
            default => null,
        };
    }
}
