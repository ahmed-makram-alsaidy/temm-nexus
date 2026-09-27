<?php

namespace Tests\Feature\Phase25;

use App\Models\ClientCallsite;
use App\Models\ClientRepository;
use App\Models\CopilotRun;
use App\Services\ControlPlane\Repository\ClientDependencyScanner;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Phase25\Concerns\BuildsPhase25Fixture;
use Tests\TestCase;

/** Phase 25K/L pages + 25 performance requirements. */
class Phase25PagesPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase25Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase25();
    }

    // ── Pages ───────────────────────────────────────────────────────────

    public function test_phase25_pages_render(): void
    {
        foreach (['client-repository', 'copilot', 'migration-center'] as $page) {
            $url = \App\Filament\Resources\Projects\ProjectResource::getUrl($page, ['record' => $this->projectA]);
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
        $this->actingAs($this->admin)->get('/admin/onboarding')->assertOk();
    }

    public function test_subnav_exposes_phase25_modules(): void
    {
        $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $this->projectA]);
        $content = (string) $this->actingAs($this->admin)->get($url)->getContent();
        $this->assertStringContainsString('client-repository', $content);
        $this->assertStringContainsString('copilot', $content);
    }

    public function test_guest_cannot_reach_phase25_pages(): void
    {
        auth()->logout(); // setUp acts as admin — drop the session for this check
        $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('copilot', ['record' => $this->projectA]);
        $response = $this->get($url);
        $this->assertContains($response->status(), [401, 403, 302], 'guest must not see the copilot page');
    }

    // ── Performance (25) ────────────────────────────────────────────────

    public function test_100_discovered_projects_mocked_parse_quickly(): void
    {
        $connection = \App\Connectors\Supabase\SupabaseAccountService::connect($this->admin, 'bulk', 'tok');
        $payload = [];
        for ($i = 0; $i < 100; $i++) {
            $payload[] = ['id' => 'ref'.$i, 'name' => 'Project '.$i, 'region' => 'eu-west-1', 'status' => 'ACTIVE_HEALTHY', 'organization_id' => 'org'.$i % 5];
        }
        Http::fake(['api.supabase.com/v1/projects' => Http::response($payload, 200)]);

        $started = microtime(true);
        $projects = \App\Connectors\Supabase\SupabaseAccountService::discoverProjects($connection);
        $elapsed = microtime(true) - $started;

        $this->assertCount(100, $projects);
        $this->assertLessThan(2.0, $elapsed, "discovery parse of 100 projects took {$elapsed}s");
    }

    public function test_10k_callsites_paginate_quickly(): void
    {
        $repo = ClientRepositoryService::linkLocal($this->projectA, 'bulk repo', $this->buildFixtureRepo());
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = [
                'client_repository_id' => $repo->id, 'file' => 'lib/f'.($i % 500).'.dart', 'line' => $i % 400,
                'category' => ['auth', 'database', 'rpc', 'functions', 'storage', 'realtime'][$i % 6],
                'target' => 't'.$i, 'language' => 'dart', 'confidence' => 'high', 'status' => 'DISCOVERED',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('client_callsites')->insert($chunk);
        }

        $started = microtime(true);
        $page = ClientCallsite::where('client_repository_id', $repo->id)->where('category', 'rpc')->orderBy('file')->limit(50)->get();
        $elapsed = microtime(true) - $started;

        $this->assertCount(50, $page);
        $this->assertLessThan(2.0, $elapsed, "paged read over 10k callsites took {$elapsed}s");

        // Scanner manifest respects limits.
        $manifest = ClientDependencyScanner::manifest($repo, 100);
        $this->assertCount(100, $manifest);
    }

    public function test_100_copilot_history_records_render_bound(): void
    {
        for ($i = 0; $i < 100; $i++) {
            CopilotRun::create([
                'project_id' => $this->projectA->id, 'run_id' => 'run-'.$i, 'mode' => 'advisor',
                'action' => 'explain_blockers', 'status' => 'completed', 'result' => ['summary' => 'ok'],
            ]);
        }
        $started = microtime(true);
        $page = CopilotRun::where('project_id', $this->projectA->id)->orderByDesc('id')->limit(12)->get();
        $elapsed = microtime(true) - $started;

        $this->assertCount(12, $page);
        $this->assertLessThan(1.0, $elapsed);
    }
}
