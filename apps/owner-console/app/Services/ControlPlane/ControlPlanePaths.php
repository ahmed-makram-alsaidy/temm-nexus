<?php

namespace App\Services\ControlPlane;

/**
 * Host paths shared by control-plane services. The repo checkout is bind-mounted
 * into this container at /projects and /backups; the Laravel app lives at /var/www/html.
 */
class ControlPlanePaths
{
    /**
     * Without CONTROL_PLANE_REPO_ROOT, per-project folders (Storage Studio,
     * project logs) live in the app's persistent storage. The old fallback "/"
     * is not writable inside the container: Storage Studio answered HTTP 500
     * (mkdir(): Permission denied) for every local-storage project.
     */
    public static function repoRoot(): string
    {
        return (string) (env('CONTROL_PLANE_REPO_ROOT') ?: storage_path('app/control-plane'));
    }

    public static function projectDir(string $slug): string
    {
        abort_if(str_contains($slug, '..') || str_contains($slug, '/') || str_contains($slug, "\0"), 422, 'Invalid project slug.');

        return self::repoRoot().'/projects/'.$slug;
    }
}
