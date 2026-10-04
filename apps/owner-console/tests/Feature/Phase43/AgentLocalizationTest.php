<?php

namespace Tests\Feature\Phase43;

use App\Models\User;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Localization gates for the Phase 43 surfaces: complete EN/AR key parity,
 * real Arabic script, and no raw translation keys leaking into rendered HTML.
 */
class AgentLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function flatten(array $items, string $prefix = ''): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    public function test_agents_translations_have_full_en_ar_parity(): void
    {
        $en = $this->flatten(require lang_path('en/agents.php'));
        $ar = $this->flatten(require lang_path('ar/agents.php'));

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($ar))), 'Keys missing in Arabic.');
        $this->assertSame([], array_values(array_diff(array_keys($ar), array_keys($en))), 'Keys missing in English.');
        $this->assertGreaterThan(100, count($en));

        // Arabic must actually be Arabic.
        foreach ($ar as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "Empty Arabic value for {$key}.");
            $this->assertTrue(
                (bool) preg_match('/[\x{0600}-\x{06FF}]/u', (string) $value),
                "Arabic value for {$key} contains no Arabic script."
            );
        }
    }

    public function test_technical_identifiers_are_never_translated(): void
    {
        $en = $this->flatten(require lang_path('en/agents.php'));
        $ar = $this->flatten(require lang_path('ar/agents.php'));

        // Endpoint/model/id placeholders keep their technical tokens.
        $this->assertStringContainsString('OPENCODE_SERVER_PASSWORD', (string) $ar['managed_secret_missing']);
        $this->assertStringContainsString('provider/model', (string) $ar['default_model_helper']);
        $this->assertStringContainsString('OPENCODE_SERVER_PASSWORD', (string) $en['managed_secret_missing']);
    }

    protected function owner(): User
    {
        return User::create([
            'name' => 'Locale Owner',
            'email' => 'locale-'.Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    public function test_workbench_and_settings_render_without_raw_keys_in_english(): void
    {
        $this->actingAs($this->owner());

        $settings = $this->get('/admin/developer-agent-settings')->assertOk();
        $this->assertStringNotContainsString('agents.', $settings->getContent());
        $this->assertStringContainsString('Developer Agents', $settings->getContent());

        $workbench = $this->get('/admin/developer-agent')->assertOk();
        $this->assertStringNotContainsString('agents.', $workbench->getContent());
    }

    public function test_arabic_locale_renders_arabic_strings(): void
    {
        $owner = $this->owner();
        app()->setLocale('ar');
        $this->actingAs($owner);

        $settings = $this->get('/admin/developer-agent-settings')->assertOk();
        $content = $settings->getContent();
        $this->assertStringNotContainsString('agents.', $content);
        $this->assertTrue((bool) preg_match('/[\x{0600}-\x{06FF}]/u', $content), 'Arabic page contains no Arabic script.');
    }
}
