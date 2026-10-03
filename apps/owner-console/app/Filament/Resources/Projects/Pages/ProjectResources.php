<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\CostEntry;
use App\Models\ProjectEnvironment;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\ResourceObservabilityService;
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
 * Phase 24G — Resource & Cost Observability. Only real metrics; per-node
 * values are labelled honestly; cost figures are ESTIMATEs from
 * operator-entered inputs.
 */
class ProjectResources extends Page
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
        return __('labels.resources');
    }

    public function getBreadcrumbs(): array
    {
        return ['Resources'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('resources'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $env = EnvironmentContext::active($project);
        $metrics = ResourceObservabilityService::current($project, $env);
        $warnings = ResourceObservabilityService::warnings($project);
        $costs = CostEntry::where('project_id', $project->id)->orderByDesc('month')->limit(12)->get();

        $fmt = function ($row): string {
            if (($row['value'] ?? null) === null) {
                return '<span class="cp-badge">unavailable</span>';
            }
            $v = $row['value'];
            $unit = $row['unit'] ?? 'count';
            $pretty = $unit === 'bytes' ? number_format((float) $v / 1048576, 2).' MB' : number_format((float) $v, 2);
            $badge = match ($row['status'] ?? 'ok') {
                'critical' => 'is-danger', 'warning' => 'is-warning', default => 'is-success',
            };

            return '<code>'.e($pretty).'</code> <span class="cp-badge '.$badge.'">'.e($row['status']).'</span>'
                .'<span class="cp-badge" style="margin-left:.25rem">attr: '.e($row['attribution'] ?? 'project').'</span>';
        };

        $rows = '';
        foreach ($metrics as $metric => $row) {
            $rows .= '<tr><td><code>'.e($metric).'</code></td><td>'.$fmt($row).'</td>'
                .'<td>'.e($row['sampled_at'] ?? '—').'</td></tr>';
        }

        $warningHtml = '';
        foreach ($warnings as $w) {
            $warningHtml .= '<span class="cp-badge '.($w['status'] === 'critical' ? 'is-danger' : 'is-warning').'">'
                .e($w['metric']).': '.e((string) $w['value']).'</span> ';
        }
        if ($warningHtml === '') {
            $warningHtml = '<span class="cp-badge is-success">No threshold warnings</span>';
        }

        $costRows = '';
        foreach ($costs as $cost) {
            $estimate = ResourceObservabilityService::allocationEstimate($cost);
            $costRows .= '<tr><td>'.e($cost->name).'</td><td>'.e(number_format($cost->monthly_cost, 2).' '.$cost->currency).'</td>'
                .'<td>'.e($cost->allocation).'</td><td>'.e($cost->month).'</td>'
                .'<td><strong>'.e((string) $estimate['estimate']).' '.$estimate['currency'].'</strong> <span class="cp-badge is-info">ESTIMATE</span>'
                .'<span style="font-size:.7rem;color:var(--cp-text-dim)"> '.e($estimate['basis']).'</span></td></tr>';
        }
        if ($costRows === '') {
            $costRows = '<tr><td colspan="5">No cost entries recorded.</td></tr>';
        }

        return $schema->components([
            Section::make('Current metrics — environment: '.e($env->slug))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Metric</th><th>Value</th><th>Sampled</th></tr></thead><tbody>'
                .$rows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.4rem">Unavailable means the platform could not measure it — never a fabricated value. '
                .'Node-attributed metrics (CPU/RAM) cover the shared node, not per-project splits.</p>'
            )])->compact(),
            Section::make('Capacity warnings')->schema([Html::make($warningHtml)])->compact(),
            Section::make('Cost (operator-entered)')->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Resource</th><th>Monthly</th><th>Allocation</th><th>Month</th><th>Project share</th></tr></thead><tbody>'
                .$costRows.'</tbody></table></div>'
            )])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();

        return [
            Action::make('collect')->label(__('labels.collect_now'))->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $collected = ResourceObservabilityService::collect($this->project(), EnvironmentContext::active($this->project()));
                    Notification::make()->title(count($collected).' metrics collected')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('threshold')->label(__('labels.set_threshold'))->icon('heroicon-o-bell-alert')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'infrastructure.manage'))
                ->schema([
                    Select::make('metric')->required()->options(array_combine(array_keys(ResourceObservabilityService::METRICS), array_keys(ResourceObservabilityService::METRICS))),
                    TextInput::make('warning')->numeric()->required(),
                    TextInput::make('critical')->numeric(),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'infrastructure.manage');
                    ResourceObservabilityService::setThreshold($this->project(), $data['metric'], (float) $data['warning'], isset($data['critical']) ? (float) $data['critical'] : null);
                    Notification::make()->title(__('labels.threshold_saved'))->success()->send();
                }),
            Action::make('cost')->label(__('labels.record_cost'))->icon('heroicon-o-banknotes')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'settings.manage'))
                ->schema([
                    TextInput::make('name')->required()->default('shared VPS'),
                    TextInput::make('monthly_cost')->numeric()->required(),
                    Select::make('currency')->options(['EGP' => 'EGP', 'USD' => 'USD'])->default('EGP'),
                    Select::make('allocation')->options([
                        'equal' => 'equal', 'manual' => 'manual', 'resource_weighted' => 'resource-weighted (when metrics reliable)',
                    ])->default('equal'),
                    TextInput::make('note'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'settings.manage');
                    ResourceObservabilityService::recordCost($this->project(), $data);
                    Notification::make()->title(__('labels.cost_entry_saved_estimate_basis'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }
}
