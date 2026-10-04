<?php

namespace Tests\Feature\Phase60;

use App\Support\ActivityHumanizer;
use Tests\TestCase;

/**
 * 0.6.0 Phase A (§A6) — the activity humanizer.
 *
 * Raw audit verbs become short human sentences on product surfaces;
 * unknown verbs fall back to readable Title Case; audit fidelity is
 * preserved (raw() + the dictionary never destroys input).
 */
class ActivityHumanizerTest extends TestCase
{
    public function test_known_verbs_become_human_sentences(): void
    {
        $this->assertSame('Connected a source', ActivityHumanizer::humanize('WIZARD_SOURCE_CONNECTED'));
        $this->assertSame('Created a secret', ActivityHumanizer::humanize('SECRET_CREATED'));
        $this->assertSame('Platform setup completed', ActivityHumanizer::humanize('PLATFORM_SETUP_COMPLETED'));
    }

    public function test_humanized_verbs_are_translated_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('ربط مصدر بيانات', ActivityHumanizer::humanize('WIZARD_SOURCE_CONNECTED'));
        $this->assertSame('أنشأ سرًّا', ActivityHumanizer::humanize('SECRET_CREATED'));

        app()->setLocale('en');
    }

    public function test_unknown_verbs_fall_back_to_readable_title_case(): void
    {
        $this->assertSame('Some Future Action', ActivityHumanizer::humanize('SOME_FUTURE_ACTION'));
    }

    public function test_raw_verb_stays_available_for_audit_fidelity(): void
    {
        $this->assertSame('WIZARD_SOURCE_CONNECTED', ActivityHumanizer::raw('WIZARD_SOURCE_CONNECTED'));
    }

    public function test_dictionary_never_destroys_input(): void
    {
        $raw = 'WIZARD_SOURCE_CONNECTED';
        ActivityHumanizer::humanize($raw);
        $this->assertSame('WIZARD_SOURCE_CONNECTED', $raw);
    }

    public function test_every_stored_audit_action_has_a_dictionary_entry_or_readable_fallback(): void
    {
        // The model declares the canonical action list; every one of them
        // must humanize to something readable (translated or Title Case)
        // and never return an empty string or the raw snake_case form.
        foreach (\App\Models\AdminAuditEntry::ACTIONS as $action) {
            $human = ActivityHumanizer::humanize($action);

            $this->assertNotSame('', $human, "Humanized form of {$action} is empty");
            $this->assertStringNotContainsString('_', $human, "Humanized form of {$action} still contains underscores");
        }
    }
}
