<?php

namespace App\Filament\Support;

use App\Models\Project;
use App\Models\Workspace;

/**
 * 0.4.0 §15/§16 — Nexus AI entry points.
 *
 * The assistant is reachable from anywhere. Opening it always resolves a
 * context from the ROUTE, so the panel can state its scope honestly:
 *
 *   opened on platform Home        -> Context: Platform
 *   opened inside a workspace      -> Context: Workspace — Nayrouz
 *   opened inside a project        -> Context: Project — Wasla
 *
 * This class only builds URLs; the scope itself is derived server-side by
 * `AiContext::fromRequest()` so a crafted link cannot widen it.
 */
final class NexusAi
{
    public static function pageUrl(): string
    {
        return \App\Filament\Pages\NexusAi::getUrl();
    }

    /** Link to the assistant for a project context. */
    public static function urlFor(?Project $project): string
    {
        if ($project === null) {
            return self::pageUrl();
        }

        // The existing per-project copilot page carries the real project
        // context; the global page accepts a scope hint for deep links.
        return self::pageUrl().'?'.http_build_query([
            'scope' => 'project',
            'project' => $project->getKey(),
        ]);
    }

    /** Link to the assistant for a workspace context. */
    public static function urlForWorkspace(?Workspace $workspace): string
    {
        if ($workspace === null) {
            return self::pageUrl();
        }

        return self::pageUrl().'?'.http_build_query([
            'scope' => 'workspace',
            'workspace' => $workspace->getRouteKey(),
        ]);
    }

    /**
     * The scope chip shown in the persistent launcher, derived from the route
     * the user is currently on.
     */
    public static function currentContextLabel(): string
    {
        $project = ControlPlaneChrome::currentProject();
        if ($project) {
            return 'Project — '.$project->name;
        }

        $workspace = request()->route()?->parameter('workspaceSlug')
            ?? request()->route()?->parameter('workspace');
        if ($workspace instanceof Workspace) {
            return 'Workspace — '.$workspace->name;
        }

        if (is_string($workspace) && $workspace !== '') {
            $found = Workspace::query()->where('slug', $workspace)->first();
            if ($found) {
                return 'Workspace — '.$found->name;
            }
        }

        return 'Platform';
    }
}
