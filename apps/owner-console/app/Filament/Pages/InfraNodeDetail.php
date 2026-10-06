<?php

namespace App\Filament\Pages;

use App\Models\InfrastructureEvent;
use App\Models\InfrastructureNode;
use App\Models\ProjectServiceNode;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\CpAccess;
use App\Support\ProductStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Phase 21B: node detail — status, roles, resources, services, projects
 * using the node, health history, last heartbeat. No shell terminal: the
 * only credential operation is token rotation (hash stored, shown once).
 *
 * 0.6.0 Phase H: all chrome flows through lang/, statuses through the
 * status dictionary, technical values stay LTR-isolated (Arabic-safe),
 * and every empty table row carries a meaningful sentence.
 */
class InfraNodeDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected static ?string $navigationLabel = 'Node Detail';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'infra-node';

    #[Url(as: 'node')]
    public ?string $node = null;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->node
            ? __('infra.node_title', ['node' => $this->node])
            : __('infra.node_section_title');
    }

    public function getBreadcrumbs(): array
    {
        return [
            InfraNodes::getUrl() => __('settings.system_nodes'),
            $this->node ?? __('infra.node_section_title'),
        ];
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
        $node = $this->node ? InfrastructureNode::query()->where('name', $this->node)->first() : null;
        if (! $node) {
            return $schema->components([
                Section::make(__('infra.node_unknown_title'))
                    ->description(__('infra.node_unknown_body'))
                    ->compact(),
            ]);
        }
        $status = $node->computedStatus();
        $dot = $status === 'healthy' ? 'is-healthy' : ($status === 'unknown' ? 'is-unknown' : 'is-danger');

        $svcRows = '';
        foreach ($node->services()->orderBy('key')->get() as $s) {
            $svcRows .= '<tr><td><code dir="ltr">'.e($s->key).'</code></td><td>'.e($s->label ?? '—').'</td>'
                .'<td>'.e($s->scope).'</td><td>'.e(ProductStatus::label((string) $s->status)).'</td>'
                .'<td><code dir="ltr">'.e($s->endpoint ?? '—').'</code></td>'
                .'<td><code dir="ltr">'.e($s->version ?? '—').'</code></td></tr>';
        }

        $projRows = '';
        foreach (ProjectServiceNode::query()->where('node_id', $node->id)->with('project')->get() as $m) {
            $projRows .= '<tr><td>'.e($m->project?->name ?? '#'.$m->project_id).'</td>'
                .'<td><code dir="ltr">'.e($m->service).'</code></td></tr>';
        }

        $histRows = '';
        foreach (InfrastructureEvent::query()->where('source', 'node-heartbeat')
            ->where('message', 'like', '%'.$node->name.'%')
            ->orderByDesc('id')->limit(10)->get() as $e) {
            $when = $e->created_at?->locale(app()->getLocale())->translatedFormat('M j, H:i') ?? '—';
            $histRows .= '<tr><td>'.e($when).'</td>'
                .'<td>'.e($e->severity).'</td><td>'.e($e->message).'</td></tr>';
        }

        $res = fn ($label, $v) => '<tr><td>'.e($label).'</td><td>'.e($v).'</td></tr>';

        return $schema->components([
            Section::make(__('infra.node_section_title'))->schema([
                Html::make(
                    '<p><span class="cp-dot '.$dot.'"></span> <strong>'.e($node->name).'</strong> '
                    .'<span class="cp-badge">'.e(strtoupper($node->environment ?? 'local')).'</span> '
                    .'<span class="cp-badge">'.e(ProductStatus::label((string) $status)).'</span></p>'
                    .'<div class="cp-tablewrap"><table class="cp-grid"><tbody>'
                    .$res(__('infra.node_col_hostname'), $node->hostname ?? '—')
                    .$res(__('infra.node_col_roles'), implode(', ', $node->roles ?? []))
                    .$res(__('infra.node_col_provider'), ($node->provider ?? '—').' / '.($node->region ?? '—'))
                    .$res(__('infra.node_col_resources'), ($node->cpu_cores ?? '?').' / '.($node->ram_mb ?? '?').' MB / '.($node->disk_gb ?? '?').' GB')
                    .$res(__('infra.node_col_load'), "CPU {$node->cpu_pct}% · RAM {$node->ram_pct}% · disk {$node->disk_pct}%")
                    .$res(__('infra.node_col_agent_version'), $node->agent_version ?? '—')
                    .$res(__('infra.node_col_token_prefix'), $node->token_prefix ?? __('infra.node_no_token'))
                    .$res(__('infra.node_col_last_heartbeat'), $node->last_seen_at?->locale(app()->getLocale())->translatedFormat('M j, H:i') ?? __('infra.never_reported'))
                    .'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'
                    .e($node->enabled ? __('infra.node_enabled_yes') : __('infra.node_enabled_no')).'. '
                    .e(__('infra.node_status_note')).'</p>'
                ),
            ]),
            Section::make(__('infra.node_services_title', ['count' => $node->services()->count()]))->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('infra.node_col_key')).'</th><th>'.e(__('infra.node_col_label')).'</th><th>'.e(__('infra.node_col_scope')).'</th><th>'.e(__('infra.col_status')).'</th><th>'.e(__('infra.node_col_endpoint')).'</th><th>'.e(__('infra.node_col_version')).'</th>'
                    .'</tr></thead><tbody>'.($svcRows !== '' ? $svcRows : '<tr><td colspan="6">'.e(__('infra.node_services_empty')).'</td></tr>').'</tbody></table></div>'),
            ])->compact(),
            Section::make(__('infra.node_projects_title'))->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('infra.node_col_project')).'</th><th>'.e(__('infra.node_col_service')).'</th></tr></thead><tbody>'
                    .($projRows !== '' ? $projRows : '<tr><td colspan="2">'.e(__('infra.node_projects_empty')).'</td></tr>').'</tbody></table></div>'),
            ])->compact(),
            Section::make(__('infra.node_history_title'))->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('infra.node_col_at')).'</th><th>'.e(__('infra.node_col_severity')).'</th><th>'.e(__('infra.node_col_event')).'</th></tr></thead><tbody>'
                    .($histRows !== '' ? $histRows : '<tr><td colspan="3">'.e(__('infra.node_history_empty')).'</td></tr>').'</tbody></table></div>'),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'infrastructure.manage')) {
            return [];
        }

        return [
            Action::make('rotate_token')->label(__('labels.rotate_agent_token'))->color('warning')->requiresConfirmation()
                ->modalDescription(__('labels.the_old_token_stops_working_immediately_'))
                ->action(function () {
                    $node = InfrastructureNode::query()->where('name', $this->node)->firstOrFail();
                    $plain = $node->rotateToken();
                    AdminAudit::record('NODE_TOKEN_ROTATED', null, 'node', $node->name, []);
                    Notification::make()->title(__('labels.new_agent_token_copy_now_shown_once'))->body($plain)->warning()
                        ->persistent()->send();
                }),
        ];
    }
}
