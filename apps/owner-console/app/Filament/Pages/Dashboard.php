<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Services\Access\Capability;
use App\Services\Product\JourneyState;
use App\Services\Product\PlatformPulse;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 0.6.0 Phase C — HOME as a calm command center (audit §C1–§C15).
 *
 * Priority order on the page:
 *   1. Platform state (one sentence, one primary action)
 *   2. Needs attention (max 3, quiet "all clear" when empty)
 *   3. Continue where you left off (deterministic, hidden when nothing to resume)
 *   4. Projects (≤ 5 compact rows)
 *   5. Recent activity (5 humanized events)
 *   6. System status (one quiet line; degraded → promoted into attention)
 *
 * What is GONE: the permanent five-card KPI grid (zero metrics consumed the
 * fold) and the two always-on backup cards (backups surface only when a
 * backup actually needs attention — through the project warnings).
 *
 * Every figure still comes from `PlatformPulse`, scoped to
 * `Access::accessibleProjects()` — a viewer is never shown state, counts or
 * CTAs their capabilities do not cover, and each section degrades
 * independently: one unavailable source must never 500 the Home.
 */
class Dashboard extends BaseDashboard
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.home';

    private ?PlatformPulse $pulse = null;

    /**
     * The greeting IS the page heading, so Filament must not render a second
     * one. v0.3.0 stacked "Dashboard" over "Good evening"; one heading is
     * enough and the greeting carries more information.
     */
    public function getHeading(): string|Htmlable|null
    {
        return '';
    }

    public function getTitle(): string
    {
        return __('home.title');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function pulse(): PlatformPulse
    {
        return $this->pulse ??= PlatformPulse::for(PlatformAccess::current()->access());
    }

    /**
     * Phase I — this user's effective appearance preferences for the Home
     * sections, in one query. The view applies visibility/density from this;
     * the Inspect panel writes them. 0.6.0 Phase C keys follow the new
     * section structure.
     *
     * @return array<string, array<string, mixed>>
     */
    public function uiPreferences(): array
    {
        return \App\Services\Product\UiPreferenceService::for(auth()->user())->effectiveForComponents([
            'home.hero',
            'home.attention',
            'home.continue',
            'home.recent_projects',
            'home.activity',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // The one primary action (§C1): exactly one, context-sensitive.
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array{label: string, url: string}
     */
    public function primaryAction(): array
    {
        $pulse = $this->pulse();

        // No projects yet: the one thing worth doing is connecting one.
        if (! $pulse->hasAnyProject()) {
            if ($this->canCreateProject()) {
                return ['label' => __('home.cta_connect_first'), 'url' => NewProjectWizard::getUrl()];
            }

            // A viewer on an empty platform has nothing to create — point
            // them at the projects they can see (still one honest action).
            return ['label' => __('home.cta_open_projects'), 'url' => $this->projectsUrl()];
        }

        // Attention exists: reviewing it is the action.
        $attention = $this->safeSection(fn () => $pulse->projectsNeedingAttention());
        if ($attention->isNotEmpty()) {
            $first = $attention->first();

            return [
                'label' => __('home.cta_review_issues'),
                'url' => $first['url'] ?? $this->projectsUrl(),
            ];
        }

        // A migration is running: continue it.
        if ($pulse->activeMigrationCount() > 0) {
            $continue = $this->safeSection(fn () => $pulse->continueTarget());
            if ($continue !== null) {
                return ['label' => __('home.cta_continue_migration'), 'url' => $continue['url'] ?? $this->projectsUrl()];
            }
        }

        // Ready for cutover: go verify.
        $ready = $this->safeSection(fn () => $pulse->readyForCutoverProjects());
        if ($ready->isNotEmpty()) {
            $first = $ready->first();

            return [
                'label' => __('home.cta_verify_migration'),
                'url' => \App\Services\Product\ProjectPulse::for($first)->urlForStage(\App\Services\Product\JourneyStage::CUTOVER)
                    ?? $this->projectsUrl(),
            ];
        }

        // Everything healthy: the default good action is starting the next
        // migration (or, without that capability, the projects list).
        if ($this->canCreateProject()) {
            return ['label' => __('home.cta_new_migration'), 'url' => NewProjectWizard::getUrl()];
        }

        return ['label' => __('home.cta_open_projects'), 'url' => $this->projectsUrl()];
    }

    // ─────────────────────────────────────────────────────────────────
    // Section data — each section fails alone (§ERRORS)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Run a section data builder; a failing secondary source yields an empty
     * result instead of a 500 Home.
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T|mixed
     */
    public function safeSection(callable $build): mixed
    {
        try {
            return $build();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Needs attention (§C2): max 3 with the problem's own detail, plus the
     * total so the view can offer "View all". A degraded SYSTEM (§C7) is
     * promoted into this list ahead of project items, with "View system
     * details" as its action — infrastructure never becomes a telemetry card.
     *
     * @return array{items: mixed, total: int}
     */
    public function attention(): array
    {
        $items = $this->safeSection(fn () => $this->pulse()->projectsNeedingAttention());

        $list = $items === null ? collect() : $items;

        $system = $this->systemStatus();
        if ($system['state'] === 'degraded') {
            $facets = implode(', ', $system['degraded']);
            $list = collect([[
                'project' => null,
                'state' => JourneyState::NEEDS_ATTENTION,
                'reason' => __('home.system_attention_title'),
                'detail' => __('home.system_attention_body', ['facets' => $facets]),
                'url' => $this->systemUrl(),
            ]])->merge($list);
        }

        return ['items' => $list->take(3), 'total' => $list->count()];
    }

    /**
     * Continue where you left off (§C3). Hidden (null) when there is no
     * meaningful, real state to resume.
     *
     * @return array{project: mixed, stage: mixed, at: mixed, url: ?string}|null
     */
    public function continueTarget(): ?array
    {
        if (! $this->pulse()->hasAnyProject()) {
            return null;
        }

        return $this->safeSection(fn () => $this->pulse()->continueTarget());
    }

    /**
     * Compact project rows (§C4): ≤ 5, attention first, no infrastructure fields.
     */
    public function projectSummaries(): mixed
    {
        return $this->safeSection(fn () => $this->pulse()->projectSummaries(5));
    }

    /**
     * Recent activity (§C6): 5 humanized events (the view humanizes), with
     * the section only for users who may reach the Activity destination.
     */
    public function recentActivity(): mixed
    {
        return $this->safeSection(fn () => $this->pulse()->recentActivity(5));
    }

    public function canViewActivity(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AUDIT_VIEW);
    }

    /**
     * System status (§C7): one quiet line for infrastructure viewers; a
     * degraded system is promoted into the attention list instead of a
     * telemetry card.
     *
     * @return array{state: string, degraded: array<int, string>}
     */
    public function systemStatus(): array
    {
        if (! PlatformAccess::current()->allowsPlatform(Capability::INFRASTRUCTURE_VIEW)) {
            return ['state' => 'unknown', 'degraded' => []];
        }

        $status = $this->safeSection(fn () => $this->pulse()->systemStatus());

        return $status ?? ['state' => 'unknown', 'degraded' => []];
    }

    public function systemUrl(): string
    {
        try {
            return InfraHealth::getUrl();
        } catch (\Throwable) {
            return '/admin/settings';
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 5 => __('home.greeting_night'),
            $hour < 12 => __('home.greeting_morning'),
            $hour < 18 => __('home.greeting_afternoon'),
            default => __('home.greeting_evening'),
        };
    }

    public function userName(): string
    {
        return (string) (auth()->user()?->name ?? 'there');
    }

    /**
     * One sentence describing the platform's state.
     *
     * Deliberately does not invent a status: it reports the same counts the
     * sections below show, so the headline can never contradict the detail.
     */
    public function stateSentence(): string
    {
        $pulse = $this->pulse();

        if (! $pulse->hasAnyProject()) {
            return __('home.state_no_projects');
        }

        $attention = $pulse->projectsNeedingAttention()->count();
        $running = $pulse->activeMigrationCount();
        $ready = $pulse->readyForCutoverCount();

        $parts = [];
        if ($attention > 0) {
            $parts[] = self::projectAttentionPhrase($attention);
        }
        if ($running > 0) {
            $parts[] = (string) trans_choice('home.migrations_running_phrase', $running, ['count' => $running]);
        }
        if ($ready > 0) {
            $parts[] = (string) trans_choice('home.ready_for_cutover_phrase', $ready, ['count' => $ready]);
        }

        if ($parts === []) {
            return __('home.state_all_clear');
        }

        return ucfirst(implode(' · ', $parts)).'.';
    }

    /**
     * The attention phrase with correct singular/plural agreement
     * (rc.2: "1 project needs attention" vs "2 projects need attention" —
     * the verb must agree with the count, not just the noun).
     *
     * @return string '' when nothing needs attention
     */
    public static function projectAttentionPhrase(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return (string) trans_choice('home.attention_phrase', $count, ['count' => $count]);
    }

    public function canCreateProject(): bool
    {
        return PlatformAccess::current()->canCreateProject();
    }

    public function canViewWorkspaces(): bool
    {
        return PlatformAccess::current()->access()->accessibleWorkspaces()->isNotEmpty();
    }

    public function canUseAi(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AI_USE);
    }

    /** Status modifier for a journey state. */
    public function statusClass(JourneyState $state): string
    {
        return 'nx-status--'.$state->tone();
    }

    private function projectsUrl(): string
    {
        try {
            return \App\Filament\Resources\Projects\ProjectResource::getUrl('index');
        } catch (\Throwable) {
            return '/admin/projects';
        }
    }
}
