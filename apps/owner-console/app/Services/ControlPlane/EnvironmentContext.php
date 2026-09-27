<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Support\Facades\Session;

/**
 * Phase 24C.3 — active-environment context for the project workspace.
 *
 * The switcher stores the chosen environment id per project in the session;
 * environment-scoped modules (Secrets, Backups, Resources, Readiness,
 * Migration Center, Connect) resolve through here and must NEVER show stale
 * data from a previously selected environment. Falls back to the project's
 * default environment when nothing was chosen yet.
 */
class EnvironmentContext
{
    public static function sessionKey(Project $project): string
    {
        return 'cp_env_'.$project->id;
    }

    public static function active(Project $project): ProjectEnvironment
    {
        $id = Session::get(self::sessionKey($project));
        if ($id) {
            $env = $project->environments()->whereKey($id)->first();
            if ($env) {
                return $env;
            }
            Session::forget(self::sessionKey($project));
        }

        return EnvironmentService::defaultFor($project);
    }

    public static function switch(Project $project, int $environmentId): bool
    {
        $env = $project->environments()->whereKey($environmentId)->first();
        if (! $env || $env->status !== 'active') {
            return false;
        }
        Session::put(self::sessionKey($project), $env->id);
        AdminAudit::record('ENVIRONMENT_SWITCHED', $project, 'environment', $env->id, [
            'slug' => $env->slug,
        ]);

        return true;
    }
}
