<?php

namespace Tests\Feature\Phase24;

use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Models\SchemaSnapshot;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\EnvironmentService;
use App\Services\ControlPlane\SchemaDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24C — Environment Manager: defaults, isolation, switch, promotion guard. */
class EnvironmentsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
    }

    public function test_defaults_are_created_with_correct_types(): void
    {
        EnvironmentService::ensureDefaults($this->projectA);

        $types = $this->projectA->environments()->pluck('type')->sort()->values()->all();
        $this->assertSame(['development', 'production', 'staging'], $types);
        $this->assertTrue($this->projectA->environments()->where('type', 'development')->value('is_default') === 1
            || $this->projectA->environments()->where('type', 'development')->first()->is_default);
    }

    public function test_environments_are_isolated_by_namespace(): void
    {
        EnvironmentService::ensureDefaults($this->projectA);
        $dev = $this->projectA->environments()->where('type', 'development')->first();
        $prod = $this->projectA->environments()->where('type', 'production')->first();

        // Redis namespaces differ per environment (prefix isolation).
        $this->assertNotSame(
            EnvironmentService::redisNamespace($this->projectA, $dev),
            EnvironmentService::redisNamespace($this->projectA, $prod)
        );
        $this->assertNotSame($dev->storage_namespace, $prod->storage_namespace);
        $this->assertNotSame($dev->realtime_namespace, $prod->realtime_namespace);
        $this->assertNotSame($dev->id, $prod->id);
    }

    public function test_switch_updates_context_and_audits(): void
    {
        $this->actingAs($this->admin);
        EnvironmentService::ensureDefaults($this->projectA);
        $prod = $this->projectA->environments()->where('type', 'production')->first();
        $prod->update(['status' => 'active']);

        $this->assertTrue(EnvironmentContext::switch($this->projectA, $prod->id));
        $this->assertSame($prod->id, EnvironmentContext::active($this->projectA)->id);

        $this->assertDatabaseHas('admin_audit_entries', [
            'project_id' => $this->projectA->id, 'action' => 'ENVIRONMENT_SWITCHED',
        ]);
    }

    public function test_inactive_environment_cannot_become_active_context(): void
    {
        $this->actingAs($this->admin);
        EnvironmentService::ensureDefaults($this->projectA);
        $staging = $this->projectA->environments()->where('type', 'staging')->first(); // inactive by default

        $this->assertFalse(EnvironmentContext::switch($this->projectA, $staging->id));
        $this->assertSame('development', EnvironmentContext::active($this->projectA)->type);
    }

    public function test_environment_type_cannot_be_mutated_to_escape_guards(): void
    {
        $this->actingAs($this->admin);
        EnvironmentService::ensureDefaults($this->projectA);
        $prod = $this->projectA->environments()->where('type', 'production')->first();

        EnvironmentService::update($prod, ['type' => 'development', 'name' => 'renamed']);
        $this->assertSame('production', $prod->fresh()->type, 'type change must be silently rejected');
        $this->assertSame('renamed', $prod->fresh()->name);
    }

    public function test_promotion_guard_requires_strong_confirmation(): void
    {
        $this->actingAs($this->admin);
        EnvironmentService::ensureDefaults($this->projectA);
        $dev = $this->projectA->environments()->where('type', 'development')->first();
        $prod = $this->projectA->environments()->where('type', 'production')->first();
        $prod->update(['status' => 'active']);

        $blocked = EnvironmentService::attemptPromotion($dev, $prod, true, ['confirmation' => 'yes sure']);
        $this->assertFalse($blocked['allowed']);

        $allowed = EnvironmentService::attemptPromotion($dev, $prod, true, [
            'confirmation' => 'PROMOTE '.$this->projectA->slug.' TO PRODUCTION',
        ]);
        $this->assertTrue($allowed['allowed']);

        $this->assertDatabaseHas('admin_audit_entries', [
            'project_id' => $this->projectA->id, 'action' => 'PROMOTION_ATTEMPTED',
        ]);
    }

    public function test_environment_diff_reports_fingerprint_and_secret_completeness(): void
    {
        $this->actingAs($this->admin);
        EnvironmentService::ensureDefaults($this->projectA);
        $dev = $this->projectA->environments()->where('type', 'development')->first();
        $staging = $this->projectA->environments()->where('type', 'staging')->first();

        $rows = EnvironmentService::diff($dev, $staging);
        $fields = collect($rows)->pluck('field')->all();
        $this->assertContains('schema_fingerprint', $fields);
        $this->assertContains('secrets_completeness', $fields);
        $this->assertContains('config', $fields);
    }

    public function test_secrets_are_environment_scoped(): void
    {
        $this->actingAs($this->admin);
        EnvironmentService::ensureDefaults($this->projectA);
        $dev = $this->projectA->environments()->where('type', 'development')->first();
        $staging = $this->projectA->environments()->where('type', 'staging')->first();

        ProjectSecret::create(['project_id' => $this->projectA->id, 'name' => 'DEV_ONLY', 'value' => 'v1', 'environment_id' => $dev->id]);
        ProjectSecret::create(['project_id' => $this->projectA->id, 'name' => 'STAGING_ONLY', 'value' => 'v2', 'environment_id' => $staging->id]);

        $devNames = ProjectSecret::where('environment_id', $dev->id)->pluck('name');
        $this->assertContains('DEV_ONLY', $devNames);
        $this->assertNotContains('STAGING_ONLY', $devNames);
    }
}
