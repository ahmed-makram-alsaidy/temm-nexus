<?php

namespace App\Filament\Pages;

use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\InfrastructureTopologyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Phase 21B: visual infrastructure/service topology (NOT the database ERD).
 * Automatic layered layout by role; click a node for its detail panel.
 * Server-rendered SVG — no graph library needed at this scale.
 */
class InfraTopology extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Topology';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 73;

    protected static ?string $slug = 'infra-topology';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.infrastructure_topology');
    }

    public function getBreadcrumbs(): array
    {
        return ['Infrastructure', 'Topology'];
    }

    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            \Filament\Schemas\Components\EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $topo = InfrastructureTopologyService::topology();

        return $schema->components([
            Section::make('Service topology')->schema([
                Html::make($this->svg($topo)
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.count($topo['nodes']).' nodes · '
                    .count($topo['edges']).' serving relationships. Click a node for detail.</p>'),
            ]),
        ]);
    }

    /** @param array{nodes:list<array{id:int,name:string,roles:list<string>,status:string,layer:int}>,edges:list<array{from:string,to:string,via:string}>} $topo */
    protected function svg(array $topo): string
    {
        $w = 230;
        $gapX = 280;
        $gapY = 26;
        $byLayer = [];
        foreach ($topo['nodes'] as $n) {
            $byLayer[$n['layer']][] = $n;
        }
        ksort($byLayer);
        $pos = [];
        $maxRows = 1;
        foreach ($byLayer as $layer => $nodes) {
            $maxRows = max($maxRows, count($nodes));
            foreach (array_values($nodes) as $i => $n) {
                $pos[$n['name']] = ['x' => 10 + $layer * $gapX, 'y' => 10 + $i * (92 + $gapY)];
            }
        }
        // Width spans the highest layer index (layers may be sparse).
        $maxLayer = $byLayer === [] ? 0 : max(array_keys($byLayer));
        $viewW = 10 + ($maxLayer + 1) * $gapX;
        $viewH = 10 + $maxRows * (92 + $gapY);

        $color = fn (string $s) => $s === 'healthy' ? '#34d399' : ($s === 'unknown' ? '#fbbf24' : '#f87171');
        $svg = '<svg viewBox="0 0 '.$viewW.' '.$viewH.'" style="width:100%;max-width:'.$viewW.'px;height:auto;background:var(--cp-surface);border:1px solid var(--cp-border);border-radius:.625rem" role="img" aria-label="Infrastructure topology">';
        $svg .= '<defs><marker id="cp-topo-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8" fill="none" stroke="#818cf8" stroke-width="1.5"/></marker></defs>';
        foreach ($topo['edges'] as $e) {
            if (! isset($pos[$e['from']], $pos[$e['to']])) {
                continue;
            }
            $a = $pos[$e['from']];
            $b = $pos[$e['to']];
            $svg .= '<line x1="'.($a['x'] + $w).'" y1="'.($a['y'] + 46).'" x2="'.$b['x'].'" y2="'.($b['y'] + 46)
                .'" stroke="#818cf8" stroke-width="1.2" marker-end="url(#cp-topo-arrow)" opacity="0.7">'
                .'<title>'.e($e['from'].' → '.$e['to'].' ('.$e['via'].')').'</title></line>';
        }
        foreach ($pos as $name => $p) {
            $node = null;
            foreach ($topo['nodes'] as $n) {
                if ($n['name'] === $name) {
                    $node = $n;
                    break;
                }
            }
            // Theme-adaptive fills via CSS vars (inline style, not presentation
            // attributes, so var() resolves against the page theme).
            $url = e(InfraNodeDetail::getUrl(['node' => $name]));
            $svg .= '<a href="'.$url.'"><g>'
                .'<rect x="'.$p['x'].'" y="'.$p['y'].'" width="'.$w.'" height="92" rx="8" style="fill:var(--cp-surface-2);stroke:'.$color($node['status']).';stroke-width:1.5"/>'
                .'<circle cx="'.($p['x'] + 18).'" cy="'.($p['y'] + 22).'" r="6" fill="'.$color($node['status']).'"/>'
                .'<text x="'.($p['x'] + 32).'" y="'.($p['y'] + 27).'" font-size="13" font-family="monospace" style="fill:var(--cp-text)">'.e(mb_substr($name, 0, 22)).'</text>'
                .'<text x="'.($p['x'] + 12).'" y="'.($p['y'] + 50).'" font-size="11" font-family="monospace" style="fill:var(--cp-text-dim)">'.e(mb_substr(implode(',', $node['roles']), 0, 34)).'</text>'
                .'<text x="'.($p['x'] + 12).'" y="'.($p['y'] + 70).'" font-size="11" font-family="monospace" style="fill:var(--cp-text-faint)">'.e($node['status']).'</text>'
                .'</g></a>';
        }

        return $svg.'</svg>';
    }
}
