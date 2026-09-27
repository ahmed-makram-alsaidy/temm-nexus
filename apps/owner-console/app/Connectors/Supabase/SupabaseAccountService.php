<?php

namespace App\Connectors\Supabase;

use App\Models\ExternalAccountConnection;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\SecretVaultService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 25A — Supabase account connector.
 *
 * The Personal Access Token is stored ONLY in the encrypted vault; the
 * connection model holds the secret NAME. Discovery uses the Supabase
 * management API (https://api.supabase.com) for account/project metadata.
 * Management-level discovery cannot read database passwords — the operator
 * supplies DB credentials separately (25C) when needed.
 */
class SupabaseAccountService
{
    public const MANAGEMENT_API = 'https://api.supabase.com';

    /**
     * Effective management API base URL. Operators may point this at a
     * LOCAL/Supabase-compatible stack via SUPABASE_MANAGEMENT_API_URL
     * (26.1B); the default is always the real Supabase management API.
     * Guard: in production, a plain-HTTP override is only allowed for
     * loopback/private/docker-internal hosts (single-label names like
     * "app", localhost, or private IP literals) — public hostnames must
     * use HTTPS so the PAT never crosses the public internet in cleartext.
     */
    public static function managementApi(): string
    {
        $override = trim((string) env('SUPABASE_MANAGEMENT_API_URL', ''));
        if ($override === '') {
            return self::MANAGEMENT_API;
        }
        if (! app()->environment('production')) {
            return rtrim($override, '/');
        }

        $host = (string) (parse_url($override, PHP_URL_HOST) ?: '');
        $privateHttp = $host === ''
            || ! str_contains($host, '.')               // docker service name ("app")
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if (! str_starts_with($override, 'https://') && ! $privateHttp) {
            abort(422, 'SUPABASE_MANAGEMENT_API_URL must use HTTPS for public hosts in production.');
        }

        return rtrim($override, '/');
    }

    public const RESULTS = ['PASS', 'INVALID_TOKEN', 'INSUFFICIENT_SCOPE', 'NETWORK_ERROR', 'RATE_LIMITED', 'UNKNOWN_ERROR'];

    /** Store the PAT encrypted-at-rest and register the connection (25A.2). */
    public static function connect($user, string $displayName, string $pat): ExternalAccountConnection
    {
        $connection = ExternalAccountConnection::create([
            'provider' => 'supabase',
            'display_name' => $displayName,
            'owner_user_id' => $user->id,
            'secret_encrypted' => $pat, // 'encrypted' cast — never stored or returned in plaintext
            'status' => 'unverified',
        ]);
        AdminAudit::record('SUPABASE_ACCOUNT_CONNECTED', null, 'external_account_connection', $connection->id, [
            'display_name' => $displayName,
        ]);

        return $connection;
    }

    public static function delete(ExternalAccountConnection $connection): void
    {
        $connection->delete();
        AdminAudit::record('SUPABASE_ACCOUNT_DELETED', null, 'external_account_connection', $connection->id);
    }

    /** PAT value — server-side only, encrypted at rest, decrypted on demand. */
    public static function pat(ExternalAccountConnection $connection): string
    {
        return (string) ($connection->secret_encrypted ?? '');
    }

    /** 25A.3 — classify a connection test against the management API. */
    public static function testConnection(ExternalAccountConnection $connection): array
    {
        $result = self::RESULT_PASS;
        $detail = '';
        try {
            $response = Http::timeout(15)
                ->withToken(self::pat($connection))
                ->accept('application/json')
                ->get(self::managementApi().'/v1/projects');
            $result = match (true) {
                $response->status() === 200 => self::RESULT_PASS,
                $response->status() === 401 => self::RESULT_INVALID_TOKEN,
                $response->status() === 403 => self::RESULT_INSUFFICIENT_SCOPE,
                $response->status() === 429 => self::RESULT_RATE_LIMITED,
                $response->status() >= 500 => self::RESULT_PROVIDER_ERROR,
                default => self::RESULT_UNKNOWN_ERROR,
            };
            if ($result !== self::RESULT_PASS) {
                $detail = 'HTTP '.$response->status();
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $result = self::RESULT_NETWORK_ERROR;
            $detail = Str::limit($e->getMessage(), 120);
        } catch (\Throwable $e) {
            $result = self::RESULT_UNKNOWN_ERROR;
            $detail = Str::limit($e->getMessage(), 120);
        }

        $connection->update([
            'status' => $result === self::RESULT_PASS ? 'connected' : 'error',
            'last_result' => $result,
            'last_verified_at' => $result === self::RESULT_PASS ? now() : $connection->last_verified_at,
        ]);
        AdminAudit::record('SUPABASE_CONNECTION_TESTED', null, 'external_account_connection', $connection->id, [
            'result' => $result,
        ]);

        return ['result' => $result, 'detail' => $detail];
    }

    public const RESULT_PASS = 'PASS';
    public const RESULT_INVALID_TOKEN = 'INVALID_TOKEN';
    public const RESULT_INSUFFICIENT_SCOPE = 'INSUFFICIENT_SCOPE';
    public const RESULT_NETWORK_ERROR = 'NETWORK_ERROR';
    public const RESULT_RATE_LIMITED = 'RATE_LIMITED';
    public const RESULT_PROVIDER_ERROR = 'PROVIDER_ERROR';
    public const RESULT_UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    /**
     * 25A.4 — discover available Supabase projects. Only safe metadata is
     * returned; unavailable fields stay null (never faked).
     */
    public static function discoverProjects(ExternalAccountConnection $connection): array
    {
        $response = Http::timeout(20)
            ->withToken(self::pat($connection))
            ->accept('application/json')
            ->get(self::managementApi().'/v1/projects');
        if ($response->status() !== 200) {
            abort(502, 'Supabase discovery failed (HTTP '.$response->status().')');
        }
        $projects = [];
        foreach ((array) $response->json() as $project) {
            $projects[] = [
                'ref' => $project['id'] ?? null,
                'name' => $project['name'] ?? '(unnamed)',
                'organization' => $project['organization_id'] ?? null,
                'region' => $project['region'] ?? null,
                'status' => $project['status'] ?? null,
            ];
        }

        return $projects;
    }

    /**
     * 25B — select a discovered project: create/prepare the platform project's
     * migration source with management metadata. No migration is executed.
     */
    public static function selectProject(Project $project, ExternalAccountConnection $connection, array $discovered, ?int $environmentId = null): \App\Models\MigrationSource
    {
        $source = \App\Models\MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'external_account_connection_id' => $connection->id,
            'connector_instance_id' => (string) $connection->id, // 27I.1 — connector-scoped instance reference
            'type' => 'supabase',
            'display_name' => $discovered['name'] ?? $discovered['ref'] ?? 'Supabase project',
            'source_ref' => $discovered['ref'] ?? null,
            'management_project_ref' => $discovered['ref'] ?? null,
            'region' => $discovered['region'] ?? null,
            'organization' => $discovered['organization'] ?? null,
            'connection' => array_filter([
                'project_ref' => $discovered['ref'] ?? null,
                'api_url' => ($discovered['ref'] ?? null) ? 'https://'.($discovered['ref']).'.supabase.co' : null,
                'region' => $discovered['region'] ?? null,
            ]),
            'secret_refs' => [],
            'read_only' => true,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
        AdminAudit::record('SUPABASE_PROJECT_SELECTED', $project, 'migration_source', $source->id, [
            'ref' => $discovered['ref'] ?? null,
        ]);

        return $source;
    }
}
