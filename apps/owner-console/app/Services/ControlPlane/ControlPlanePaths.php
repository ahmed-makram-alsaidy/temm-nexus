<?php

namespace App\Services\ControlPlane;

/**
 * Host paths shared by control-plane services. The repo checkout is bind-mounted
 * into this container at /projects and /backups; the Laravel app lives at /var/www/html.
 */
class ControlPlanePaths
{
    public static function repoRoot(): string
    {
        return (string) (env('CONTROL_PLANE_REPO_ROOT') ?: '/');
    }

    public static function projectDir(string $slug): string
    {
        abort_if(str_contains($slug, '..') || str_contains($slug, '/') || str_contains($slug, "\0"), 422, 'Invalid project slug.');

        return self::repoRoot().'/projects/'.$slug;
    }
}
