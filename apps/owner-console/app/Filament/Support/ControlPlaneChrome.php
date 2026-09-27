<?php

namespace App\Filament\Support;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
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

    public static function navigation(): bool|\Filament\Navigation\NavigationBuilder
    {
        if (! self::currentProject()) {
            return true;
        }

        return new \Filament\Navigation\NavigationBuilder;
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
            '<link rel="stylesheet" href="/css/cp.css?v='.self::CSS_VERSION.'">'
        );
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

    public static function hooks(): array
    {
        return [
            PanelsRenderHook::HEAD_END => self::stylesHook(),
            PanelsRenderHook::TOPBAR_END => self::projectPillHook(),
            PanelsRenderHook::BODY_END => self::searchShortcutHook(),
            PanelsRenderHook::SIDEBAR_NAV_START => self::workspaceHook(),
        ];
    }
}
