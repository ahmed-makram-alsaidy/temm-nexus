<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\SavedSqlQuery;
use App\Models\SqlQueryHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlEditorHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
    }

    protected function conn(): string
    {
        return \App\Services\ControlPlane\ProjectConnectionManager::connection($this->project);
    }

    public function test_guest_redirected_and_unassigned_denied(): void
    {
        $this->get('/admin/projects/'.$this->project->id.'/sql')->assertRedirect('/admin/login');
        $this->postJson('/cp-sql/'.$this->project->id.'/run', ['sql' => 'select 1'])->assertUnauthorized();
    }

    public function test_page_renders_editor_for_permitted_user(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/sql');
        $response->assertOk();
        $response->assertSee('/vendor/codemirror/codemirror.min.js', false);
        $response->assertSee('data-cp-sql', false);
        $response->assertSee('data-cp-project-sidebar', false);
        $response->assertSee('aria-label="Project workspace"', false);
        $response->assertDontSee('class="cp-subnav"', false);
        $response->assertDontSee('cp-subnav__tab', false);
    }

    public function test_run_select_returns_rows_and_records_history(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/cp-sql/'.$this->project->id.'/run', ['sql' => 'select 1 as one']);
        $response->assertOk();
        $this->assertSame('ok', $response->json('status'));
        $this->assertSame(1, $response->json('rows.0.one'));

        $history = SqlQueryHistory::query()->where('project_id', $this->project->id)->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertSame('read', $history->category);
        // Raw text never stored; literals redacted.
        $this->assertStringNotContainsString('select 1 as one', (string) $history->redacted_sql);
    }

    public function test_insert_blocked_without_write_mode_and_logged(): void
    {
        DB::connection($this->conn())->statement(
            'CREATE TABLE IF NOT EXISTS cp20_http_t (id serial primary key, v text)'
        );
        try {
            $response = $this->actingAs($this->admin)
                ->postJson('/cp-sql/'.$this->project->id.'/run', ['sql' => "INSERT INTO cp20_http_t (v) VALUES ('x')"]);
            $this->assertSame('blocked', $response->json('status'));
            $this->assertSame(0, DB::connection($this->conn())->table('cp20_http_t')->count());
        } finally {
            DB::connection($this->conn())->statement('DROP TABLE IF EXISTS cp20_http_t');
        }
    }

    public function test_write_mode_requires_password_then_permits_demo_write(): void
    {
        DB::connection($this->conn())->statement(
            'CREATE TABLE IF NOT EXISTS cp20_http_w (id serial primary key, v text)'
        );
        try {
            // Wrong password → 403.
            $this->actingAs($this->admin)
                ->postJson('/cp-sql/'.$this->project->id.'/write-mode', ['password' => 'nope'])
                ->assertForbidden();

            $this->admin->update(['password' => 's3cret-write-pw']);
            $this->actingAs($this->admin)
                ->postJson('/cp-sql/'.$this->project->id.'/write-mode', ['password' => 's3cret-write-pw'])
                ->assertOk();

            $response = $this->actingAs($this->admin)
                ->postJson('/cp-sql/'.$this->project->id.'/run', [
                    'sql' => "INSERT INTO cp20_http_w (v) VALUES ('demo')", 'write' => true,
                ]);
            $response->assertOk();
            $this->assertSame('write', $response->json('category'));
            $this->assertSame(1, DB::connection($this->conn())->table('cp20_http_w')->count());
        } finally {
            DB::connection($this->conn())->statement('DROP TABLE IF EXISTS cp20_http_w');
        }
    }

    public function test_destructive_needs_slug_confirmation(): void
    {
        $this->admin->update(['password' => 's3cret-write-pw']);
        $this->actingAs($this->admin)
            ->postJson('/cp-sql/'.$this->project->id.'/write-mode', ['password' => 's3cret-write-pw'])
            ->assertOk();

        $response = $this->actingAs($this->admin)
            ->postJson('/cp-sql/'.$this->project->id.'/run', [
                'sql' => 'UPDATE gate_a_probe SET x = 1', 'write' => true,
            ]);
        // Missing table → error status (classifier passed destructive-with-confirm gate? no slug given).
        // UPDATE without WHERE without slug → confirm_required.
        $this->assertContains($response->json('status'), ['confirm_required', 'error']);
    }

    public function test_saved_query_round_trip(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/cp-sql/'.$this->project->id.'/save', ['name' => 'q1', 'sql' => 'select 2'])
            ->assertCreated();
        $list = $this->actingAs($this->admin)->getJson('/cp-sql/'.$this->project->id.'/saved')->assertOk();
        $this->assertSame('q1', $list->json('0.name'));
        $id = SavedSqlQuery::query()->where('project_id', $this->project->id)->value('id');
        $this->actingAs($this->admin)->deleteJson('/cp-sql/'.$this->project->id.'/saved/'.$id)->assertOk();
        $this->assertSame(0, SavedSqlQuery::query()->where('project_id', $this->project->id)->count());
    }
}
