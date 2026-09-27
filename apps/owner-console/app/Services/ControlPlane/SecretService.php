<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 20M project-scoped Secrets Vault. Values are encrypted at rest
 * (Laravel Crypt, key = APP_KEY from server env — never in the DB) and are
 * NEVER returned to list views, logs, or audit metadata. Consumption happens
 * server-side only (Server Functions runtime, scheduled tasks).
 */
class SecretService
{
    public static function validateName(string $name): string
    {
        $name = strtoupper(trim($name));
        abort_unless(preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $name) === 1, 422, 'Secret names look like ENV_VARS.');

        return $name;
    }

    public static function create(Project $project, string $name, string $value, ?string $description = null): ProjectSecret
    {
        $name = self::validateName($name);
        abort_if(trim($value) === '', 422, 'Secret value is required.');
        abort_if(mb_strlen($value) > 8000, 422, 'Secret too large (8 kB max).');
        $secret = ProjectSecret::updateOrCreate(
            ['project_id' => $project->id, 'name' => $name],
            ['value' => $value, 'description' => $description, 'last_rotated_at' => now()]
        );
        AdminAudit::record('SECRET_CREATED', $project, 'secret', $secret->id, ['name' => $name]);

        return $secret;
    }

    public static function rotate(ProjectSecret $secret, string $value): void
    {
        abort_if(trim($value) === '', 422, 'Secret value is required.');
        $secret->forceFill(['value' => $value, 'last_rotated_at' => now()])->save();
        AdminAudit::record('SECRET_ROTATED', $secret->project, 'secret', $secret->id, ['name' => $secret->name]);
    }

    public static function delete(ProjectSecret $secret): void
    {
        $project = $secret->project;
        $name = $secret->name;
        $secret->delete();
        AdminAudit::record('SECRET_DELETED', $project, 'secret', null, ['name' => $name]);
    }

    /** Server-side consumption only. Returns name => plain for KNOWN names. */
    public static function valuesFor(Project $project, array $names): array
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        if ($names === []) {
            return [];
        }
        $out = [];
        foreach (ProjectSecret::query()->where('project_id', $project->id)->whereIn('name', $names)->get() as $s) {
            $out[$s->name] = $s->value; // decrypted via cast, server memory only
        }

        return $out;
    }

    /** Redact known secret values from text destined for logs/UI. */
    public static function redact(Project $project, ?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        foreach (self::valuesFor($project, self::namesFor($project)) as $value) {
            if (is_string($value) && mb_strlen($value) >= 4) {
                $text = str_replace($value, '***', $text);
            }
        }

        return $text;
    }

    /** @return list<string> */
    public static function namesFor(Project $project): array
    {
        return ProjectSecret::query()->where('project_id', $project->id)->pluck('name')->all();
    }
}
