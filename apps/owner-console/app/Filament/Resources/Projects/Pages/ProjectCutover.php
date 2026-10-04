<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\PlatformAccess;
use App\Services\Access\Capability;
use App\Services\ControlPlane\Cutover\CutoverCenterService;
use App\Services\Product\CutoverReadiness;
use App\Services\Product\UiPreferenceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;

/**
 * 0.4.0 Phase E (§10) — the dedicated CUTOVER readiness screen.
 *
 * v0.3.0 had no page called Cutover. This is the critical product moment: the
 * point at which a client's production switches. The screen answers:
 *
 *   Can I go?  If not, exactly why?  And who has approved what?
 *
 * SAFETY POSTURE (unchanged from Phase 34, and deliberately so)
 *  - The platform NEVER performs a DNS or endpoint switch. Those steps are
 *    operator-owned and are recorded, not executed.
 *  - Approvals are explicit, per gate, and audited. Nothing auto-approves.
 *  - A gate with no evidence reads "Not verified", never green.
 *  - The primary action is disabled until every gate passes, so the UI cannot
 *    invite an unsafe click.
 */
class ProjectCutover extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Cutover';

    protected static ?string $slug = 'cutover';

    protected static ?string $breadcrumb = 'Cutover';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return __('labels.cutover');
    }

    public function getSubheading(): ?string
    {
        return 'Readiness for '.$this->project()->name.'. Nothing here changes your systems.';
    }

    /**
     * 0.6.0 Phase B (§B7): every project page shares one ancestry —
     * Projects → {Project name} → {Area}. See ViewProject::getBreadcrumbs().
     */
    public function getBreadcrumbs(): array
    {
        $crumbs = [ProjectResource::getUrl('index') => __('nav.projects')];
        $crumbs[static::projectUrl($this->project(), 'overview')] = $this->project()->name;
        $crumbs[static::getUrl(['record' => $this->project()])] = __('labels.cutover');

        return $crumbs;
    }

    private ?CutoverReadiness $readiness = null;

    public function readiness(): CutoverReadiness
    {
        return $this->readiness ??= CutoverReadiness::for($this->project());
    }

    public function content(Schema $schema): Schema
    {
        $project = $this->project();

        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--cutover'])->components([
            $this->subnavSection('cutover'),
            Html::make(fn (): string => view('filament.projects.cutover', [
                'project' => $project,
                'r' => $this->readiness(),
                'overall' => $this->readiness()->overall(),
                'gates' => $this->readiness()->gates(),
                'issues' => $this->readiness()->blockingIssues(),
                'liveSync' => $this->readiness()->liveSync(),
                'validation' => $this->readiness()->validation(),
                'backup' => $this->readiness()->backup(),
                'rollback' => $this->readiness()->rollback(),
                'finalSync' => $this->readiness()->finalSync(),
                'approvals' => $this->readiness()->approvals(),
                'steps' => $this->readiness()->steps(),
                'plan' => $this->readiness()->plan(),
                'canApprove' => $this->canApprove(),
                'canPreflight' => $this->canPreflight(),
                // Phase I — this user's appearance preferences for the cutover
                // components, resolved at project scope.
                'ui' => UiPreferenceService::for(auth()->user())->effectiveForComponents(
                    ['cutover.overall', 'cutover.gates', 'cutover.approvals', 'cutover.plan'],
                    $project->workspace?->getKey(),
                    $project->getKey(),
                ),
            ])->render()),
        ]);
    }

    // ── Capabilities ───────────────────────────────────────────────────

    /**
     * Approval is a distinct capability from viewing. A Viewer or Operator can
     * read this screen; only a role holding `cutover.approve` may decide a gate.
     */
    public function canApprove(): bool
    {
        return PlatformAccess::current()
            ->allowsProject(Capability::CUTOVER_APPROVE, $this->project());
    }

    public function canPreflight(): bool
    {
        return PlatformAccess::current()
            ->allowsProject(Capability::CUTOVER_PREFLIGHT, $this->project());
    }

    // ── Actions ────────────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runPreflight')
                ->label(__('labels.run_preflight'))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn (): bool => $this->canPreflight())
                ->requiresConfirmation()
                ->modalHeading(__('labels.run_cutover_preflight'))
                ->modalDescription(__('labels.creates_a_new_cutover_plan_from_the_curr'))
                ->modalSubmitActionLabel(__('labels.run_preflight'))
                ->action(function (): void {
                    // Execution-time re-check (§17).
                    if (! $this->canPreflight()) {
                        abort(403, 'Missing capability: '.Capability::CUTOVER_PREFLIGHT);
                    }

                    $plan = (new CutoverCenterService)->createPlan($this->project());
                    $this->readiness = null;

                    Notification::make()
                        ->title(__('labels.preflight_recorded'))
                        ->body(__('labels.plan_frag').$plan->run_id.' created. Review the gates and approve what is ready.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
