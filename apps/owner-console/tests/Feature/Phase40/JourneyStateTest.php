<?php

namespace Tests\Feature\Phase40;

use App\Services\Product\JourneyStage;
use App\Services\Product\JourneyState;
use Tests\TestCase;

/**
 * 0.4.0 §6/§14 — the journey and status vocabulary.
 *
 * These are cheap, but they guard a class of defect that unit tests usually
 * miss and that only shows up as a page-level 500: an icon name that does not
 * exist in the installed Heroicons set. `heroicon-o-circle` shipped in an
 * earlier revision of this branch and broke Home until browser QA caught it.
 */
class JourneyStateTest extends TestCase
{
    /** @return list<string> */
    private function installedOutlineIcons(): array
    {
        $dir = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');
        $this->assertDirectoryExists($dir, 'Heroicons SVG set is missing; cannot validate icon names.');

        return array_map(
            fn (string $path): string => 'heroicon-'.basename($path, '.svg'),
            glob($dir.'/o-*.svg') ?: [],
        );
    }

    public function test_every_journey_state_icon_exists_in_the_installed_icon_set(): void
    {
        $installed = $this->installedOutlineIcons();

        foreach (JourneyState::cases() as $state) {
            $this->assertContains(
                $state->icon(),
                $installed,
                "JourneyState::{$state->name} references '{$state->icon()}', which is not an installed Heroicon. "
                .'An unknown icon throws at render time and turns the page into a 500.',
            );
        }
    }

    public function test_every_journey_state_has_a_label_and_a_tone(): void
    {
        foreach (JourneyState::cases() as $state) {
            $this->assertNotSame('', $state->label());
            $this->assertContains($state->tone(), ['neutral', 'info', 'success', 'warning', 'danger']);
            // A label is never the raw enum value (§14).
            $this->assertNotSame($state->value, $state->label());
        }
    }

    public function test_no_state_relies_on_colour_alone(): void
    {
        // §34: every state must carry an icon AND a distinct label.
        $icons = array_map(fn (JourneyState $s): string => $s->icon(), JourneyState::cases());
        $labels = array_map(fn (JourneyState $s): string => $s->label(), JourneyState::cases());

        $this->assertSame($icons, array_unique($icons), 'Two states share an icon, so colour becomes load-bearing.');
        $this->assertSame($labels, array_unique($labels), 'Two states share a label.');
    }

    public function test_journey_sequence_is_ordered_and_complete(): void
    {
        $sequence = JourneyStage::sequence();

        $this->assertCount(7, $sequence);
        $this->assertSame([
            JourneyStage::CONNECT,
            JourneyStage::ANALYZE,
            JourneyStage::PLAN,
            JourneyStage::MIGRATE,
            JourneyStage::SYNC,
            JourneyStage::VALIDATE,
            JourneyStage::CUTOVER,
        ], $sequence);

        foreach ($sequence as $index => $stage) {
            $this->assertSame($index, $stage->position(), "{$stage->name} reports the wrong position.");
            $this->assertNotSame('', $stage->label());
            $this->assertNotSame('', $stage->description());
        }
    }

    public function test_raw_internal_tokens_translate_to_product_language(): void
    {
        // The raw tokens the mission names must never be the displayed label.
        $this->assertSame(JourneyState::NOT_STARTED, JourneyState::fromRaw('draft'));
        $this->assertSame(JourneyState::NOT_STARTED, JourneyState::fromRaw('pending'));
        $this->assertSame(JourneyState::IN_PROGRESS, JourneyState::fromRaw('streaming'));
        $this->assertSame(JourneyState::IN_PROGRESS, JourneyState::fromRaw('applying'));
        $this->assertSame(JourneyState::COMPLETE, JourneyState::fromRaw('ok'));
        $this->assertSame(JourneyState::NEEDS_ATTENTION, JourneyState::fromRaw('paused'));
        $this->assertSame(JourneyState::NEEDS_ATTENTION, JourneyState::fromRaw('lagging'));
        $this->assertSame(JourneyState::BLOCKED, JourneyState::fromRaw('failed'));
        $this->assertSame(JourneyState::BLOCKED, JourneyState::fromRaw('tampered'));

        // Unknown input degrades to NOT_STARTED, never to a success state.
        $this->assertSame(JourneyState::NOT_STARTED, JourneyState::fromRaw('something_new'));
        $this->assertSame(JourneyState::NOT_STARTED, JourneyState::fromRaw(null));
        $this->assertSame(JourneyState::NOT_STARTED, JourneyState::fromRaw(''));
    }

    public function test_only_blocked_is_blocking_and_only_settled_states_count_as_done(): void
    {
        foreach (JourneyState::cases() as $state) {
            $this->assertSame($state === JourneyState::BLOCKED, $state->isBlocking());
        }

        $this->assertTrue(JourneyState::COMPLETE->isSettled());
        $this->assertTrue(JourneyState::READY->isSettled());
        $this->assertFalse(JourneyState::IN_PROGRESS->isSettled());
        $this->assertFalse(JourneyState::BLOCKED->isSettled());
    }

    /**
     * Every icon referenced anywhere in the 0.4.0 product layer must resolve.
     *
     * Scans the source rather than a hand-maintained list, so a new icon added
     * to a nav item or a page is validated automatically.
     *
     * Only QUOTED string literals are matched, and doc comments are stripped
     * first — otherwise the comment explaining a past bad icon would itself be
     * reported as a bad icon.
     */
    public function test_no_invalid_heroicon_is_referenced_in_the_product_layer(): void
    {
        $installed = $this->installedOutlineIcons();
        $paths = [
            app_path('Filament/Support/ProductNavigation.php'),
            app_path('Filament/Support/ControlPlaneChrome.php'),
            app_path('Filament/Support/NexusAi.php'),
            app_path('Filament/Pages/Workspaces.php'),
            app_path('Filament/Pages/WorkspaceDetail.php'),
            app_path('Filament/Pages/NexusAi.php'),
            app_path('Filament/Pages/Dashboard.php'),
            app_path('Services/Product'),
            resource_path('views/filament/pages'),
        ];

        $found = [];
        foreach ($paths as $path) {
            $files = is_dir($path)
                ? array_merge(glob($path.'/*.php') ?: [], glob($path.'/*.blade.php') ?: [])
                : (file_exists($path) ? [$path] : []);

            foreach ($files as $file) {
                $contents = (string) file_get_contents($file);

                // Strip block and line comments so prose cannot fail the scan.
                $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
                $contents = preg_replace('#(^|\s)//[^\n]*#', '$1', $contents) ?? $contents;
                $contents = preg_replace('#(^|\s)\{\{--.*?--\}\}#s', '$1', $contents) ?? $contents;

                // Only 'heroicon-...' / "heroicon-..." literals count.
                if (preg_match_all('#["\'](heroicon-[a-z0-9-]+)["\']#', $contents, $matches)) {
                    foreach ($matches[1] as $icon) {
                        $found[$icon][] = basename($file);
                    }
                }
            }
        }

        $this->assertNotEmpty($found, 'No icon literals found — the scanner is not looking at the right files.');

        $bad = [];
        foreach ($found as $icon => $sources) {
            if (! in_array($icon, $installed, true)) {
                $bad[] = $icon.' (in '.implode(', ', array_unique($sources)).')';
            }
        }

        $this->assertSame([], $bad, "These Heroicons do not exist and would 500 at render time:\n  ".implode("\n  ", $bad));
    }
}
