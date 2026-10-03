<?php

namespace App\Filament\Support;

use App\Filament\Pages\WorkspaceDetail;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\Localization\LocaleManager;
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
    public const CSS_VERSION = '41.0.0';

    /** Bump when public/css/nexus.css changes (0.4.0 product design system). */
    public const NEXUS_CSS_VERSION = '41.0.0';

    /** Bump when public/js/nexus-inspect.js changes (0.4.0 Phase I). */
    public const INSPECT_JS_VERSION = '40.1.0';

    /**
     * 0.4.0 Phase I — the Inspect Mode toggle.
     *
     * Rendered only for someone who can actually use the assistant, so the
     * control never appears where it would do nothing. The button is inert on
     * its own: it toggles a client class, and the SELECTION it produces is
     * validated server-side against `ComponentRegistry` before it can reach a
     * model. The client never sends a component description.
     */
    public static function inspectToggleHook(): \Closure
    {
        return function (): HtmlString {
            try {
                if (! \App\Filament\Pages\NexusAi::canAccess()) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    '<button type="button" class="nx-inspect-toggle" data-nx-inspect-toggle'
                    .' aria-pressed="false"'
                    .' data-nx-inspect-selected-format="'.e(__('chrome.inspect_selected_format')).'"'
                    .' data-nx-inspect-fallback="'.e(__('chrome.inspect_fallback_component')).'"'
                    .' title="'.e(__('chrome.inspect_title')).'">'
                    .'<span class="nx-inspect-toggle__glyph" aria-hidden="true">'
                    // Inline eye glyph: no icon-component dependency in the shell.
                    .'<svg viewBox="0 0 20 20" fill="currentColor" width="15" height="15" aria-hidden="true">'
                    .'<path d="M10 4c-3.6 0-6.6 2.3-8 6 1.4 3.7 4.4 6 8 6s6.6-2.3 8-6c-1.4-3.7-4.4-6-8-6Zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/>'
                    .'</svg></span>'
                    .'<span class="nx-inspect-toggle__text">'.e(__('chrome.inspect')).'</span>'
                    .'</button>'
                );
            } catch (\Throwable) {
                // Chrome must never break a page render.
                return new HtmlString('');
            }
        };
    }

    /** The Inspect Mode client script. */
    public static function inspectScriptHook(): \Closure
    {
        return fn (): HtmlString => new HtmlString(
            '<script src="/js/nexus-inspect.js?v='.self::INSPECT_JS_VERSION.'" defer></script>'
        );
    }

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

                // The launcher LINKS to the context it displays. Opening the
                // assistant inside a project must arrive at project scope,
                // which is also what makes a selected project component
                // attachable (Inspect Mode, Phase I). A deep link can only
                // NARROW scope: the page re-checks reachability server-side.
                $project = self::currentProject();
                $url = $project instanceof Project
                    ? NexusAi::urlFor($project)
                    : NexusAi::pageUrl();

                return new HtmlString(
                    '<a class="nx-ai-launcher" href="'.e($url).'" title="'.e(__('chrome.open_nexus_ai', ['context' => $label])).'">'
                    .'<span class="nx-ai-launcher__icon" aria-hidden="true">✦</span>'
                    .'<span class="nx-ai-launcher__text">'.e(__('chrome.nexus_ai')).'</span>'
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
                '<a class="cp-pill" href="'.e(ProjectResource::getUrl('overview', ['record' => $project])).'" title="'.e(__('chrome.active_project_workspace')).'">'
                .'<span class="cp-dot '.$dot.'"></span>'
                .'<span class="cp-pill__name">'.e($project->name).'</span>'
                .'<span class="cp-pill__env">'.e((string) ($project->environment ?? 'local')).'</span>'
                .'</a>'
            );
        };
    }

    /**
     * 0.4.0-rc.5 (Phase 41) — the visible language selector.
     *
     * Plain POST forms (no Livewire) so it works on EVERY surface the panel
     * renders, including the login screen. Persisting is server-side
     * (LocaleController): session + cookie, and the user's preference when
     * authenticated.
     */
    public static function localeSwitcherHtml(bool $compact = false): string
    {
        try {
            $current = app()->getLocale();
            $items = '';

            foreach (LocaleManager::available() as $code => $label) {
                $active = $code === $current;
                $items .= '<form method="POST" action="'.e(route('locale.update')).'" class="nx-locale__form">'
                    .'<input type="hidden" name="_token" value="'.e(csrf_token()).'">'
                    .'<input type="hidden" name="locale" value="'.e($code).'">'
                    .'<button type="submit" class="nx-locale__option'.($active ? ' is-active' : '').'"'
                    .' lang="'.e($code).'" dir="'.LocaleManager::direction($code).'"'
                    .($active ? ' aria-current="true"' : '').'>'.e($label).'</button>'
                    .'</form>';
            }

            return '<div class="nx-locale'.($compact ? ' nx-locale--compact' : '').'"'
                .' title="'.e(__('chrome.language')).'" aria-label="'.e(__('chrome.language')).'">'
                .$items.'</div>';
        } catch (\Throwable) {
            // Chrome must never break a page render.
            return '';
        }
    }

    /** Language switcher for the panel topbar (authenticated pages). */
    public static function localeSwitcherHook(): \Closure
    {
        return fn (): HtmlString => new HtmlString(self::localeSwitcherHtml());
    }

    /** Floating switcher for guest surfaces (login screen has no topbar). */
    public static function guestLocaleSwitcherHtml(): string
    {
        try {
            if (auth()->check()) {
                return '';
            }
        } catch (\Throwable) {
            return '';
        }

        return self::localeSwitcherHtml(compact: true);
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
            $inspect = self::inspectToggleHook()();
            $script = self::inspectScriptHook()();

            // rc.2: the launcher and the Inspect toggle live in ONE floating
            // container (flex row) so they can never overlap, whatever the
            // scope label's length — previously each control was absolutely
            // positioned and the launcher grew leftward under the toggle.
            // rc.5: guests (login/setup) also get the language switcher here,
            // floating where the topbar chip would normally live.
            return new HtmlString(
                self::guestLocaleSwitcherHtml()
                .'<div class="nx-floating-controls">'
                .$launcher->toHtml().$inspect->toHtml()
                .'</div>'
                .$search->toHtml().$script->toHtml()
            );
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
                    .'<summary class="nx-context__chip" title="'.e(__('chrome.active_project_open_to_switch')).'">'
                    .'<span class="nx-dot '.$dot.'" aria-hidden="true"></span>'
                    .'<span class="nx-context__name">'.e($project->name).'</span>'
                    .'<span class="nx-context__env">'.e(strtoupper((string) ($project->environment ?? 'local'))).'</span>'
                    .'</summary>'
                    .'<nav class="nx-context__menu" aria-label="'.e(__('chrome.switch_project')).'">'
                    .$items
                    .'<div class="nx-context__footer">'
                    .'<a href="'.e(ProjectResource::getUrl('index')).'"><span>'.e(__('chrome.all_projects')).'</span><span aria-hidden="true">→</span></a>'
                    .($project->workspace
                        ? '<a href="'.e(WorkspaceDetail::urlFor($project->workspace)).'"><span>'.e($project->workspace->name.' '.__('chrome.workspace_suffix')).'</span><span aria-hidden="true">→</span></a>'
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
