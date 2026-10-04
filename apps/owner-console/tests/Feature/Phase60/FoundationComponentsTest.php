<?php

namespace Tests\Feature\Phase60;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * 0.6.0 Phase A — the shared foundation components.
 *
 * A7: the error pattern shows WHAT FAILED and WHAT TO DO; exception text,
 * SQLSTATE and capability slugs render ONLY inside the Technical details
 * disclosure — never as the default view, and never as a bare "Error".
 * A8: empty states carry an icon, a title and (optionally) an action.
 * A9: disclosures hide technical information without deleting it.
 * A10: technical values stay LTR even inside Arabic pages.
 */
class FoundationComponentsTest extends TestCase
{
    public function test_error_component_shows_title_and_hides_technical_details_by_default(): void
    {
        $html = Blade::render('<x-nx.error :payload="$payload" />', [
            'payload' => [
                'title' => 'This step could not be completed',
                'body' => 'Nothing was lost — try again.',
                'technical' => 'ConnectionException: SQLSTATE 42501 permission denied',
            ],
        ]);

        $this->assertStringContainsString('This step could not be completed', $html);
        $this->assertStringContainsString('Nothing was lost', $html);
        $this->assertStringContainsString(__('foundation.error_technical_details'), $html);

        // The technical payload exists for operators but is inside the
        // disclosure — the visible line is the human title, never the code.
        $this->assertStringContainsString('SQLSTATE 42501', $html);
        $this->assertMatchesRegularExpression('/<details[^>]*class="nx-details nx-details--inline"[^>]*>/s', $html);

        // The technical string must appear INSIDE the disclosure — i.e.
        // strictly after the <details> marker opens it.
        $this->assertGreaterThan(
            strpos($html, '<details'),
            strpos($html, 'SQLSTATE 42501'),
            'Technical details leaked outside the disclosure'
        );
    }

    public function test_error_component_never_renders_only_the_word_error(): void
    {
        $html = Blade::render('<x-nx.error :payload="$payload" />', [
            'payload' => ['title' => 'Something went wrong', 'body' => null, 'technical' => null],
        ]);

        // The alert must always carry more than a bare "Error" word: it has
        // a role, structure and a title distinct from a bare enum.
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Something went wrong', $html);
    }

    public function test_empty_state_renders_title_body_and_action(): void
    {
        $html = Blade::render(
            '<x-nx.empty-state icon="heroicon-o-folder" :title="$t" :body="$b" :actionUrl="$u" :actionLabel="$l" />',
            ['t' => 'No projects yet', 'b' => 'Connect your first project.', 'u' => '/admin/new-project', 'l' => 'Connect project'],
        );

        $this->assertStringContainsString('No projects yet', $html);
        $this->assertStringContainsString('Connect your first project.', $html);
        $this->assertStringContainsString('href="/admin/new-project"', $html);
        $this->assertStringContainsString('Connect project', $html);
    }

    public function test_disclosure_hides_content_until_opened(): void
    {
        $html = Blade::render('<x-nx.details summary="Technical details">{{$slot}}</x-nx.details>', [
            'slot' => '<span>run_id 9f2c</span>',
        ]);

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Technical details', $html);
        $this->assertStringContainsString('run_id 9f2c', $html);
        $this->assertStringNotContainsString('<details open', $html);
    }

    public function test_technical_values_render_ltr_even_for_arabic(): void
    {
        app()->setLocale('ar');

        $html = Blade::render('<x-nx.tech>{{ $value }}</x-nx.tech>', ['value' => ' postgres://db-14:5432 ']);

        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('postgres://db-14:5432', $html);

        app()->setLocale('en');
    }

    public function test_technical_value_with_copy_offers_the_copy_affordance(): void
    {
        $html = Blade::render('<x-nx.tech copy>{{ $value }}</x-nx.tech>', ['value' => 'gpt-4.1-mini']);

        $this->assertStringContainsString('data-nx-copy="gpt-4.1-mini"', $html);
        $this->assertStringContainsString(__('foundation.copy'), $html);
    }
}
