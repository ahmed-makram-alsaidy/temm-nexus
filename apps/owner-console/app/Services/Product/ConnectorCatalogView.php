<?php

namespace App\Services\Product;

use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0 Phase F (§11) — the Connector Catalog as a product catalogue.
 *
 * WHAT WAS WRONG (UX audit finding P9)
 * v0.3.0 rendered the catalog as six full-width unstyled boxes reading
 * `name · first_party` / `key: … · v1.0.0` / `Enabled`. There were no logos,
 * no descriptions, no capability columns, no per-card CTA, and no working
 * search or filters — it was a debug listing, not a catalogue.
 *
 * WHAT THIS DOES
 * Turns each connector's raw manifest into the card the mission specifies:
 * icon, provider name, short description, capabilities, migration support,
 * Live Sync support, trust level, status, version, and a CTA.
 *
 * CAPABILITY TRANSLATION (§14)
 * The raw capability keys (`database_metadata`, `change_capture`, …) are
 * internal vocabulary. They are mapped once, here, onto user-facing feature
 * names. The raw keys remain available for the Advanced/Machine-readable
 * disclosure so nothing is lost.
 */
final class ConnectorCatalogView
{
    /**
     * Capability key → the user-facing feature it enables.
     *
     * `group` drives the filter chips and the two headline flags the mission
     * asks for ("Migration support", "CDC support").
     *
     * @var array<string, array{label: string, group: string, hint: string}>
     */
    public const FEATURES = [
        ConnectorCapability::DATABASE_METADATA => [
            'label' => 'Schema inspection',
            'group' => 'migration',
            'hint' => 'Reads tables, columns, and types without writing to the source.',
        ],
        ConnectorCapability::DATA_EXTRACTION => [
            'label' => 'Data transfer',
            'group' => 'migration',
            'hint' => 'Moves the initial dataset into the target project.',
        ],
        ConnectorCapability::CONSISTENT_SNAPSHOT => [
            'label' => 'Consistent snapshot',
            'group' => 'migration',
            'hint' => 'Takes a point-in-time snapshot so the copy is internally consistent.',
        ],
        ConnectorCapability::SOURCE_FINGERPRINT => [
            'label' => 'Change detection',
            'group' => 'migration',
            'hint' => 'Fingerprints the source so an interrupted run can be resumed safely.',
        ],
        ConnectorCapability::RESUME => [
            'label' => 'Resumable runs',
            'group' => 'migration',
            'hint' => 'Continues from where a run stopped instead of starting over.',
        ],
        ConnectorCapability::CHANGE_CAPTURE => [
            'label' => 'Live Sync',
            'group' => 'live_sync',
            'hint' => 'Streams ongoing changes so the target stays current.',
        ],
        ConnectorCapability::CHECKPOINT => [
            'label' => 'Sync checkpoints',
            'group' => 'live_sync',
            'hint' => 'Records a verifiable position so sync can resume after a restart.',
        ],
        ConnectorCapability::ACCOUNT_DISCOVERY => [
            'label' => 'Account discovery',
            'group' => 'discovery',
            'hint' => 'Lists the accounts available to the supplied credentials.',
        ],
        ConnectorCapability::PROJECT_DISCOVERY => [
            'label' => 'Project discovery',
            'group' => 'discovery',
            'hint' => 'Lists the projects available to the supplied credentials.',
        ],
        ConnectorCapability::AUTH_METADATA => [
            'label' => 'Auth import',
            'group' => 'platform',
            'hint' => 'Reads the source authentication configuration.',
        ],
        ConnectorCapability::STORAGE_METADATA => [
            'label' => 'Storage metadata',
            'group' => 'platform',
            'hint' => 'Reads bucket and object metadata.',
        ],
        ConnectorCapability::STORAGE_CONTENT => [
            'label' => 'Storage files',
            'group' => 'platform',
            'hint' => 'Transfers stored file content.',
        ],
        ConnectorCapability::FUNCTION_METADATA => [
            'label' => 'Functions & routines',
            'group' => 'platform',
            'hint' => 'Reads stored procedures and database functions.',
        ],
        ConnectorCapability::POLICY_METADATA => [
            'label' => 'Access policies',
            'group' => 'platform',
            'hint' => 'Reads row-level security and access policies.',
        ],
        ConnectorCapability::REALTIME_METADATA => [
            'label' => 'Realtime config',
            'group' => 'platform',
            'hint' => 'Reads realtime channel configuration.',
        ],
        ConnectorCapability::SCHEDULE_METADATA => [
            'label' => 'Scheduled jobs',
            'group' => 'platform',
            'hint' => 'Reads scheduled and event-driven jobs.',
        ],
        ConnectorCapability::CLIENT_SCAN => [
            'label' => 'Client code scan',
            'group' => 'platform',
            'hint' => 'Scans linked client code for callsites that need converting.',
        ],
        ConnectorCapability::READ_ONLY_ENFORCEMENT => [
            'label' => 'Read-only guarantee',
            'group' => 'safety',
            'hint' => 'Enforces a read-only session against the source; the platform never writes to it.',
        ]
    ];

    /** Trust level → product label + tone (values are translation keys). */
    public const TRUST = [
        'first_party' => ['label' => 'First-party', 'tone' => 'success', 'hint' => 'Built and maintained by the platform team.'],
        'trusted' => ['label' => 'Trusted', 'tone' => 'info', 'hint' => 'Reviewed third-party connector.'],
        'community' => ['label' => 'Community', 'tone' => 'warning', 'hint' => 'Community-contributed; review before use.'],
        'unverified' => ['label' => 'Unverified', 'tone' => 'danger', 'hint' => 'Not reviewed. Treat with caution.']
    ];

    /** Feature metadata, labels/hints resolved at CALL time (locale-aware). */
    public static function features(): array
    {
        $out = [];
        foreach (self::FEATURES as $key => $feature) {
            $out[$key] = [
                'label' => self::translated("features.{$key}_label", $feature['label']),
                'group' => $feature['group'],
                'hint' => self::translated("features.{$key}_hint", $feature['hint']),
            ];
        }

        return $out;
    }

    /** Trust metadata, labels/hints resolved at CALL time (locale-aware). */
    public static function trust(): array
    {
        $out = [];
        foreach (self::TRUST as $key => $entry) {
            $out[$key] = [
                'label' => self::translated("features.trust_{$key}_label", $entry['label']),
                'tone' => $entry['tone'],
                'hint' => self::translated("features.trust_{$key}_hint", $entry['hint']),
            ];
        }

        return $out;
    }

    /** Translation with the canonical English const value as fallback. */
    protected static function translated(string $key, string $fallback): string
    {
        $line = __($key);

        return $line === $key ? $fallback : (string) $line;
    }

    /**
     * Every connector as a catalogue card.
     *
     * @return list<array{
     *     key: string, name: string, version: string, category: string,
     *     description: string, author: string, icon: string, docs_url: ?string,
     *     trust: string, trust_label: string, trust_tone: string, trust_hint: string,
     *     enabled: bool, status_label: string, status_tone: string,
     *     migration: bool, live_sync: bool, resumable: bool, read_only: bool,
     *     features: list<array{key: string, label: string, group: string, hint: string}>,
     *     raw_capabilities: list<string>, import_flow: string
     * }>
     */
    public static function cards(?ConnectorRegistry $registry = null): array
    {
        $registry ??= ConnectorRegistry::instance();

        $cards = [];
        foreach ($registry->connectors() as $connector) {
            try {
                $definition = $connector->definition();
            } catch (\Throwable $e) {
                // A broken connector must not take down the catalogue.
                Log::warning('Connector definition unreadable', [
                    'key' => is_string($connector->manifest()->key() ?? null) ? $connector->manifest()->key() : 'unknown',
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $capabilities = array_values(array_filter($definition->capabilities, 'is_string'));
            $features = self::featuresFor($capabilities);
            $trust = self::trustFor($definition->trust);
            $enabled = $registry->isEnabled($definition->key);

            $cards[] = [
                'key' => $definition->key,
                'name' => $definition->name,
                'version' => $definition->version,
                'category' => $definition->category,
                'description' => $definition->description,
                'author' => $definition->author,
                // The manifest's own icon when it declares one, else a neutral
                // fallback — never a broken glyph.
                'icon' => self::safeIcon($definition->ui['icon'] ?? null),
                'docs_url' => $definition->ui['docs_url'] ?? null,
                'trust' => $definition->trust,
                'trust_label' => $trust['label'],
                'trust_tone' => $trust['tone'],
                'trust_hint' => $trust['hint'],
                'enabled' => $enabled,
                'status_label' => $enabled ? 'Ready' : 'Disabled',
                'status_tone' => $enabled ? 'success' : 'neutral',
                'migration' => in_array(ConnectorCapability::DATA_EXTRACTION, $capabilities, true),
                'live_sync' => in_array(ConnectorCapability::CHANGE_CAPTURE, $capabilities, true),
                'resumable' => in_array(ConnectorCapability::RESUME, $capabilities, true),
                'read_only' => in_array(ConnectorCapability::READ_ONLY_ENFORCEMENT, $capabilities, true),
                'features' => $features,
                'raw_capabilities' => $capabilities,
                'import_flow' => $definition->importFlow,
            ];
        }

        usort($cards, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $cards;
    }

    /**
     * Apply the catalogue's search and filters.
     *
     * Matching is case-insensitive across name, description, author, key, and
     * every feature label, so a user searching "sync" finds the Live Sync
     * connectors even though the manifest never says "sync" as a word.
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  list<string>  $features  feature keys that must ALL be present
     * @param  list<string>  $trusts  trust levels to include (empty = all)
     * @return list<array<string, mixed>>
     */
    public static function filter(array $cards, string $search = '', array $features = [], array $trusts = [], bool $onlyReady = false): array
    {
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter($cards, function (array $card) use ($needle, $features, $trusts, $onlyReady): bool {
            if ($onlyReady && ! $card['enabled']) {
                return false;
            }

            if ($trusts !== [] && ! in_array($card['trust'], $trusts, true)) {
                return false;
            }

            if ($features !== []) {
                $featureKeys = array_column($card['features'], 'key');
                foreach ($features as $required) {
                    if (! in_array($required, $featureKeys, true)) {
                        return false;
                    }
                }
            }

            if ($needle === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $card['name'],
                $card['description'],
                $card['author'],
                $card['key'],
                $card['category'],
                $card['trust_label'],
                implode(' ', array_column($card['features'], 'label')),
            ])));

            return str_contains($haystack, $needle);
        }));
    }

    /** @param list<string> $capabilities */
    private static function featuresFor(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            $feature = self::features()[$capability] ?? null;
            if ($feature === null) {
                continue;
            }
            $out[] = [
                'key' => $capability,
                'label' => $feature['label'],
                'group' => $feature['group'],
                'hint' => $feature['hint'],
            ];
        }

        return $out;
    }

    /** @return array{label: string, tone: string, hint: string} */
    private static function trustFor(string $trust): array
    {
        return self::trust()[$trust] ?? [
            'label' => ucfirst(str_replace('_', ' ', $trust)),
            'tone' => 'neutral',
            'hint' => 'This connector declares a trust level the catalogue does not recognise.',
        ];
    }

    /**
     * Only allow an icon name that actually exists in the installed set.
     * An unknown Heroicon throws at render time and would 500 the catalogue.
     */
    private static function safeIcon(mixed $icon): string
    {
        $fallback = 'heroicon-o-puzzle-piece';

        if (! is_string($icon) || $icon === '' || ! str_starts_with($icon, 'heroicon-')) {
            return $fallback;
        }

        try {
            // Filament resolves through the Blade Icons factory; asking it to
            // resolve an unknown name throws, so we check the SVG set directly.
            $name = substr($icon, strlen('heroicon-'));
            $path = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg/'.$name.'.svg');

            return is_file($path) ? $icon : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * The filter chips the catalogue offers, derived from the capability map so
     * a new capability cannot be forgotten in the UI.
     *
     * @return list<array{key: string, label: string, group: string, count: int}>
     */
    public static function availableFeatures(array $cards): array
    {
        $counts = [];
        foreach ($cards as $card) {
            foreach ($card['features'] as $feature) {
                $counts[$feature['key']] = ($counts[$feature['key']] ?? 0) + 1;
            }
        }

        $out = [];
        foreach (self::features() as $key => $feature) {
            if (! isset($counts[$key])) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $feature['label'],
                'group' => $feature['group'],
                'count' => $counts[$key],
            ];
        }

        usort($out, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /** @return list<array{key: string, label: string, count: int}> */
    public static function availableTrusts(array $cards): array
    {
        $counts = [];
        foreach ($cards as $card) {
            $counts[$card['trust']] = ($counts[$card['trust']] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $trust => $count) {
            $out[] = [
                'key' => $trust,
                'label' => self::trustFor($trust)['label'],
                'count' => $count,
            ];
        }

        usort($out, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }
}
