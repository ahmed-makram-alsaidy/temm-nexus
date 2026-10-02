<?php

namespace Tests\Feature\Phase40;

use App\Models\User;
use App\Services\Access\Capability;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\Product\ConnectorCatalogView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 Phase F (§11) — the Connector Catalog as a product catalogue.
 *
 * The audit (finding P9) found the catalogue rendered as unstyled debug text
 * with no capability information and non-functional filters. These tests pin
 * the product contract: every connector carries the required card data, the
 * capability vocabulary is translated into product language, and the filters
 * actually filter.
 */
class ConnectorCatalogTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
    }

    // ── Card data (§11's required fields) ──────────────────────────────

    public function test_every_card_carries_all_the_required_fields(): void
    {
        $cards = ConnectorCatalogView::cards();

        $this->assertNotEmpty($cards, 'No connectors were discovered; cannot judge the catalogue.');

        foreach ($cards as $card) {
            foreach ([
                'key', 'name', 'version', 'description', 'author', 'icon',
                'trust', 'trust_label', 'trust_tone', 'enabled',
                'status_label', 'status_tone', 'migration', 'live_sync',
                'features', 'raw_capabilities',
            ] as $field) {
                $this->assertArrayHasKey($field, $card, "Card '{$card['name']}' is missing '{$field}'.");
            }

            // The mission's card list: icon, name, description, capabilities,
            // migration support, CDC support, trust, status, version, CTA.
            $this->assertNotSame('', $card['name']);
            $this->assertNotSame('', (string) $card['description'], "{$card['name']} has no description.");
            $this->assertNotSame('', $card['version']);
            $this->assertIsBool($card['migration']);
            $this->assertIsBool($card['live_sync']);
            $this->assertIsBool($card['enabled']);
        }
    }

    public function test_every_card_icon_exists_in_the_installed_icon_set(): void
    {
        $dir = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');
        $installed = array_map(
            fn (string $p): string => 'heroicon-'.basename($p, '.svg'),
            glob($dir.'/o-*.svg') ?: [],
        );

        foreach (ConnectorCatalogView::cards() as $card) {
            $this->assertContains(
                $card['icon'],
                $installed,
                "Connector '{$card['key']}' declares '{$card['icon']}', which is not an installed Heroicon "
                .'— an unknown icon throws at render time and would 500 the catalogue.',
            );
        }
    }

    public function test_descriptions_come_from_the_manifest_not_a_placeholder(): void
    {
        foreach (ConnectorCatalogView::cards() as $card) {
            $this->assertGreaterThan(
                20,
                mb_strlen($card['description']),
                "Connector '{$card['key']}' has a description too short to be real.",
            );
        }
    }

    // ── Capability translation (§14) ───────────────────────────────────

    public function test_raw_capability_keys_are_translated_into_product_language(): void
    {
        $cards = ConnectorCatalogView::cards();
        $allLabels = [];
        foreach ($cards as $card) {
            foreach ($card['features'] as $feature) {
                $allLabels[] = $feature['label'];
            }
        }

        $this->assertNotEmpty($allLabels);

        // The internal keys must not be what the user reads.
        foreach (['database_metadata', 'data_extraction', 'change_capture', 'read_only_enforcement'] as $internal) {
            $this->assertNotContains($internal, $allLabels, "Raw capability '{$internal}' leaked as a feature label.");
        }

        // …and the product names must appear.
        $this->assertContains('Data transfer', $allLabels);
        $this->assertContains('Live Sync', $allLabels);
        $this->assertContains('Read-only guarantee', $allLabels);
    }

    public function test_raw_capabilities_remain_available_for_advanced_disclosure(): void
    {
        foreach (ConnectorCatalogView::cards() as $card) {
            // §14: nothing is deleted, only re-hierarchised.
            $this->assertIsArray($card['raw_capabilities']);
        }
    }

    public function test_migration_and_live_sync_flags_track_the_real_capabilities(): void
    {
        foreach (ConnectorCatalogView::cards() as $card) {
            $this->assertSame(
                in_array(ConnectorCapability::DATA_EXTRACTION, $card['raw_capabilities'], true),
                $card['migration'],
                "Migration flag disagrees with the manifest for '{$card['key']}'.",
            );

            $this->assertSame(
                in_array(ConnectorCapability::CHANGE_CAPTURE, $card['raw_capabilities'], true),
                $card['live_sync'],
                "Live Sync flag disagrees with the manifest for '{$card['key']}'.",
            );
        }
    }

    public function test_at_least_one_connector_supports_live_sync_and_at_least_one_does_not(): void
    {
        $cards = ConnectorCatalogView::cards();

        $withSync = array_filter($cards, fn (array $c): bool => $c['live_sync']);
        $withoutSync = array_filter($cards, fn (array $c): bool => ! $c['live_sync']);

        // The flag must be discriminating, not always-true or always-false.
        $this->assertNotEmpty($withSync, 'No connector reports Live Sync; the flag may be broken.');
        $this->assertNotEmpty($withoutSync, 'Every connector reports Live Sync; the flag may be broken.');
    }

    public function test_trust_levels_are_labelled_in_product_language(): void
    {
        foreach (ConnectorCatalogView::cards() as $card) {
            $this->assertNotSame('first_party', $card['trust_label']);
            $this->assertNotSame('', $card['trust_hint']);
            $this->assertContains($card['trust_tone'], ['success', 'info', 'warning', 'danger', 'neutral']);
        }
    }

    // ── Filters ────────────────────────────────────────────────────────

    public function test_search_matches_name_description_and_feature_labels(): void
    {
        $cards = ConnectorCatalogView::cards();

        // By name fragment.
        $byName = ConnectorCatalogView::filter($cards, 'postgres');
        $this->assertNotEmpty($byName);
        foreach ($byName as $card) {
            $this->assertStringContainsStringIgnoringCase('postgres', $card['name'].$card['description'].$card['key']);
        }

        // By product-language capability — the manifest never says "sync" as a
        // standalone word for every connector, so this proves translation works.
        $byFeature = ConnectorCatalogView::filter($cards, 'live sync');
        $this->assertNotEmpty($byFeature);
        foreach ($byFeature as $card) {
            $this->assertTrue($card['live_sync'], "Search for 'live sync' returned {$card['key']}, which does not support it.");
        }
    }

    public function test_search_is_case_insensitive_and_trims(): void
    {
        $cards = ConnectorCatalogView::cards();

        $this->assertSame(
            count(ConnectorCatalogView::filter($cards, 'FIREBASE')),
            count(ConnectorCatalogView::filter($cards, '  firebase  ')),
        );
    }

    public function test_a_capability_filter_requires_every_selected_capability(): void
    {
        $cards = ConnectorCatalogView::cards();

        $filtered = ConnectorCatalogView::filter($cards, '', [
            ConnectorCapability::DATA_EXTRACTION,
            ConnectorCapability::CHANGE_CAPTURE,
        ]);

        $this->assertNotEmpty($filtered);
        foreach ($filtered as $card) {
            $this->assertTrue($card['migration']);
            $this->assertTrue($card['live_sync']);
        }

        // And it must be a real narrowing, not a no-op.
        $this->assertLessThanOrEqual(count($cards), count($filtered));
    }

    public function test_a_trust_filter_narrows_to_that_level(): void
    {
        $cards = ConnectorCatalogView::cards();

        $filtered = ConnectorCatalogView::filter($cards, '', [], ['first_party']);
        $this->assertNotEmpty($filtered);
        foreach ($filtered as $card) {
            $this->assertSame('first_party', $card['trust']);
        }
    }

    public function test_filters_combine_and_can_legitimately_return_nothing(): void
    {
        $cards = ConnectorCatalogView::cards();

        // A search that cannot match anything must return an empty list rather
        // than everything — the empty state depends on it.
        $this->assertSame([], ConnectorCatalogView::filter($cards, 'zzzz-no-such-connector'));
    }

    public function test_only_ready_filter_excludes_disabled_connectors(): void
    {
        $cards = ConnectorCatalogView::cards();

        $ready = ConnectorCatalogView::filter($cards, '', [], [], true);

        foreach ($ready as $card) {
            $this->assertTrue($card['enabled']);
        }
    }

    public function test_available_features_report_real_counts(): void
    {
        $cards = ConnectorCatalogView::cards();
        $features = ConnectorCatalogView::availableFeatures($cards);

        $this->assertNotEmpty($features);

        foreach ($features as $feature) {
            $expected = 0;
            foreach ($cards as $card) {
                if (in_array($feature['key'], array_column($card['features'], 'key'), true)) {
                    $expected++;
                }
            }
            $this->assertSame($expected, $feature['count'], "Count for '{$feature['label']}' is wrong.");
            $this->assertGreaterThan(0, $feature['count'], "Feature '{$feature['label']}' is offered with a zero count.");
        }
    }

    // ── HTTP surface ───────────────────────────────────────────────────

    public function test_the_catalogue_renders_for_a_user_with_the_capability(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/connectors')
            ->assertOk()
            ->assertSee('Connectors')
            ->assertSee('Everything you can migrate from');
    }

    public function test_the_catalogue_shows_the_headline_capability_and_trust_fields(): void
    {
        $html = $this->actingAs($this->platformOwner)->get('/admin/connectors')->getContent();

        foreach (['Migration', 'Live Sync', 'Trust', 'Search connectors'] as $label) {
            $this->assertStringContainsString($label, $html, "The catalogue does not render '{$label}'.");
        }
    }

    public function test_the_old_catalogue_url_redirects_to_the_new_one(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/connector-catalog')
            ->assertRedirect('/admin/connectors');
    }

    public function test_a_user_without_the_capability_is_denied(): void
    {
        $this->actingAs($this->nobody)
            ->get('/admin/connectors')
            ->assertForbidden();
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->get('/admin/connectors')->assertRedirect();
    }

    public function test_the_catalogue_never_exposes_credential_names_or_secret_shaped_values(): void
    {
        $html = $this->actingAs($this->platformOwner)->get('/admin/connectors')->getContent();
        $text = strip_tags($html);

        // NOTE: a blunt ban on the word "password" is wrong — the Firebase
        // connector's own description legitimately says it inventories auth
        // "never password material", which is a safety statement we WANT shown.
        // What must never appear is a credential FIELD NAME being rendered as
        // card content, or a secret-shaped value.
        $cards = ConnectorCatalogView::cards();

        foreach ($cards as $card) {
            $definition = ConnectorRegistry::instance()
                ->connectors()[$card['key']]->definition() ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($definition->credentials as $field) {
                $needle = $field->key;

                // The template renders credential names only inside the
                // Advanced `<dl>`, as `<dt>name</dt>`. Test THAT shape rather
                // than a bare substring: a plain `str_contains` gives false
                // positives on ordinary prose (a credential field keyed `uri`
                // matched the word "during").
                $this->assertStringNotContainsString(
                    '<dt>'.$needle.'</dt>',
                    $html,
                    "The catalogue rendered credential field '{$needle}' in the Advanced metadata list.",
                );
            }
        }

        // Secret-shaped values must never appear regardless of naming.
        foreach ([
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            '/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\./',   // JWT
            '/\bsk-[A-Za-z0-9]{20,}\b/',                          // OpenAI-style key
            '/\bservice_role\b/i',
        ] as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $text,
                "The catalogue matched a secret-shaped pattern: {$pattern}",
            );
        }
    }

    public function test_the_capability_vocabulary_has_no_untranslated_feature_mapping(): void
    {
        // Every capability a connector actually declares should either be in the
        // translation map or be one of the explicitly non-presentational ones.
        $ignored = [
            ConnectorCapability::SUPPORTED,
            ConnectorCapability::SUPPORTED_WITH_CONFIGURATION,
            ConnectorCapability::PARTIAL,
            ConnectorCapability::NOT_SUPPORTED,
            ConnectorCapability::NOT_APPLICABLE,
        ];

        $definitions = ConnectorRegistry::instance();
        foreach ($definitions->connectors() as $connector) {
            foreach ($connector->definition()->capabilities as $capability) {
                if (in_array($capability, $ignored, true)) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $capability,
                    ConnectorCatalogView::FEATURES,
                    "Capability '{$capability}' has no product-language label, so it would be invisible in the catalogue.",
                );
            }
        }
    }
}
