<?php

namespace Tests\Feature\Phase26;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\SetupState;
use App\Services\Platform\SystemCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26D/26E — first-run setup wizard: gate behavior, bootstrap safety,
 * setup lock, replay defense, race defense, password policy, system check.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Fresh instance: no users, no settings. */
    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase migrated; ensure a truly uninitialized platform.
        User::query()->delete();
        PlatformSetting::query()->delete();
        \Illuminate\Support\Facades\Cache::forget('platform.initialized');
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Cache::forget('platform.initialized');
        parent::tearDown();
    }

    protected function runWizard(): void
    {
        $this->post('/setup/step/1');
        $this->post('/setup/step/2', [
            'platform_name' => 'Test Plane',
            'brand_name' => 'Test Plane',
            'support_url' => 'https://support.example.test',
        ]);
        $this->post('/setup/step/6', [
            'admin_name' => 'Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'Str0ng!Passphrase#42',
            'admin_password_confirmation' => 'Str0ng!Passphrase#42',
        ]);
        $this->post('/setup/step/7', ['platform_url' => 'https://panel.example.test']);
        $this->post('/setup/step/8', ['mail_configured' => '0']);
        $this->post('/setup/step/9');
        $this->post('/setup/step/10', ['ai_enabled' => '0']);
        $this->post('/setup/step/11', ['security_ack' => '1']);
        $this->post('/setup/step/12');
    }

    // ── 26D gate ─────────────────────────────────────────────────────────

    public function test_fresh_instance_redirects_root_to_setup(): void
    {
        $this->get('/')->assertRedirect('/setup');
    }

    public function test_fresh_instance_blocks_admin_login(): void
    {
        $this->get('/admin/login')->assertRedirect('/setup');
    }

    public function test_fresh_instance_keeps_health_probes_reachable(): void
    {
        $this->get('/api/health')->assertStatus(200);
        $this->get('/up')->assertOk();
    }

    public function test_uninitialized_function_invoke_is_blocked(): void
    {
        $this->post('/f/some-project/some-fn')->assertRedirect('/setup');
    }

    public function test_setup_wizard_renders_system_check(): void
    {
        $response = $this->get('/setup/step/1');
        $response->assertOk();
        $response->assertSee('System Check');
    }

    // ── 26E bootstrap ────────────────────────────────────────────────────

    public function test_full_wizard_creates_exactly_one_platform_owner(): void
    {
        $this->runWizard();

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $admin = User::query()->where('is_admin', true)->first();
        $this->assertSame('owner@example.test', $admin->email);
        $this->assertSame('owner', $admin->cp_role);
        $this->assertSame('Test Plane', SetupState::get('platform.name'));
        $this->assertSame('1', SetupState::get(SetupState::KEY_COMPLETED));
        // Pending credentials are cleaned up after bootstrap.
        foreach (['setup.pending_admin_name', 'setup.pending_admin_email', 'setup.pending_admin_hash'] as $k) {
            $this->assertNull(SetupState::get($k), "{$k} must not survive bootstrap");
        }
    }

    public function test_bootstrapped_admin_can_reach_dashboard(): void
    {
        $this->runWizard();

        $admin = User::query()->where('is_admin', true)->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Str0ng!Passphrase#42', $admin->password), 'bootstrap password must verify');
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->from('/setup/step/6')->post('/setup/step/6', [
            'admin_name' => 'Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ])->assertSessionHasErrors(['admin_password']);

        $this->assertSame(0, User::count());
    }

    public function test_default_style_admin_email_is_rejected(): void
    {
        $this->post('/setup/step/6', [
            'admin_name' => 'Admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'Str0ng!Passphrase#42',
            'admin_password_confirmation' => 'Str0ng!Passphrase#42',
        ])->assertSessionHasErrors(['admin_email']);
    }

    // ── 26D.4 idempotence ────────────────────────────────────────────────

    public function test_replaying_wizard_steps_does_not_duplicate_admin_or_settings(): void
    {
        $this->runWizard();
        $this->runWizard(); // full replay after completion

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $this->assertSame('Test Plane', SetupState::get('platform.name'));
    }

    public function test_app_key_is_never_regenerated_by_setup(): void
    {
        $keyBefore = config('app.key');
        $this->runWizard();

        $this->assertSame($keyBefore, config('app.key'));
    }

    // ── 26D.5 setup lock + replay defense ────────────────────────────────

    public function test_setup_is_locked_after_completion(): void
    {
        $this->runWizard();

        $this->get('/setup')->assertRedirect('/admin');
        $this->get('/setup/step/1')->assertRedirect('/admin');
    }

    public function test_replay_of_completion_after_lock_redirects_without_duplicates(): void
    {
        $this->runWizard();

        // The middleware lock (initialized platform) bounces a replayed
        // completion POST before the controller ever sees it.
        $this->post('/setup/step/12')->assertRedirect('/admin');

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
    }

    // ── 26I.4 race defense ───────────────────────────────────────────────

    public function test_bootstrap_under_held_lock_fails_without_creating_users(): void
    {
        $this->post('/setup/step/6', [
            'admin_name' => 'Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'Str0ng!Passphrase#42',
            'admin_password_confirmation' => 'Str0ng!Passphrase#42',
        ]);

        $lock = \Illuminate\Support\Facades\Cache::lock(SetupState::LOCK, 10);
        $lock->get();

        $failed = false;
        try {
            SetupState::complete('Racer', 'racer@example.test', 'Str0ng!Passphrase#42');
        } catch (\App\Services\Platform\SetupLockedException) {
            $failed = true;
        } finally {
            $lock->release();
        }

        $this->assertTrue($failed, 'a locked bootstrap must be refused');
        $this->assertSame(0, User::count(), 'a locked bootstrap must not create users');
    }

    public function test_concurrent_completions_create_exactly_one_owner(): void
    {
        $this->post('/setup/step/6', [
            'admin_name' => 'Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'Str0ng!Passphrase#42',
            'admin_password_confirmation' => 'Str0ng!Passphrase#42',
        ]);

        // First completion wins; a second concurrent bootstrap is rejected.
        SetupState::complete('Owner', 'owner@example.test', 'Str0ng!Passphrase#42', [], preHashed: false);

        $rejected = false;
        try {
            SetupState::complete('Second', 'second@example.test', 'Str0ng!Passphrase#42');
        } catch (\App\Services\Platform\SetupCompletedException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'second concurrent bootstrap must be rejected');
        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $this->assertSame('owner@example.test', User::query()->where('is_admin', true)->first()->email);
    }

    public function test_existing_admin_prevents_second_bootstrap(): void
    {
        User::factory()->create(['is_admin' => true, 'email' => 'legacy@example.test']);
        \Illuminate\Support\Facades\Cache::forget('platform.initialized');

        $this->expectException(\App\Services\Platform\SetupCompletedException::class);
        SetupState::complete('X', 'x@example.test', 'Str0ng!Passphrase#42');
    }

    // ── 26D.3 system check ───────────────────────────────────────────────

    public function test_system_check_reports_all_expectations_and_passes_on_test_stack(): void
    {
        $checks = SystemCheck::run();
        $keys = array_column($checks, 'key');

        foreach (['php', 'extensions', 'app_key', 'database', 'migrations', 'redis', 'storage', 'queue'] as $expected) {
            $this->assertContains($expected, $keys);
        }
        foreach ($checks as $c) {
            $this->assertContains($c['status'], ['PASS', 'FAIL', 'INFO'], $c['label'].' status invalid');
            $this->assertNotSame('', $c['detail'], $c['label'].' must carry an actionable detail');
        }
        $this->assertFalse(SystemCheck::blockingFailed($checks), 'blocking checks must pass on a healthy test stack');
    }

    // ── 26D.5 authorized reopen ──────────────────────────────────────────

    public function test_setup_reset_command_requires_confirmation(): void
    {
        $this->runWizard();

        $this->artisan('platform:setup-reset', [])
            ->expectsConfirmation('Reopening setup temporarily unlocks first-run configuration. Admin users and data are preserved. Type confirmation below to continue.', 'no')
            ->assertFailed();

        $this->assertTrue(SetupState::initialized());
    }

    public function test_setup_reset_command_unlocks_when_confirmed(): void
    {
        $this->runWizard();

        $this->artisan('platform:setup-reset', [])
            ->expectsConfirmation('Reopening setup temporarily unlocks first-run configuration. Admin users and data are preserved. Type confirmation below to continue.', 'yes')
            ->expectsQuestion('Type RESET to confirm', 'RESET')
            ->assertSuccessful();

        // Note: an admin exists, so the platform stays operational (never
        // lock an operator out of a working console) — but the flag is gone.
        $this->assertNull(SetupState::get(SetupState::KEY_COMPLETED));
    }
}
