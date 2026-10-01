<?php

namespace App\Filament\Support;

use App\Filament\Pages\WorkspaceDetail;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Navigation\NavigationBuilder;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

/**
 * Phase 20C application chrome: design-system stylesheet hook + persistent
 * project-context pill in the topbar.
 *
 * The pill is read-only display (name + environment + stored health state).
 * It never performs health checks per request and never exposes secrets.
 */
class ControlPlaneChrome
{
    /** Bump when public/css/cp.css changes (static-asset cache buster). */
    public const CSS_VERSION = '20.8.0';

    /** Bump when public/css/nexus.css changes (0.4.0 product design system). */
    public const NEXUS_CSS_VERSION = '40.0.0';

    public static function navigation(): bool|NavigationBuilder
    {
        // 0.4.0: the global navigation is always the product navigation. The
        // project journey is an ADDITIONAL surface (see workspaceHook), not a
        // replacement — an operator must always be able to leave a project.
        return ProductNavigation::build();
    }

    public static function workspaceHook(): \Closure
    {
        return function (): string {
            $project = self::currentProject();
            if (! $project) {
                return '';
            }

            return view('filament.projects.subnav', ['project' => $project])->render();
        };
    }

    public static function stylesHook(): \Closure
    {
        return fn (): HtmlString => new HtmlString(
            // nexus.css is loaded AFTER Filament's own sheet so the product's
            // tokens and overrides win. cp.css is retained for the existing
            // project sub-navigation and custom panels.
            '<link rel="stylesheet" href="/css/nexus.css?v='.self::NEXUS_CSS_VERSION.'">'
            .'<link rel="stylesheet" href="/css/cp.css?v='.self::CSS_VERSION.'">'
        );
    }

    /**
     * 0.4.0 §16/§29 — the persistent Nexus AI launcher.
     *
     * Present on every page, and it states the CURRENT context scope so the user
     * can never be confused about what the assistant is looking at. Pure
     * navigation: it performs no AI call and reveals nothing.
     */
    public static function aiLauncherHook(): \Closure
    {
        return function (): HtmlString {
            try {
                if (! \App\Filament\Pages\NexusAi::canAccess()) {
                    return new HtmlString('');
                }

                $label = NexusAi::currentContextLabel();
                $url = NexusAi::pageUrl();

                return new HtmlString(
                    '<a class="nx-ai-launcher" href="'.e($url).'" title="Open Nexus AI — context: '.e($label).'">'
                    .'<span class="nx-ai-launcher__icon" aria-hidden="true">✦</span>'
                    .'<span class="nx-ai-launcher__text">Nexus AI</span>'
                    .'<span class="nx-ai-launcher__scope">'.e($label).'</span>'
                    .'</a>'
                );
            } catch (\Throwable) {
                // Chrome must never break a page render.
                return new HtmlString('');
            }
        };
    }

    public static function projectPillHook(): \Closure
    {
        return function (): HtmlString {
            $project = self::currentProject();

            if (! $project) {
                return new HtmlString('');
            }

            $dot = match ($project->health_status) {
                'healthy' => 'is-healthy',
                'unhealthy' => 'is-danger',
                default => 'is-unknown',
            };

            return new HtmlString(
                '<a class="cp-pill" href="'.e(ProjectResource::getUrl('overview', ['record' => $project])).'" title="Active project workspace">'
                .'<span class="cp-dot '.$dot.'"></span>'
                .'<span class="cp-pill__name">'.e($project->name).'</span>'
                .'<span class="cp-pill__env">'.e((string) ($project->environment ?? 'local')).'</span>'
                .'</a>'
            );
        };
    }

    public static function currentProject(): ?Project
    {
        try {
            $record = request()->route('record');

            if ($record instanceof Project) {
                return $record;
            }

            if (is_numeric($record) || (is_string($record) && $record !== '')) {
                return Project::query()->find($record);
            }
        } catch (\Throwable) {
            // Never break page rendering for a decorative element.
        }

        return null;
    }

    /** Phase 20W: press "/" anywhere (outside inputs) to jump to Search. */
    public static function searchShortcutHook(): \Closure
    {
        return fn (): HtmlString => new HtmlString(
            '<script>document.addEventListener("keydown",function(e){'
            .'if(e.key==="/"&&!/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)'
            .'&&!e.ctrlKey&&!e.metaKey){e.preventDefault();window.location.href="/admin/search";}});</script>'
        );
    }

    /** Press "/" outside an input to jump to Search; plus the AI launcher. */
    public static function bodyEndHook(): \Closure
    {
        return function (): HtmlString {
            $search = self::searchShortcutHook()();
            $launcher = self::aiLauncherHook()();

            return new HtmlString($search->toHtml().$launcher->toHtml());
        };
    }

    /**
     * 0.4.0 Phase C — project context chip in the topbar.
     *
     * The project workspace navigation moved to the END of the sidebar so the
     * global product navigation leads. That removed the always-visible project
     * switcher, so the topbar now carries a compact context chip that answers
     * three questions at a glance: which project am I in, which environment,
     * and how do I get out.
     *
     * Read-only apart from navigation: it lists only projects the user can
     * already reach, and performs no health check.
     */
    public static function projectContextHook(): \Closure
    {
        return function (): HtmlString {
            try {
                $project = self::currentProject();
                if (! $project) {
                    return new HtmlString('');
                }

                // Only projects this user may reach — never an unscoped list.
                $siblings = PlatformAccess::current()
                    ->access()
                    ->accessibleProjects()
                    ->take(50);

                $dot = match ($project->health_status) {
                    'healthy' => 'nx-dot--success',
                    'unhealthy' => 'nx-dot--danger',
                    default => 'nx-dot--neutral',
                };

                $items = '';
                foreach ($siblings as $sibling) {
                    $items .= '<a href="'.e(ProjectResource::getUrl('overview', ['record' => $sibling])).'"'
                        .($sibling->is($project) ? ' aria-current="true"' : '')
                        .'><span>'.e($sibling->name).'</span>'
                        .'<span class="nx-context__env">'.e(strtoupper((string) ($sibling->environment ?? 'local'))).'</span></a>';
                }

                return new HtmlString(
                    '<details class="nx-context">'
                    .'<summary class="nx-context__chip" title="Active project — open to switch">'
                    .'<span class="nx-dot '.$dot.'" aria-hidden="true"></span>'
                    .'<span class="nx-context__name">'.e($project->name).'</span>'
                    .'<span class="nx-context__env">'.e(strtoupper((string) ($project->environment ?? 'local'))).'</span>'
                    .'</summary>'
                    .'<nav class="nx-context__menu" aria-label="Switch project">'
                    .$items
                    .'<div class="nx-context__footer">'
                    .'<a href="'.e(ProjectResource::getUrl('index')).'"><span>All projects</span><span aria-hidden="true">→</span></a>'
                    .($project->workspace
                        ? '<a href="'.e(WorkspaceDetail::urlFor($project->workspace)).'"><span>'.e($project->workspace->name).' workspace</span><span aria-hidden="true">→</span></a>'
                        : '')
                    .'</div>'
                    .'</nav>'
                    .'</details>'
                );
            } catch (\Throwable) {
                // Chrome must never break a page render.
                return new HtmlString('');
            }
        };
    }

    public static function hooks(): array
    {
        return [
            PanelsRenderHook::HEAD_END => self::stylesHook(),
            PanelsRenderHook::TOPBAR_END => self::projectPillHook(),
            PanelsRenderHook::BODY_END => self::bodyEndHook(),
            PanelsRenderHook::SIDEBAR_NAV_START => self::workspaceHook(),
        ];
    }
}
