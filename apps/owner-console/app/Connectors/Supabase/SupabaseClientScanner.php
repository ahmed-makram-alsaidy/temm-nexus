<?php

namespace App\Connectors\Supabase;

use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;

/**
 * Phase 27H — Supabase client dependency patterns, extracted verbatim from
 * the Phase 25E scanner into the connector package. The generic scanner
 * merges pattern sets from all connectors implementing the provider
 * contract; with only this provider registered the effective behavior is
 * byte-identical to Phase 25 (27H.2 no-regression).
 */
class SupabaseClientScanner implements ClientScannerProvider
{
    public function scannerLabel(): string
    {
        return 'Supabase';
    }

    public function patternsFor(string $language): array
    {
        $common = [
            ['category' => 'url', 'regex' => '/https:\/\/[a-z0-9\-]+\.supabase\.co/i', 'target' => null],
            ['category' => 'url', 'regex' => '/https:\/\/[a-z0-9\-]+\.supabase\.in/i', 'target' => null],
            ['category' => 'client_init', 'regex' => '/createClient\s*\(|createSupabaseClient\s*\(|Supabase\.initialize\s*\(/', 'target' => null],
            ['category' => 'secret', 'regex' => '/SERVICE_ROLE|service_role|SUPABASE_SERVICE|anon_key\s*[:=]\s*[\'"][A-Za-z0-9_\-\.]{30,}/i', 'target' => null],
        ];

        if ($language === 'dart') {
            return array_merge([
                ['category' => 'auth', 'regex' => '/(?:supabase\.auth|client\.auth)\.(signInWithPassword|signUp|signOut|signInWithOtp|getUser|resetPasswordForEmail)\s*\(/', 'target' => 1],
                ['category' => 'database', 'regex' => '/\.from\s*\(\s*[\'"]([A-Za-z0-9_\.]+)[\'"]\s*\)/', 'target' => 1],
                ['category' => 'rpc', 'regex' => '/\.rpc\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', 'target' => 1],
                ['category' => 'functions', 'regex' => '/\.functions\.invoke\s*\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', 'target' => 1],
                ['category' => 'storage', 'regex' => '/\.storage\.from\s*\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]\s*\)/', 'target' => 1],
                ['category' => 'realtime', 'regex' => '/\.channel\s*\(\s*[\'"]([^\'"]+)[\'"]|\.stream\s*\(\s*executable\s*:/', 'target' => 1],
            ], $common);
        }

        // javascript / typescript / react / php (Phase 25 behavior: the
        // js idiom set applied to every non-dart language).
        return array_merge([
            ['category' => 'auth', 'regex' => '/supabase\.auth\.(signInWithPassword|signUp|signOut|signInWithOtp|getUser|resetPasswordForEmail|getSession)\s*\(/', 'target' => 1],
            ['category' => 'database', 'regex' => '/\.from\s*\(\s*[\'"]([A-Za-z0-9_\.]+)[\'"]\s*\)/', 'target' => 1],
            ['category' => 'rpc', 'regex' => '/\.rpc\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', 'target' => 1],
            ['category' => 'functions', 'regex' => '/\.functions\.invoke\s*\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', 'target' => 1],
            ['category' => 'storage', 'regex' => '/\.storage\.from\s*\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]\s*\)/', 'target' => 1],
            ['category' => 'realtime', 'regex' => '/\.channel\s*\(\s*[\'"]([^\'"]+)[\'"]/', 'target' => 1],
        ], $common);
    }

    /** Provider-specific env markers merged with the scanner's generic base list. */
    public function secretMarkers(): array
    {
        return ['SERVICE_ROLE', 'SUPABASE_SERVICE'];
    }

    public function configDirNames(): array
    {
        return ['supabase'];
    }

    public function hardcodedUrlRiskCode(): string
    {
        return 'hardcoded_supabase_url';
    }
}
