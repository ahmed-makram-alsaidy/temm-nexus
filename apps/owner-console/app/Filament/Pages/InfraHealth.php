<?php

namespace App\Filament\Pages;

use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\InfrastructureHealthService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Phase 21B: global health split by facet (proxy / app / database / redis /
 * workers / realtime) plus per-node liveness — never one generic state.
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
        return 'Infrastructure Health';
    }

    public function getBreadcrumbs(): array
    {
        return ['Infrastructure', 'Health'];
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
            $tone = $status === 'healthy' ? 'is-success' : ($status === 'unknown' ? '' : 'is-danger');

            return '<span class="cp-badge '.$tone.'">'.e($label).': '.e($status).'</span>';
        };
        $facets = '';
        foreach ($health['facets'] as $facet => $status) {
            $facets .= $badge($facet, $status).' ';
        }
        $nodeRows = '';
        foreach ($health['nodes'] as $n) {
            $nodeRows .= '<tr><td><a href="'.e(InfraNodeDetail::getUrl(['node' => $n['name']])).'"><code>'
                .e($n['name']).'</code></a></td><td>'.e($n['status']).'</td>'
                .'<td><code>'.e($n['last_seen_at'] ?? 'never').'</code></td></tr>';
        }
        $svcRows = '';
        foreach ($health['services'] as $key => $status) {
            $svcRows .= '<tr><td><code>'.e($key).'</code></td><td>'.e($status).'</td></tr>';
        }

        return $schema->components([
            Section::make('Facets')->schema([
                Html::make('<p>'.$facets.'</p>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Profile: <strong>'
                    .e(config('infrastructure.profile', 'single'))
                    .'</strong> (descriptive only — no automatic moves).</p>'),
            ]),
            Section::make('Nodes')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>Node</th><th>Status</th><th>Last seen</th></tr></thead><tbody>'
                    .($nodeRows ?: '<tr><td colspan="3">No nodes registered. Run <code>php artisan infra:seed-local</code>.</td></tr>')
                    .'</tbody></table></div>'),
            ])->compact(),
            Section::make('Services (worst status wins)')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Service</th><th>Status</th></tr></thead><tbody>'
                    .$svcRows.'</tbody></table></div>'),
            ])->compact(),
        ]);
    }
}
