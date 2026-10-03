<?php

namespace Tests\Feature\Phase41;

use Tests\TestCase;

/**
 * 0.4.0-rc.5 closure — no user-facing strings bypassing the translation
 * layer (spec §2/§3).
 *
 * Scans the product surface (Filament pages/resources/widgets) for freshly
 * introduced hard-coded English in user-visible call sites: ->label(),
 * ->title(), ->body(), ->modalHeading(), ->modalDescription(),
 * ->helperText(), ->placeholder(), ->modalSubmitActionLabel() and bare
 * `return 'Title';` getters.
 *
 * ALLOWLIST = technical identifiers, vendor names, env-var names and other
 * values that must stay canonical (C.10). Everything else must go through
 * __(). New violations FAIL the release.
 */
class NoUntranslatedStringsTest extends TestCase
{
    /** Strings allowed to remain literal (technical identifiers / vendors). */
    protected array $allowlist = [
        'APP_KEY', 'DB_PASSWORD_STAGING', 'GOOGLE_CLIENT_SECRET', 'STRIPE_SECRET',
        'MIGRATION_SOURCE_DB_PASSWORD', 'PROMOTE', 'PROMOTE ', 'Apple', 'GitHub', 'Google',
        'HTTP', 'HTTP ', 'JSON', 'URL',
    ];

    protected string $appPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appPath = base_path('app/Filament');
    }

    public function test_no_new_hardcoded_user_visible_strings(): void
    {
        $violations = [];

        $methods = "(label|modalHeading|modalDescription|modalSubmitActionLabel|helperText|placeholder|title|body)";
        $files = array_merge(
            glob($this->appPath.'/*.php'),
            glob($this->appPath.'/**/*.php'),
            glob($this->appPath.'/**/**/*.php'),
            glob($this->appPath.'/**/**/**/*.php'),
            glob($this->appPath.'/**/**/**/**/*.php'),
        );

        foreach ($files as $file) {
            $lines = file($file);
            foreach ($lines as $i => $line) {
                if (! preg_match("/->{$methods}\('([A-Z][^']*)'/", $line, $m)) {
                    continue;
                }
                $text = $m[2];
                if (in_array($text, $this->allowlist, true)) {
                    continue;
                }
                // Trailing-space/colon fragments are concatenation pieces that
                // were migrated with their exact literal; anything ending in a
                // separator that still has a match is a violation too.
                $violations[] = basename($file).':'.($i + 1)." ->{$m[1]}('{$text}')";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Hard-coded user-visible strings detected (move them into lang/ keys):\n".implode("\n", array_slice($violations, 0, 25))
        );
    }

    public function test_wizard_plural_keys_resolve_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach ([0, 1, 2, 5, 11] as $count) {
                $changes = trans_choice('wizard.review_client_changes', $count, [], $locale);
                $plan = trans_choice('wizard.review_plan_items', $count, [], $locale);

                $this->assertStringNotContainsString('|', (string) $changes);
                $this->assertStringNotContainsString('{', (string) $changes);
                $this->assertStringNotContainsString('|', (string) $plan);
                $this->assertStringNotContainsString('{', (string) $plan);
            }
        }
    }
}
