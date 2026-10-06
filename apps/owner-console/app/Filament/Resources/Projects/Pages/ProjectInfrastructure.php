<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\InfrastructureHealthService;
use App\Services\ControlPlane\InfrastructureMapper;
use App\Support\ProductStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 21B: project → infrastructure mapping. Shows which node serves each
 * project service, the resolved endpoints, per-facet health, and the
 * descriptive architecture profile. Overrides are explicit, audited, and
 * reversible — there is no automatic "move" of any service.
 */
class ProjectInfrastructure extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.infrastructure');
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'Infrastructure'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('infrastructure'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        try {
            $mapping = InfrastructureMapper::mapping($p);
            $health = InfrastructureHealthService::forProject($p);
            $db = InfrastructureMapper::dbEndpoint($p);
            $redis = InfrastructureMapper::redisEndpoint($p);
            $reverb = InfrastructureMapper::reverbEndpoint($p);
        } catch (\Throwable $e) {
            return $schema->components([static::connectionError($e)]);
        }

        $facetHtml = '';
        foreach ($health['facets'] as $facet => $info) {
            $tone = $info['status'] === 'healthy' ? 'is-success'
                : (($info['status'] === 'unknown' ? '' : 'is-danger'));
            $facetHtml .= '<span class="cp-badge '.$tone.'">'.e(\Illuminate\Support\Str::headline($facet)).': '.e(ProductStatus::label((string) $info['status']))
                .' → <code dir="ltr">'.e($info['node'] ?? '—').'</code></span> ';
        }
        $mapRows = '';
        foreach ($mapping as $service => $m) {
            $mapRows .= '<tr><td><code dir="ltr">'.e($service).'</code></td><td><code dir="ltr">'.e($m['node'] ?? '—')
                .'</code></td><td><code dir="ltr">'.e($m['endpoint_override'] ?? '—').'</code></td></tr>';
        }
        $ep = fn ($label, $v) => '<tr><td>'.e($label).'</td><td><code dir="ltr">'.e($v).'</code></td></tr>';
        $overridden = ($p->db_host || $p->redis_host || $p->reverb_host) ? __('labels.inf_yes') : __('labels.inf_no');

        return $schema->components([
            Section::make(__('labels.inf_profile', ['profile' => e($health['profile'])]))->schema([
                Html::make('<p>'.$facetHtml.'</p>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('labels.inf_profile_note')).' '
                    .e(__('labels.inf_overrides', ['state' => $overridden])).' '
                    .e(__('labels.inf_explicit_note')).'</p>'),
            ]),
            Section::make(__('labels.inf_mapping'))->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.th_service')).'</th><th>'.e(__('labels.node')).'</th><th>'.e(__('labels.th_endpoint_override')).'</th></tr></thead><tbody>'
                    .$mapRows.'</tbody></table></div>'),
            ])->compact(),
            Section::make(__('labels.inf_endpoints'))->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><tbody>'
                    .$ep(__('labels.inf_postgres'), $db['host'].':'.$db['port'].' ('.__('labels.inf_db').' '.$p->db_name.')')
                    .$ep(__('labels.inf_redis'), $redis['host'].':'.$redis['port'].' ('.__('labels.inf_prefix').' '.$p->redis_prefix.')')
                    .$ep(__('labels.inf_reverb'), $reverb['host'].':'.$reverb['port'])
                    .'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('labels.inf_resolution_note')).'</p>'),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'infrastructure.manage')) {
            return [];
        }

        return [
            Action::make('db_endpoint')->label(__('labels.set_db_endpoint'))
                ->schema([
                    TextInput::make('host')->label(__('labels.db_host_empty_env_default'))->placeholder('postgres'),
                    TextInput::make('port')->label(__('labels.db_port_empty_env_default'))->placeholder('5432')
                        ->rule('nullable|integer|min:1|max:65535'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'infrastructure.manage');
                    $p = $this->project();
                    $p->forceFill([
                        'db_host' => ($data['host'] ?? '') !== '' ? mb_substr(trim($data['host']), 0, 255) : null,
                        'db_port' => ($data['port'] ?? '') !== '' ? (int) $data['port'] : null,
                    ])->save();
                    \App\Services\ControlPlane\ProjectConnectionManager::forget($p);
                    $this->audit('PROJECT_ENDPOINT_UPDATED', 'database', $p->db_host.':'.$p->db_port);
                    Notification::make()->title(__('labels.db_endpoint_updated'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $p]));
                }),
            Action::make('redis_endpoint')->label(__('labels.set_redis_endpoint'))
                ->schema([
                    TextInput::make('host')->label(__('labels.redis_host_empty_env_default'))->placeholder('redis'),
                    TextInput::make('port')->label(__('labels.redis_port_empty_env_default'))->placeholder('6379')
                        ->rule('nullable|integer|min:1|max:65535'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'infrastructure.manage');
                    $p = $this->project();
                    $p->forceFill([
                        'redis_host' => ($data['host'] ?? '') !== '' ? mb_substr(trim($data['host']), 0, 255) : null,
                        'redis_port' => ($data['port'] ?? '') !== '' ? (int) $data['port'] : null,
                    ])->save();
                    $this->audit('PROJECT_ENDPOINT_UPDATED', 'redis', $p->redis_host.':'.$p->redis_port);
                    Notification::make()->title(__('labels.redis_endpoint_updated'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $p]));
                }),
            Action::make('profile')->label(__('labels.set_profile'))
                ->schema([
                    Select::make('profile')->label(__('labels.architecture_profile_descriptive'))->required()
                        ->options(['single' => 'Single node', 'split' => 'Split database', 'distributed' => 'Distributed']),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'infrastructure.manage');
                    $p = $this->project();
                    $p->forceFill(['infra_profile' => $data['profile']])->save();
                    $this->audit('PROJECT_PROFILE_UPDATED', 'profile', $data['profile']);
                    Notification::make()->title(__('labels.profile_set_to_frag').$data['profile'])->success()->send();
                    $this->redirect(static::getUrl(['record' => $p]));
                }),
        ];
    }
}
