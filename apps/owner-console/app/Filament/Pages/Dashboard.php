<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Services\Access\Capability;
use App\Services\Product\JourneyState;
use App\Services\Product\PlatformPulse;
use App\Services\Product\UiPreferenceService;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 0.4.0 Phase D — PLATFORM HOME (§7).
 *
 * The first screen answers, in this order:
 *   1. What requires attention?
 *   2. What is running?
 *   3. What should I do next?
 *
 * v0.3.0 led with infrastructure ("DB STORAGE", "LAST BACKUP") above anything
 * user-relevant. Here infrastructure is demoted to the last section and
 * summarised in product language.
 *
 * Every figure comes from `PlatformPulse`, which is scoped to
 * `Access::accessibleProjects()` — so a summary can never count a project the
 * viewer cannot open.
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
        return 'Home';
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
     * components, in one query. The view applies visibility and density from
     * this; the Inspect panel writes them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function uiPreferences(): array
    {
        return UiPreferenceService::for(auth()->user())->effectiveForComponents([
            'home.hero',
            'home.summary',
            'home.attention',
            'home.recent_projects',
            'home.platform_health',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────

    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 5 => 'Good night',
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
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
            return 'No projects yet — create one to start your first migration.';
        }

        $attention = $pulse->projectsNeedingAttention()->count();
        $running = $pulse->activeMigrationCount();
        $ready = $pulse->readyForCutoverCount();

        $parts = [];
        if ($attention > 0) {
            $parts[] = self::projectAttentionPhrase($attention);
        }
        if ($running > 0) {
            $parts[] = $running.' migration'.($running === 1 ? '' : 's').' running';
        }
        if ($ready > 0) {
            $parts[] = $ready.' ready for cutover';
        }

        if ($parts === []) {
            return 'All clear — nothing needs you right now.';
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

        if ($count === 1) {
            return '1 project needs attention';
        }

        return $count.' projects need attention';
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

    /** Tone class for a summary card. */
    public function toneClass(string $tone): string
    {
        return match ($tone) {
            'success' => 'nx-stat-card__value--success',
            'warning' => 'nx-stat-card__value--warning',
            'danger' => 'nx-stat-card__value--danger',
            'info' => 'nx-stat-card__value--info',
            default => '',
        };
    }

    /** Status modifier for a journey state. */
    public function statusClass(JourneyState $state): string
    {
        return 'nx-status--'.$state->tone();
    }
}
