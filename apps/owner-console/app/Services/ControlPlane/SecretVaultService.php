<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Support\Str;

/**
 * Phase 24F — Secrets Vault built on the Phase 20M encrypted store.
 *
 * Adds environment/category scoping, versioned rotation tracking, one-time
 * reveal with audit, masking, and a leak scan. Values are encrypted at rest
 * (APP_KEY), never returned in listings, never logged, never audited.
 */
class SecretVaultService
{
    /** Create (or supersede) a vault secret. */
    public static function createSecret(Project $project, string $name, string $value, array $options = []): ProjectSecret
    {
        SecretService::validateName($name);
        if (! in_array($options['category'] ?? 'application', ProjectSecret::CATEGORIES, true)) {
            abort(422, 'Unknown secret category');
        }

        $secret = ProjectSecret::updateOrCreate(
            ['project_id' => $project->id, 'name' => $name],
            [
                'value' => $value,
                'description' => $options['description'] ?? null,
                'environment_id' => $options['environment_id'] ?? null,
                'category' => $options['category'] ?? 'application',
                'version' => 1,
                'status' => 'active',
                'last_rotated_at' => now(),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]
        );
        if (array_key_exists('env', $options) && $options['env'] !== null) {
            $secret->update(['env' => $options['env']]);
        }
        AdminAudit::record('SECRET_CREATED', $project, 'secret', $secret->id, [
            'name' => $name, 'category' => $secret->category, 'environment_id' => $secret->environment_id,
        ]);

        return $secret;
    }

    /** Rotation workflow: old version tracked, value replaced, version bumped. */
    public static function rotate(ProjectSecret $secret, string $newValue): ProjectSecret
    {
        $meta = $secret->rotation_meta ?? [];
        $meta['previous_version'] = $secret->version;
        $meta['previous_rotated_at'] = $secret->last_rotated_at?->toIso8601String();

        $secret->update([
            'value' => $newValue,
            'version' => $secret->version + 1,
            'rotation_meta' => $meta,
            'last_rotated_at' => now(),
            'updated_by' => auth()->id(),
        ]);
        AdminAudit::record('SECRET_ROTATED', $secret->project, 'secret', $secret->id, [
            'name' => $secret->name, 'version' => $secret->version,
        ]);

        return $secret;
    }

    /**
     * One-time reveal for authorized operators (24F.2). Every reveal is
     * audited; the UI flow is explicit and value never lands in listings.
     */
    public static function reveal(ProjectSecret $secret): string
    {
        CpAccess::require(auth()->user(), 'secrets.manage');
        AdminAudit::record('SECRET_REVEALED', $secret->project, 'secret', $secret->id, ['name' => $secret->name]);

        return $secret->value;
    }

    /** Safe prefix/suffix mask (24F.4) — 4 chars each side max. */
    public static function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '********';
        }
        if (strlen($value) <= 8) {
            return '********';
        }

        return substr($value, 0, 4).'…'.substr($value, -4);
    }

    /** Listing shape — never contains the value. */
    public static function listingRow(ProjectSecret $secret): array
    {
        return [
            'id' => $secret->id,
            'name' => $secret->name,
            'category' => $secret->category,
            'environment_id' => $secret->environment_id,
            'version' => $secret->version,
            'status' => $secret->status,
            'last_rotated_at' => $secret->last_rotated_at?->toIso8601String(),
        ];
    }

    /**
     * Leak scan (24F.7): look for raw secret values in owner-console logs,
     * audit log metadata, and the most recent HTTP responses captured in
     * storage logs. Returns findings without echoing values.
     */
    public static function leakScan(Project $project): array
    {
        $secrets = ProjectSecret::where('project_id', $project->id)->get();
        $needles = [];
        foreach ($secrets as $secret) {
            $value = $secret->value;
            if (is_string($value) && strlen($value) >= 6) {
                $needles[$secret->name] = $value;
            }
        }
        $findings = [];
        if ($needles === []) {
            return ['scanned' => 0, 'leaks' => 0, 'findings' => []];
        }

        // Owner console logs.
        foreach (glob(storage_path('logs/*.log')) ?: [] as $logFile) {
            $content = @file_get_contents($logFile) ?: '';
            foreach ($needles as $name => $value) {
                if (str_contains($content, $value)) {
                    $findings[] = ['where' => basename($logFile), 'secret' => $name];
                }
            }
        }

        // Audit log metadata.
        foreach (\App\Models\AdminAuditEntry::where('project_id', $project->id)->latest('id')->limit(500)->get() as $entry) {
            $encoded = json_encode($entry->metadata ?? []);
            foreach ($needles as $name => $value) {
                if ($encoded && str_contains($encoded, $value)) {
                    $findings[] = ['where' => 'audit_log#'.$entry->id, 'secret' => $name];
                }
            }
        }

        return ['scanned' => count($needles), 'leaks' => count($findings), 'findings' => $findings];
    }
}
