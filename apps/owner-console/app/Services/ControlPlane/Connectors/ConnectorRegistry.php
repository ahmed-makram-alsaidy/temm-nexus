<?php

namespace App\Services\ControlPlane\Connectors;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Contracts\Connector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;

/**
 * Phase 27D — the central connector registry.
 *
 * This is the single boundary where connectors are declared and resolved.
 * Onboarding, the Migration Center and the AI Copilot go through this
 * registry — none of them hardwire a provider. Phase 27D.2: provider
 * branching in generic core code was removed in favor of registry resolution.
 *
 * Registration model (27J.1): connectors are discovered from first-party
 * packages (connector.json manifests under app/Connectors) by
 * ConnectorDiscovery and registered here. Only FIRST_PARTY trust is enabled
 * by default (27K.1) — unverified connectors stay disabled and uninvokable.
 */
class ConnectorRegistry
{
    /** @var array<string, Connector> key => connector */
    protected array $connectors = [];

    /** @var array<string, bool> key => enabled */
    protected array $enabled = [];

    /** @var array<string, string> key => rejection reason (manifest/platform failures) */
    protected array $rejected = [];

    // ── Instance API ─────────────────────────────────────────────────────

    /**
     * Register a connector. Duplicate keys are rejected (27D.3); manifest/
     * platform problems must have been caught by discovery — a connector
     * object reaching this method is assumed valid.
     */
    public function register(Connector $connector, ?bool $enabled = null): void
    {
        $key = $connector->manifest()->key();
        if (isset($this->connectors[$key])) {
            throw new ConnectorKeyConflict($key);
        }
        $this->connectors[$key] = $connector;
        // Phase 27K.1 — only first-party connectors are enabled by default.
        $this->enabled[$key] = $enabled ?? $connector->manifest()->trust() === 'first_party';
    }

    public function unregister(string $key): void
    {
        unset($this->connectors[$key], $this->enabled[$key]);
    }

    /** Enable/disable a connector at runtime (27D.1). */
    public function setEnabled(string $key, bool $enabled): void
    {
        if (! isset($this->connectors[$key])) {
            throw new ConnectorNotSupported($key);
        }
        $this->enabled[$key] = $enabled;
    }

    public function isEnabled(string $key): bool
    {
        return $this->enabled[$key] ?? false;
    }

    /** Record a manifest/platform rejection for observability (27J.3/27R). */
    public function reject(string $key, string $reason): void
    {
        $this->rejected[$key] = $reason;
    }

    /** @return array<string, string> key => rejection reason */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** @return array<string, Connector> enabled connectors, keyed by stable key */
    public function connectors(): array
    {
        return array_filter($this->connectors, fn ($key) => $this->enabled[$key] ?? false, ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, Connector> all registered connectors (enabled or not) */
    public function allConnectors(): array
    {
        return $this->connectors;
    }

    /** @throws ConnectorNotSupported unknown key; @throws ConnectorDisabled registered but disabled */
    public function connector(string $key): Connector
    {
        $connector = $this->connectors[$key] ?? null;
        if ($connector === null) {
            throw new ConnectorNotSupported($key);
        }
        if (! ($this->enabled[$key] ?? false)) {
            throw new ConnectorDisabled($key);
        }

        return $connector;
    }

    /** @throws ConnectorNotSupported when the source's connector is unknown or disabled */
    public function connectorForSource(MigrationSource $source): Connector
    {
        return $this->connector($source->effectiveConnectorKey());
    }

    /** Resolve the enabled source connector for a key. @throws ConnectorNotSupported|ConnectorDisabled */
    public function sourceConnector(string $key): SourceConnector
    {
        $connector = $this->connector($key);
        if (! $connector instanceof SourceConnector) {
            throw new ConnectorNotSupported($key.' (not a source connector)');
        }

        return $connector;
    }

    /**
     * Phase 24 engine seam — the read-only SourceAdapter for a migration
     * source, resolved through the source's connector.
     *
     * @throws ConnectorNotSupported|ConnectorDisabled
     */
    public static function resolve(MigrationSource $source): SourceAdapter
    {
        return self::instance()->resolveSource($source);
    }

    /** @throws ConnectorNotSupported|ConnectorDisabled */
    public function resolveSource(MigrationSource $source): SourceAdapter
    {
        $connector = $this->connectorForSource($source);

        if (! $connector instanceof SourceConnector) {
            throw new ConnectorNotSupported($source->effectiveConnectorKey().' (not a source connector)');
        }

        return $connector->sourceAdapter($source);
    }

    // ── Presentation / BC facade (Phase 26O signatures preserved) ────────

    /**
     * Connector descriptors for generic UI/CLI, keyed by stable key.
     * Shape: key => {key, name, label, version, description, trust,
     * capabilities, enabled, import_flow, category, docs_url}.
     */
    public static function all(): array
    {
        return self::instance()->descriptors();
    }

    public function descriptors(): array
    {
        $out = [];
        foreach ($this->connectors as $key => $connector) {
            $manifest = $connector->manifest();
            $out[$key] = [
                'key' => $key,
                'name' => $manifest->name(),
                'label' => $manifest->name(),
                'version' => $manifest->version(),
                'description' => $manifest->description(),
                'trust' => $manifest->trust(),
                'capabilities' => $manifest->capabilities(),
                'enabled' => $this->enabled[$key] ?? false,
                'import_flow' => $manifest->importFlow(),
                'category' => $manifest->category(),
                'docs_url' => $manifest->ui()['docs_url'] ?? null,
            ];
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return self::instance()->isEnabled($key);
    }

    public static function label(string $key): string
    {
        $all = self::instance()->descriptors();

        return $all[$key]['label'] ?? ucfirst($key);
    }

    /**
     * Static facade — resolves the container-bound singleton. No static
     * caching here: the container is the single source of truth (a cached
     * instance would go stale across application rebuilds in tests/Octane).
     */
    public static function instance(): self
    {
        return app(self::class);
    }

    /** Test/registry-reset seam: forget the container singleton. */
    public static function flushInstance(): void
    {
        app()->forgetInstance(self::class);
    }
}
