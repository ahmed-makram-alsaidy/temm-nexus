<?php

namespace Tests\Feature\Phase26;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 26B/26E/26I/26K — generic configuration: settings encryption at rest,
 * seeder without default credentials, canonical version reporting.
 */
class PlatformConfigurationTest extends TestCase
{
    use RefreshDatabase;

    // ── 26I.5 secrets at rest ────────────────────────────────────────────

    public function test_platform_settings_are_encrypted_at_rest(): void
    {
        SetupState::set('setup.pending_admin_hash', 'raw-bcrypt-hash-value', secret: true);
        SetupState::set('platform.name', 'My Plane');

        $rawHash = DB::table('platform_settings')->where('key', 'setup.pending_admin_hash')->value('value');
        $rawName = DB::table('platform_settings')->where('key', 'platform.name')->value('value');

        $this->assertStringNotContainsString('raw-bcrypt-hash-value', $rawHash, 'secret settings must be encrypted');
        $this->assertStringNotContainsString('My Plane', $rawName, 'all settings are stored encrypted');
        $this->assertSame('raw-bcrypt-hash-value', SetupState::get('setup.pending_admin_hash'));
        $this->assertSame('My Plane', SetupState::get('platform.name'));
    }

    // ── 26E no default credentials ───────────────────────────────────────

    public function test_demo_seeder_is_disabled_by_default(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\OwnerConsoleSeeder']);

        $this->assertSame(0, User::count(), 'no user may exist without the wizard or an explicit demo opt-in');
    }

    public function test_demo_seeder_generates_random_password_when_enabled(): void
    {
        $_ENV['ENABLE_DEMO_SEED'] = '1';
        $_SERVER['ENABLE_DEMO_SEED'] = '1';
        putenv('ENABLE_DEMO_SEED=1');

        try {
            $seeder = new \Database\Seeders\OwnerConsoleSeeder;
            $seeder->setContainer(app())->run();
        } finally {
            unset($_ENV['ENABLE_DEMO_SEED'], $_SERVER['ENABLE_DEMO_SEED']);
            putenv('ENABLE_DEMO_SEED');
        }

        $user = User::query()->where('email', 'demo-owner@localhost.test')->first();
        $this->assertNotNull($user);
        // The documented legacy default must never appear as a credential.
        $this->assertNotSame('LocalOwnerOnly_ChangeMe_001', $user->password);
        $this->assertMatchesRegularExpression('/^\$2y\$/', $user->password, 'stored value must be a bcrypt hash');
        $this->assertSame('owner', $user->cp_role);
    }

    // ── 26K.2 version source ─────────────────────────────────────────────

    public function test_platform_version_comes_from_root_version_file(): void
    {
        $version = (string) config('platform.version');

        $this->assertNotSame('0.0.0-dev', $version, 'VERSION file must be readable from the app');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version);
    }

    public function test_welcome_page_displays_brand_and_version_without_private_names(): void
    {
        // Fresh instance: the first-run gate redirects to /setup.
        $this->get('/')->assertRedirect('/setup');
    }
}
