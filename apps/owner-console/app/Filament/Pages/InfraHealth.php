<?php

namespace App\Filament\Pages;

use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\InfrastructureHealthService;
use App\Support\ProductStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Phase 21B: global health split by facet (proxy / app / database / redis /
 * workers / realtime) plus per-node liveness — never one generic state.
 *
 * 0.6.0 Phase H: statuses render through the status dictionary (raw enums
 * never shown), copy through lang/, and the empty states explain what
 * appears here and why. (This pass also repaired malformed <th> markup in
 * the nodes/services tables.)
 */
class InfraHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Health';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 72;

    protected static ?string $slug = 'infra-health';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.infrastructure_health');
    }

    public function getBreadcrumbs(): array
    {
        return [__('settings.system_title'), __('settings.system_health')];
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
        $health = InfrastructureHealthService::global();

        $badge = function (string $label, string $status) {
            $tone = ProductStatus::color($status);

            return '<span class="cp-badge is-'.e($tone).'">'.e($label).': '.e(ProductStatus::label($status)).'</span>';
        };

        $facets = '';
        foreach ($health['facets'] as $facet => $status) {
            $facets .= $badge(\Illuminate\Support\Str::headline($facet), (string) $status).' ';
        }

        $nodeRows = '';
        foreach ($health['nodes'] as $n) {
            $nodeRows .= '<tr><td><a href="'.e(InfraNodeDetail::getUrl(['node' => $n['name']])).'"><code>'
                .e($n['name']).'</code></a></td><td>'.e(ProductStatus::label((string) $n['status'])).'</td>'
                .'<td><code dir="ltr">'.e($n['last_seen_at'] ?? __('infra.never_reported')).'</code></td></tr>';
        }

        $svcRows = '';
        foreach ($health['services'] as $key => $status) {
            $svcRows .= '<tr><td><code dir="ltr">'.e($key).'</code></td><td>'.e(ProductStatus::label((string) $status)).'</td></tr>';
        }

        return $schema->components([
            Section::make(__('infra.health_facets_title'))
                ->description(__('infra.health_facets_description'))
                ->schema([
                    Html::make('<p>'.$facets.'</p>'
                        .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('infra.health_profile_note', ['profile' => config('infrastructure.profile', 'single')])).'</p>'),
                ]),
            Section::make(__('infra.health_nodes_title'))->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('infra.col_node')).'</th><th>'.e(__('infra.col_status')).'</th><th>'.e(__('infra.col_last_seen')).'</th></tr></thead><tbody>'
                    .($nodeRows !== ''
                        ? $nodeRows
                        : '<tr><td colspan="3">'.e(__('infra.health_empty_nodes_title')).' — '.e(__('infra.health_empty_nodes_body')).'</td></tr>')
                    .'</tbody></table></div>'),
            ])->compact(),
            Section::make(__('infra.health_services_title'))
                ->description(__('infra.health_services_hint'))
                ->schema([
                    Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                        .'<th>'.e(__('infra.col_service')).'</th><th>'.e(__('infra.col_status')).'</th></tr></thead><tbody>'
                        .($svcRows !== ''
                            ? $svcRows
                            : '<tr><td colspan="2">'.e(__('infra.health_services_empty')).'</td></tr>')
                        .'</tbody></table></div>'),
                ])->compact(),
        ]);
    }
}
