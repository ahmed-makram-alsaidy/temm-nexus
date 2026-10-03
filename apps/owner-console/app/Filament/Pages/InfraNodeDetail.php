<?php

namespace App\Filament\Pages;

use App\Models\InfrastructureEvent;
use App\Models\InfrastructureNode;
use App\Models\ProjectServiceNode;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\CpAccess;
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
        return $this->node ? 'Node · '.$this->node : 'Node';
    }

    public function getBreadcrumbs(): array
    {
        return [InfraNodes::getUrl() => 'Nodes', $this->node ?? 'Node'];
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
                Section::make('Unknown node')->description('Pick a node from the Nodes list.')->compact(),
            ]);
        }
        $status = $node->computedStatus();
        $dot = $status === 'healthy' ? 'is-healthy' : ($status === 'unknown' ? 'is-unknown' : 'is-danger');

        $svcRows = '';
        foreach ($node->services()->orderBy('key')->get() as $s) {
            $svcRows .= '<tr><td><code>'.e($s->key).'</code></td><td>'.e($s->label ?? '—').'</td>'
                .'<td>'.e($s->scope).'</td><td>'.e($s->status).'</td>'
                .'<td><code>'.e($s->endpoint ?? '—').'</code></td>'
                .'<td><code>'.e($s->version ?? '—').'</code></td></tr>';
        }

        $projRows = '';
        foreach (ProjectServiceNode::query()->where('node_id', $node->id)->with('project')->get() as $m) {
            $projRows .= '<tr><td>'.e($m->project?->name ?? '#'.$m->project_id).'</td>'
                .'<td><code>'.e($m->service).'</code></td></tr>';
        }

        $histRows = '';
        foreach (InfrastructureEvent::query()->where('source', 'node-heartbeat')
            ->where('message', 'like', '%'.$node->name.'%')
            ->orderByDesc('id')->limit(10)->get() as $e) {
            $histRows .= '<tr><td>'.e($e->created_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td>'.e($e->severity).'</td><td>'.e($e->message).'</td></tr>';
        }

        $res = fn ($label, $v) => '<tr><td>'.e($label).'</td><td>'.e($v).'</td></tr>';

        return $schema->components([
            Section::make('Node')->schema([
                Html::make(
                    '<p><span class="cp-dot '.$dot.'"></span> <strong>'.e($node->name).'</strong> '
                    .'<span class="cp-badge">'.e(strtoupper($node->environment ?? 'local')).'</span> '
                    .'<span class="cp-badge">'.e($status).'</span></p>'
                    .'<div class="cp-tablewrap"><table class="cp-grid"><tbody>'
                    .$res('Hostname / IP ref', $node->hostname ?? '—')
                    .$res('Roles', implode(', ', $node->roles ?? []))
                    .$res('Provider / region', ($node->provider ?? '—').' / '.($node->region ?? '—'))
                    .$res('CPU / RAM / disk', ($node->cpu_cores ?? '?').' cores / '.($node->ram_mb ?? '?').' MB / '.($node->disk_gb ?? '?').' GB')
                    .$res('Load (last heartbeat)', "CPU {$node->cpu_pct}% · RAM {$node->ram_pct}% · disk {$node->disk_pct}%")
                    .$res('Agent version', $node->agent_version ?? '—')
                    .$res('Token prefix', $node->token_prefix ?? 'none issued')
                    .$res('Last heartbeat', $node->last_seen_at?->toIso8601String() ?? 'never')
                    .'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Enabled: '.($node->enabled ? 'yes' : 'no (reports offline)').'. '
                    .'Status is computed from heartbeat age (degraded &gt;90s, offline &gt;300s).</p>'
                ),
            ]),
            Section::make('Services ('.$node->services()->count().')')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.key')).'</th><th>'.e(__('labels.th_label')).'</th><th>'.e(__('labels.th_scope')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.endpoint')).'</th><th>'.e(__('labels.version')).'</th>'
                    .'</tr></thead><tbody>'.($svcRows ?: '<tr><td colspan="6">No services reported.</td></tr>').'</tbody></table></div>'),
            ])->compact(),
            Section::make('Projects using this node')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.project')).'</th><th>'.e(__('labels.th_service')).'</th></tr></thead><tbody>'
                    .($projRows ?: '<tr><td colspan="2">None.</td></tr>').'</tbody></table></div>'),
            ])->compact(),
            Section::make('Health history')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_at')).'</th><th>'.e(__('labels.th_severity')).'</th><th>'.e(__('labels.event')).'</th></tr></thead><tbody>'
                    .($histRows ?: '<tr><td colspan="3">No transitions recorded.</td></tr>').'</tbody></table></div>'),
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
