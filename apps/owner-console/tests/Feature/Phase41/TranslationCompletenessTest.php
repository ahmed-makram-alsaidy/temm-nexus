<?php

namespace Tests\Feature\Phase41;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 0.4.0-rc.5 (Phase 41, Part G) — the TRANSLATION COMPLETENESS GATE.
 *
 * The release fails when a mandatory product translation is missing: every
 * key in an English file must exist in the Arabic file, and vice versa.
 * This is what makes "humans notice missing keys later" unnecessary.
 *
 * Also covers pluralization (C.12): Arabic resolves all six plural forms,
 * English resolves singular/plural.
 */
class TranslationCompletenessTest extends TestCase
{
    public function test_every_english_key_exists_in_arabic(): void
    {
        $missing = $this->missingKeys('en', 'ar');

        $this->assertSame([], $missing, 'Keys missing from Arabic translations: '.implode(', ', $missing));
    }

    public function test_every_arabic_key_exists_in_english(): void
    {
        $missing = $this->missingKeys('ar', 'en');

        $this->assertSame([], $missing, 'Keys missing from English translations: '.implode(', ', $missing));
    }

    public function test_no_translation_file_is_empty(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) as $file) {
                $lines = count(file($file, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES));
                $this->assertGreaterThan(3, $lines, basename($file).' in '.$locale.' looks empty');
            }
        }
    }

    public function test_english_and_arabic_use_the_same_file_set(): void
    {
        $en = array_map('basename', glob(lang_path('en/*.php')));
        $ar = array_map('basename', glob(lang_path('ar/*.php')));

        sort($en);
        sort($ar);

        $this->assertSame($en, $ar, 'en/ and ar/ contain different translation files');
    }

    public function test_arabic_pluralization_resolves_all_forms(): void
    {
        // 0 → zero form, 1 → one, 2 → two, 3-10 → few, 11-99 → many, 100+ → other.
        $zero = trans_choice('home.backups_never', 0, [], 'ar');
        $one = trans_choice('home.backups_never', 1, [], 'ar');
        $two = trans_choice('home.backups_never', 2, [], 'ar');
        $few = trans_choice('home.backups_never', 5, [], 'ar');
        $many = trans_choice('home.backups_never', 15, [], 'ar');
        $hundred = trans_choice('home.backups_never', 100, [], 'ar');

        $this->assertNotEquals($one, $two);
        $this->assertNotEquals($two, $few);
        $this->assertNotEquals($few, $many);

        // The :count placeholder is substituted in every form that carries it.
        $this->assertStringContainsString('15', $many);
        $this->assertStringContainsString('100', $hundred);

        // Zero form must not leak the raw pipe syntax.
        foreach ([$zero, $one, $two, $few, $many, $hundred] as $form) {
            $this->assertStringNotContainsString('|', $form);
            $this->assertStringNotContainsString('{', $form);
        }
    }

    public function test_english_pluralization_resolves_singular_and_plural(): void
    {
        $this->assertStringContainsString('transfer running right now', (string) trans_choice('home.transfers_running', 1, [], 'en'));
        $this->assertStringContainsString('transfers running right now', (string) trans_choice('home.transfers_running', 2, [], 'en'));
        $this->assertStringContainsString('transfers running right now', (string) trans_choice('home.transfers_running', 7, [], 'en'));
    }

    public function test_arabic_strings_actually_contain_arabic(): void
    {
        // Guard against an accidental all-English "Arabic" file.
        $checks = [
            'nav.home',
            'common.save',
            'wizard.title',
            'ai.settings_title',
        ];
        foreach ($checks as $key) {
            $value = __($key, [], 'ar');

            $this->assertTrue(
                (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $value) || str_contains($value, 'Nexus AI'),
                "{$key} in Arabic is not Arabic: {$value}"
            );
        }
    }

    public function test_technical_identifiers_survive_translation(): void
    {
        // C.10: protocol/vendor identifiers are not translated — only the
        // surrounding explanation is.
        $arWizard = __('wizard.destination_external');
        $this->assertStringContainsString('PostgreSQL', $arWizard);

        app()->setLocale('ar');
        $arAi = __('ai.settings_subtitle');
        $this->assertStringContainsString('Nexus AI', $arAi);

        $arConnector = __('features.change_capture_hint');
        $this->assertNotSame('features.change_capture_hint', $arConnector);
        app()->setLocale('en');
    }

    /**
     * Recursively diff the key sets of two locales. Paths are compared as
     * RAW key segments — a file may legitimately contain a literal key like
     * `home.hero` (one level), which a naive dot-split would mangle.
     *
     * @return list<string> human-readable paths present in $from but missing in $to
     */
    protected function missingKeys(string $from, string $to): array
    {
        $missing = [];

        foreach (glob(lang_path($from.'/*.php')) as $file) {
            $group = basename($file, '.php');
            $fromPaths = $this->keyPaths((array) require $file);
            $toFile = lang_path($to.'/'.$group.'.php');
            $toPaths = is_file($toFile) ? $this->keyPaths((array) require $toFile) : [];

            foreach (array_keys($fromPaths) as $path) {
                if (! isset($toPaths[$path])) {
                    $missing[] = $group.'.'.str_replace("\x1f", '.', $path);
                }
            }
        }

        return $missing;
    }

    /**
     * All leaf paths of a translation array, keyed by the raw segment path
     * (segments joined with a unit separator — never a dot).
     *
     * @return array<string, true>
     */
    protected function keyPaths(array $items, array $prefix = []): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            $path = array_merge($prefix, [(string) $key]);
            if (is_array($value)) {
                $out += $this->keyPaths($value, $path);
            } else {
                $out[implode("\x1f", $path)] = true;
            }
        }

        return $out;
    }
}
