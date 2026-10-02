<?php

namespace Tests\Feature\Phase40;

use App\Models\MigrationSource;
use App\Models\Project;
use App\Models\ReadinessCheck;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Product\JourneyStage;
use App\Services\Product\JourneyState;
use App\Services\Product\PlatformPulse;
use App\Services\Product\ProjectPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 Phase D (§6/§7/§9) — the journey model, the platform dashboard, and the
 * redirects that close the baseline dead-link findings.
 */
class DashboardTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
    }

    // ── Journey derivation ─────────────────────────────────────────────

    public function test_a_brand_new_project_is_not_started_with_zero_progress(): void
    {
        $pulse = ProjectPulse::for($this->alphaWeb);

        $this->assertSame(JourneyState::NOT_STARTED, $pulse->stageState(JourneyStage::CONNECT));
        $this->assertSame(0, $pulse->progressPercent());
        $this->assertSame(JourneyStage::CONNECT, $pulse->currentStage());
    }

    public function test_journey_covers_all_seven_stages_in_order(): void
    {
        $journey = ProjectPulse::for($this->alphaWeb)->journey();

        $this->assertCount(7, $journey);
        $this->assertSame(JourneyStage::CONNECT, $journey[0]['stage']);
        $this->assertSame(JourneyStage::CUTOVER, $journey[6]['stage']);

        foreach ($journey as $step) {
            $this->assertInstanceOf(JourneyState::class, $step['state']);
            $this->assertNotSame('', $step['detail'], 'Every stage must explain itself.');
        }
    }

    public function test_progress_advances_as_the_journey_completes(): void
    {
        $before = ProjectPulse::for($this->alphaWeb)->progressPercent();

        // Connect a source: the first stage becomes COMPLETE.
        MigrationSource::create([
            'project_id' => $this->alphaWeb->id,
            'display_name' => 'Prod MySQL',
            'type' => 'mysql',
            'status' => 'ready',
            'read_only' => true,
        ]);

        $pulse = ProjectPulse::for($this->alphaWeb->fresh());

        $this->assertSame(JourneyState::COMPLETE, $pulse->stageState(JourneyStage::CONNECT));
        $this->assertGreaterThan($before, $pulse->progressPercent());
        $this->assertLessThanOrEqual(100, $pulse->progressPercent());
    }

    public function test_a_failed_source_degrades_connect_rather_than_showing_green(): void
    {
        MigrationSource::create([
            'project_id' => $this->alphaWeb->id,
            'display_name' => 'Broken Source',
            'type' => 'mysql',
            'status' => 'failed',
            'read_only' => true,
        ]);

        $this->assertSame(
            JourneyState::NEEDS_ATTENTION,
            ProjectPulse::for($this->alphaWeb->fresh())->stageState(JourneyStage::CONNECT),
        );
    }

    public function test_an_unhealthy_project_reports_blocked_overall(): void
    {
        $this->alphaWeb->forceFill(['health_status' => 'unhealthy'])->save();

        $this->assertSame(JourneyState::BLOCKED, ProjectPulse::for($this->alphaWeb->fresh())->overallState());
    }

    public function test_live_sync_language_is_product_facing_not_internal(): void
    {
        $sync = ProjectPulse::for($this->alphaWeb)->liveSync();

        // §14: "Live Sync", never "CDC", and never a raw internal token.
        $this->assertSame('Not running', $sync['label']);
        $this->assertStringNotContainsStringIgnoringCase('cdc', $sync['detail']);
        $this->assertStringNotContainsStringIgnoringCase('lsn', $sync['detail']);
        $this->assertNull($sync['lagSeconds']);
    }

    public function test_blockers_are_empty_for_a_fresh_project(): void
    {
        $pulse = ProjectPulse::for($this->alphaWeb);

        // A project that has not started is not "blocked" — that distinction
        // matters, otherwise every new project looks like a failure.
        $this->assertSame([], $pulse->blockers());
    }

    public function test_an_unacknowledged_blocking_readiness_check_blocks_cutover(): void
    {
        ReadinessCheck::create([
            'project_id' => $this->alphaWeb->id,
            'category' => 'data',
            'check_key' => 'row_counts_match',
            'title' => 'Row counts do not match',
            'status' => 'failed',
            'blocks_production' => true,
            'detail' => 'orders differs by 42 rows.',
        ]);

        $pulse = ProjectPulse::for($this->alphaWeb->fresh());

        $this->assertSame(JourneyState::BLOCKED, $pulse->stageState(JourneyStage::CUTOVER));

        $blockers = $pulse->blockers();
        $this->assertNotEmpty($blockers);
        $this->assertSame('Row counts do not match', (string) $blockers[0]['title']);
        // §10: the user must be told WHY, not just that it is blocked.
        $this->assertStringContainsString('42 rows', (string) $blockers[0]['detail']);
    }

    public function test_acknowledging_a_blocking_check_clears_the_block(): void
    {
        $check = ReadinessCheck::create([
            'project_id' => $this->alphaWeb->id,
            'category' => 'data',
            'check_key' => 'row_counts_match',
            'title' => 'Row counts do not match',
            'status' => 'failed',
            'blocks_production' => true,
        ]);

        $this->assertNotEmpty(ProjectPulse::for($this->alphaWeb->fresh())->blockers());

        $check->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $this->platformOwner->id])->save();

        $this->assertSame([], ProjectPulse::for($this->alphaWeb->fresh())->blockers());
    }

    // ── Platform pulse scoping (the security-relevant part) ─────────────

    public function test_platform_summary_counts_only_projects_the_user_can_reach(): void
    {
        // Platform owner sees both tenants' projects: alpha-web, alpha-booking,
        // beta-crm = 3.
        $owner = PlatformPulse::for(Access::for($this->platformOwner));
        $this->assertSame(3, $owner->projects()->count());

        // Alpha's owner sees only their two.
        $alpha = PlatformPulse::for(Access::for($this->alphaOwner));
        $this->assertSame(2, $alpha->projects()->count());

        // A project-only member sees exactly one.
        $dev = PlatformPulse::for(Access::for($this->alphaDevOnWeb));
        $this->assertSame(1, $dev->projects()->count());
    }

    public function test_platform_summary_never_leaks_another_tenants_attention_items(): void
    {
        // Make BETA's project unhealthy. Alpha must not be told about it.
        $this->betaCrm->forceFill(['health_status' => 'unhealthy'])->save();

        $alpha = PlatformPulse::for(Access::for($this->alphaOwner));

        $names = $alpha->projectsNeedingAttention()->map(fn ($r) => $r['project']->name);
        $this->assertNotContains('Beta CRM', $names->all());

        // The platform owner DOES see it.
        $owner = PlatformPulse::for(Access::for($this->platformOwner));
        $this->assertContains('Beta CRM', $owner->projectsNeedingAttention()->map(fn ($r) => $r['project']->name)->all());
    }

    public function test_platform_summary_has_exactly_five_figures_each_with_context(): void
    {
        $summary = PlatformPulse::for(Access::for($this->platformOwner))->summary();

        $this->assertCount(5, $summary);
        foreach ($summary as $item) {
            // §7: no bare numbers — every figure carries a hint.
            $this->assertNotSame('', $item['hint']);
            $this->assertNotSame('', $item['label']);
            $this->assertIsInt($item['value']);
        }
    }

    public function test_attention_count_is_neutral_when_zero_not_a_celebratory_green(): void
    {
        $summary = collect(PlatformPulse::for(Access::for($this->platformOwner))->summary())
            ->keyBy('key');

        $this->assertSame(0, $summary['needs_attention']['value']);
        $this->assertSame('neutral', $summary['needs_attention']['tone']);
    }

    // ── HTTP surface ───────────────────────────────────────────────────

    public function test_home_renders_for_a_platform_owner(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Start a migration')
            ->assertSee('Needs attention');
    }

    public function test_home_renders_for_a_tenant_user_without_leaking_other_tenants(): void
    {
        $response = $this->actingAs($this->betaOwner)->get('/admin');

        $response->assertOk();
        $response->assertSee('Beta CRM');
        $response->assertDontSee('Alpha Website');
        $response->assertDontSee('Alpha Booking');
    }

    public function test_home_shows_the_first_run_empty_state_when_there_are_no_projects(): void
    {
        Project::query()->delete();

        $this->actingAs($this->platformOwner)
            ->get('/admin')
            ->assertOk()
            ->assertSee('No projects yet');
    }

    public function test_home_never_renders_a_raw_internal_status_token(): void
    {
        $html = $this->actingAs($this->platformOwner)->get('/admin')->getContent();

        // §14: these belong under Advanced, never on the first screen.
        foreach (['LSN', 'checkpoint position', 'CDC'] as $token) {
            $this->assertStringNotContainsString(
                $token,
                $html,
                "Home leaked the internal term '{$token}'.",
            );
        }
    }

    // ── Baseline dead links (B1/B2) ────────────────────────────────────

    public function test_the_two_dead_admin_links_now_redirect(): void
    {
        // These returned 404 in v0.3.0; the audit recorded them as B1/B2.
        $this->actingAs($this->platformOwner)
            ->get('/admin/team-management')
            ->assertRedirect('/admin/team');

        $this->actingAs($this->platformOwner)
            ->get('/admin/project-switcher')
            ->assertRedirect('/admin/switcher');
    }

    public function test_the_redirect_targets_actually_resolve(): void
    {
        $this->actingAs($this->platformOwner)->get('/admin/team')->assertOk();
        $this->actingAs($this->platformOwner)->get('/admin/switcher')->assertOk();
    }

    // ── rc.2: pluralization and the floating-controls layout ──────────

    #[\PHPUnit\Framework\Attributes\DataProvider('attentionCounts')]
    public function test_the_attention_phrase_agrees_in_number(int $count, string $expected): void
    {
        $this->assertSame($expected, \App\Filament\Pages\Dashboard::projectAttentionPhrase($count));
    }

    public static function attentionCounts(): array
    {
        return [
            'zero projects' => [0, ''],
            'one project' => [1, '1 project needs attention'],
            'two projects' => [2, '2 projects need attention'],
            'eleven projects' => [11, '11 projects need attention'],
        ];
    }

    public function test_the_launcher_and_the_inspect_toggle_share_one_floating_row(): void
    {
        // rc.1 finding: the two fixed-position controls overlapped when the
        // launcher's scope label was long. They now live in one flex
        // container — asserted here structurally; the interaction contract
        // (both independently clickable, no intersection) is proven in the
        // real-browser QA.
        $html = $this->actingAs($this->platformOwner)->get('/admin')->getContent();

        $open = strpos((string) $html, '<div class="nx-floating-controls">');
        $this->assertNotFalse($open, 'the floating controls container is missing');

        $launcher = strpos((string) $html, 'class="nx-ai-launcher"', $open);
        $toggle = strpos((string) $html, 'data-nx-inspect-toggle', $open);
        $close = strpos((string) $html, '</div>', (int) $open);

        $this->assertNotFalse($launcher, 'the launcher is not inside the floating container');
        $this->assertNotFalse($toggle, 'the Inspect toggle is not inside the floating container');
        $this->assertLessThan($close, $launcher);
        $this->assertLessThan($close, $toggle);
    }
}
