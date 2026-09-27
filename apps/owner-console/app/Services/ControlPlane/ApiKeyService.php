<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Phase 20J project-scoped API keys. Secret material is shown ONCE at
 * creation (via a single-use reveal token), stored only as sha256, never
 * logged. Scopes: read:data, write:data, storage:read, storage:write,
 * functions:invoke.
 */
class ApiKeyService
{
    public const SCOPES = [
        'read:data', 'write:data', 'storage:read', 'storage:write', 'functions:invoke',
    ];

    /** @return array{key:ProjectApiKey,plain:string,reveal:string} */
    public static function create(Project $project, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $name = trim($name);
        abort_unless($name !== '' && mb_strlen($name) <= 120, 422, 'Invalid key name.');
        $scopes = array_values(array_intersect($scopes, self::SCOPES));
        abort_if($scopes === [], 422, 'Select at least one scope.');

        $prefix = 'cp_'.Str::random(8);
        $secret = Str::random(40);
        $key = ProjectApiKey::create([
            'project_id' => $project->id,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $prefix.'.'.$secret),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'created_by' => Auth::id(),
        ]);
        // Single-use reveal token (5 min). Plain secret lives only in cache.
        $reveal = Str::random(32);
        cache()->put("cp_key_reveal:{$key->id}:{$reveal}", $prefix.'.'.$secret, 300);
        AdminAudit::record('APIKEY_CREATED', $project, 'api_key', $key->id, ['name' => $name, 'scopes' => $scopes]);

        return ['key' => $key, 'plain' => $prefix.'.'.$secret, 'reveal' => $reveal];
    }

    public static function revealOnce(int $keyId, string $reveal): ?string
    {
        return cache()->pull("cp_key_reveal:{$keyId}:{$reveal}");
    }

    /** Validate "prefix.secret" against hash + scopes + expiry + revocation. */
    public static function validate(Project $project, string $presented, string $scope): ?ProjectApiKey
    {
        if (! str_contains($presented, '.')) {
            return null;
        }
        [$prefix] = explode('.', $presented, 2);
        $key = ProjectApiKey::query()
            ->where('project_id', $project->id)
            ->where('prefix', $prefix)
            ->first();
        if (! $key || ! $key->isActive()) {
            return null;
        }
        if (! hash_equals($key->key_hash, hash('sha256', $presented))) {
            return null;
        }
        if (! in_array($scope, $key->scopes ?? [], true)) {
            return null;
        }
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key;
    }

    public static function revoke(ProjectApiKey $key): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
        AdminAudit::record('APIKEY_REVOKED', $key->project, 'api_key', $key->id, ['name' => $key->name]);
    }

    /** @return array{key:ProjectApiKey,plain:string,reveal:string} */
    public static function rotate(ProjectApiKey $key): array
    {
        $project = $key->project;
        $name = $key->name;
        $scopes = $key->scopes ?? [];
        self::revoke($key);

        return self::create($project, $name.' (rotated)', $scopes);
    }
}
