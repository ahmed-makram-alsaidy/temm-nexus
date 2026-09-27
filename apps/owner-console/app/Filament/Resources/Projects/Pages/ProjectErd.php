<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\CpAccess;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 21A: professional interactive PostgreSQL ERD / schema visualizer.
 *
 * The graph is fetched live from pg_catalog via the read-only
 * /cp-erd/{project} JSON backend (ProjectErdController + ErdService) — the
 * page itself renders no schema data server-side, so the canvas always
 * reflects the real database. Visual engine is dependency-free SVG +
 * vanilla JS (public/js/cp-erd.js): no React/Cytoscape toolchain, no SPA
 * rewrite, no fight with Livewire DOM diffing.
 */
class ProjectErd extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public const ASSET_VERSION = '21.0.0';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'ERD';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'ERD'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('erd'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        $config = [
            'api' => url('/cp-erd/'.$p->id),
            'schema' => (string) (request()->query('schema', 'public') ?: 'public'),
            'recordsUrl' => static::projectUrl($p, 'records', ['table' => '__T__']),
            'schemaUrl' => static::projectUrl($p, 'table-schema', ['table' => '__T__']),
        ];

        return $schema->components([
            Html::make(
                '<link rel="stylesheet" href="/css/cp-erd.css?v='.self::ASSET_VERSION.'">'
                .'<div id="cp-erd" data-config="'.e(json_encode($config)).'">'
                .'<div class="cp-erd__toolbar" role="toolbar" aria-label="ERD controls">'
                .'<label>Schema: <select id="cp-erd-schema" aria-label="Schema"><option value="public">public</option></select></label>'
                .'<input type="search" id="cp-erd-search" placeholder="Search tables…" aria-label="Search tables">'
                .'<input type="text" id="cp-erd-relfilter" placeholder="Filter relationships…" aria-label="Filter relationships" style="min-width:9rem">'
                .'<button class="cp-btn" id="cp-erd-fit" type="button">Fit</button>'
                .'<button class="cp-btn" id="cp-erd-reset" type="button">Reset layout</button>'
                .'<button class="cp-btn" id="cp-erd-auto" type="button">Auto layout</button>'
                .'<button class="cp-btn" id="cp-erd-zoomin" type="button" aria-label="Zoom in">+</button>'
                .'<button class="cp-btn" id="cp-erd-zoomout" type="button" aria-label="Zoom out">−</button>'
                .'<button class="cp-btn" id="cp-erd-svg-btn" type="button">Export SVG</button>'
                .'<button class="cp-btn" id="cp-erd-png-btn" type="button">Export PNG</button>'
                .'<span class="cp-erd__spacer"></span>'
                .'<span class="cp-erd__meta" id="cp-erd-meta"></span>'
                .'</div>'
                .'<div class="cp-erd__toolbar cp-erd__toggles">'
                .'<label><input type="checkbox" id="cp-erd-isolated"> Hide isolated tables</label>'
                .'<label><input type="checkbox" id="cp-erd-related"> Related tables only</label>'
                .'<label><input type="checkbox" id="cp-erd-collapse"> Collapse columns</label>'
                .'<button class="cp-btn" id="cp-erd-clear-focus" type="button">Clear focus</button>'
                .'<span style="margin-left:auto">drag to move · scroll to zoom · drag background to pan · double-click table for Schema</span>'
                .'</div>'
                .'<div class="cp-erd__banner" id="cp-erd-banner" style="display:none"></div>'
                .'<div class="cp-erd__body">'
                .'<div class="cp-erd__canvas" id="cp-erd-canvas">'
                .'<svg id="cp-erd-svg" role="img" aria-label="Entity relationship diagram">'
                .'<defs><marker id="cp-erd-arrow" markerWidth="9" markerHeight="9" refX="7" refY="4.5" orient="auto">'
                .'<path d="M0,0 L9,4.5 L0,9" fill="none" stroke="#818cf8" stroke-width="1.5"/></marker></defs>'
                .'<g id="cp-erd-viewport"></g></svg>'
                .'<div class="cp-erd__tip" id="cp-erd-tip"></div>'
                .'<div class="cp-erd__empty" id="cp-erd-empty">Loading schema…</div>'
                .'</div>'
                .'<aside class="cp-erd__detail" id="cp-erd-detail" aria-live="polite"></aside>'
                .'</div></div>'
                .'<script src="/js/cp-erd.js?v='.self::ASSET_VERSION.'"></script>'
            ),
        ]);
    }
}
