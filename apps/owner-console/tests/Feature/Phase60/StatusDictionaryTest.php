<?php

namespace Tests\Feature\Phase60;

use App\Support\ProductStatus;
use Tests\TestCase;

/**
 * 0.6.0 Phase A (§A5) — the status dictionary.
 *
 * Raw stored enums are never rendered on product surfaces: every known
 * value maps to a translated LABEL and a semantic TONE, unknown values
 * fall back to a neutral "Unknown" instead of leaking the raw string.
 * Stored enums themselves are unchanged — this is presentation only.
 */
class StatusDictionaryTest extends TestCase
{
    public function test_known_raw_values_map_to_human_labels(): void
    {
        $this->assertSame('Not deployed', ProductStatus::label('not_deployed'));
        $this->assertSame('Unknown', ProductStatus::label('unknown'));
        $this->assertSame('Healthy', ProductStatus::label('healthy'));
        $this->assertSame('Failed', ProductStatus::label('failed'));
        $this->assertSame('Blocked', ProductStatus::label('BLOCKED'));
    }

    public function test_labels_are_translated_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('لم يُنشر بعد', ProductStatus::label('not_deployed'));
        $this->assertSame('غير معروف', ProductStatus::label('unknown'));

        app()->setLocale('en');
    }

    public function test_tones_are_semantic(): void
    {
        $this->assertSame('success', ProductStatus::tone('healthy'));
        $this->assertSame('danger', ProductStatus::tone('unhealthy'));
        $this->assertSame('danger', ProductStatus::tone('BLOCKED'));
        $this->assertSame('warning', ProductStatus::tone('degraded'));
        $this->assertSame('neutral', ProductStatus::tone('not_deployed'));
        $this->assertSame('neutral', ProductStatus::tone('unknown'));
    }

    public function test_colors_map_to_filament_badge_colors(): void
    {
        $this->assertSame('success', ProductStatus::color('healthy'));
        $this->assertSame('danger', ProductStatus::color('unhealthy'));
        $this->assertSame('gray', ProductStatus::color('not_deployed'));
        $this->assertSame('gray', ProductStatus::color('some_future_value'));
    }

    public function test_unknown_values_do_not_leak_the_raw_string(): void
    {
        $this->assertSame('Unknown', ProductStatus::label('some_future_value'));
        $this->assertFalse(ProductStatus::known('some_future_value'));
        $this->assertTrue(ProductStatus::known('healthy'));
    }

    public function test_stored_enums_are_unchanged(): void
    {
        // The dictionary is presentation only — it must never mutate input.
        $raw = 'not_deployed';
        ProductStatus::label($raw);
        $this->assertSame('not_deployed', $raw);
    }
}
