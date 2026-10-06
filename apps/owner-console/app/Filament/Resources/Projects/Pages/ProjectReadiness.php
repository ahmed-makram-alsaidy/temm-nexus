<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectEnvironment;
use App\Models\ReadinessSnapshot;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\ReadinessService;
use App\Support\ProductStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Phase 24H — Production Readiness Center. Checklist/status only (no score):
 * GREEN / YELLOW / RED / NOT_APPLICABLE with machine evidence, guarded manual
 * acknowledgements, explicit blockers, and snapshot history.
 */
class ProjectReadiness extends Page
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
        return __('labels.readiness');
    }

    public function getBreadcrumbs(): array
    {
        return ['Readiness'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('readiness'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $env = EnvironmentContext::active($project);
        $summary = ReadinessService::summary($project, $env);
        $history = ReadinessSnapshot::where('project_id', $project->id)
            ->when($env, fn ($q) => $q->where(fn ($qq) => $qq->where('environment_id', $env->id)->orWhereNull('environment_id')))
            ->orderByDesc('id')->limit(10)->get();

        $badge = ['green' => 'is-success', 'yellow' => 'is-warning', 'red' => 'is-danger', 'not_applicable' => ''];
        $rows = '';
        foreach ($summary['checks'] as $check) {
            $ack = $check['acknowledged_at'] ? '<span class="cp-badge" title="'.e((string) $check['acknowledgement_note']).'">'.e(__('labels.rd_ack')).'</span>' : '';
            $rows .= '<tr><td>'.e($check['category']).'</td>'
                .'<td><span class="cp-badge '.($badge[$check['status']] ?? '').'">'.e(ProductStatus::label((string) $check['status'])).'</span></td>'
                .'<td>'.e($check['title']).'</td>'
                .'<td>'.e($check['origin']).'</td>'
                .'<td style="font-size:.75rem">'.e((string) $check['detail']).'</td>'
                .'<td>'.($check['blocks_production'] ? '<span class="cp-badge is-danger">'.e(__('labels.rd_blocks')).'</span>' : '—').'</td>'
                .'<td>'.$ack.'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">'.e(__('labels.rd_no_checks')).'</td></tr>';
        }

        $blockers = '';
        foreach ($summary['blockers'] as $b) {
            $blockers .= '<tr><td><span class="cp-badge is-danger">'.e(__('labels.rd_blocker')).'</span></td><td>'.e($b['title']).'</td>'
                .'<td style="font-size:.75rem">'.e((string) $b['detail']).'</td></tr>';
        }
        if ($blockers === '') {
            $blockers = '<tr><td><span class="cp-badge is-success">'.e(__('labels.rd_none')).'</span></td><td>'.e(__('labels.rd_no_blockers')).'</td><td></td></tr>';
        }

        $histRows = '';
        foreach ($history as $snap) {
            $counts = $snap->summary['counts'] ?? [];
            $histRows .= '<tr><td>#'.$snap->id.'</td><td>'.e($snap->created_at->locale(app()->getLocale())->translatedFormat('M j, H:i')).'</td>'
                .'<td>'.e(__('labels.rd_count', ['count' => (int) ($counts['green'] ?? 0), 'state' => ProductStatus::label('green')])).'</td>'
                .'<td>'.e(__('labels.rd_count', ['count' => (int) ($counts['yellow'] ?? 0), 'state' => ProductStatus::label('yellow')])).'</td>'
                .'<td>'.e(__('labels.rd_count', ['count' => (int) ($counts['red'] ?? 0), 'state' => ProductStatus::label('red')])).'</td>'
                .'<td>'.e(__('labels.rd_blockers_count', ['count' => count($snap->summary['blockers'] ?? [])])).'</td></tr>';
        }
        if ($histRows === '') {
            $histRows = '<tr><td colspan="6">'.e(__('labels.rd_no_snapshots')).'</td></tr>';
        }

        return $schema->components([
            Section::make(__('labels.rd_checklist', ['env' => e($env->slug)]))->schema([Html::make(
                '<p style="font-size:.75rem;color:var(--cp-text-dim);margin-bottom:.5rem">'.e(__('labels.rd_status_note')).'</p>'
                .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_category')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_check')).'</th><th>'.e(__('labels.th_origin')).'</th><th>'.e(__('labels.th_evidence')).'</th><th>'.e(__('labels.th_prod')).'</th><th>'.e(__('labels.th_ack')).'</th></tr></thead><tbody>'
                .$rows.'</tbody></table></div>'
            )])->compact(),
            Section::make(__('labels.rd_blockers_title'))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><tbody>'.$blockers.'</tbody></table></div>'
            )])->compact(),
            Section::make(__('labels.rd_history'))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_id')).'</th><th>'.e(__('labels.th_when')).'</th><th colspan="4">'.e(__('labels.rd_counts')).'</th></tr></thead><tbody>'
                .$histRows.'</tbody></table></div>'
            )])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();

        return [
            Action::make('evaluate')->label(__('labels.evaluate_now'))->icon('heroicon-o-play')
                ->action(function () {
                    $summary = ReadinessService::evaluate($this->project(), EnvironmentContext::active($this->project()));
                    Notification::make()->title(__('labels.rd_evaluated', [
                        'green' => (int) $summary['counts']['green'],
                        'yellow' => (int) $summary['counts']['yellow'],
                        'red' => (int) $summary['counts']['red'],
                    ]))->warning($summary['counts']['red'] > 0)->success($summary['counts']['red'] === 0)->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('acknowledge')->label(__('labels.acknowledge_check'))->icon('heroicon-o-check-badge')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'readiness.acknowledge'))
                ->schema([
                    Select::make('check_key')->required()->options(
                        \App\Models\ReadinessCheck::where('project_id', $project->id)
                            ->where('origin', 'manual')->pluck('title', 'check_key')->all()
                    )->helperText(__('labels.machine_checks_only_yellow_can_be_acknow')),
                    Textarea::make('note')->required()->rows(2)->placeholder(__('labels.who_verified_what_and_how')),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'readiness.acknowledge');
                    try {
                        ReadinessService::acknowledge($this->project(), EnvironmentContext::active($this->project()), $data['check_key'], $data['note']);
                        Notification::make()->title(__('labels.acknowledgement_recorded'))->success()->send();
                    } catch (\Throwable $e) {
                        // Raw diagnostics stay in the log; the notification
                        // follows the product error pattern (H3).
                        report($e);
                        Notification::make()->title(__('labels.rd_ack_failed'))->danger()->send();
                    }
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('add_manual')->label(__('labels.add_manual_check'))->icon('heroicon-o-plus')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'readiness.acknowledge'))
                ->schema([
                    Select::make('category')->required()->options(array_combine(ReadinessService::CATEGORIES, ReadinessService::CATEGORIES)),
                    TextInput::make('title')->required(),
                    Textarea::make('note')->rows(2),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'readiness.acknowledge');
                    ReadinessService::addManualCheck($this->project(), EnvironmentContext::active($this->project()), $data);
                    Notification::make()->title(__('labels.manual_check_added'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('snapshot')->label(__('labels.record_snapshot'))->icon('heroicon-o-clock')
                ->action(function () {
                    ReadinessService::snapshot($this->project(), EnvironmentContext::active($this->project()));
                    Notification::make()->title(__('labels.readiness_snapshot_recorded'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }
}
