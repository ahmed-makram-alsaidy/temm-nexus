<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27A.2 — the stable public identity of a connector.
 *
 * Composed from the validated manifest plus the connector's declarative
 * schemas. This is what the registry, the generic UI and the CLI consume —
 * class names never become public identifiers.
 */
final class ConnectorDefinition
{
    /**
     * @param list<ConnectorCredentialField> $credentials full credential schema
     * @param list<ConnectorCredentialField> $configuration non-secret configuration fields
     * @param list<string> $capabilities capability keys
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly ?string $license,
        public readonly array $capabilities,
        public readonly array $credentials,
        public readonly array $configuration,
        public readonly array $ui,
        public readonly string $trust,
        public readonly string $importFlow,
        public readonly string $category,
        public readonly ?string $platformRequirement,
        public readonly array $permissions,
    ) {
    }

    public static function fromManifest(ConnectorManifest $manifest, array $credentials, array $configuration): self
    {
        $ui = $manifest->ui();

        return new self(
            key: $manifest->key(),
            name: $manifest->name(),
            version: $manifest->version(),
            description: $manifest->description(),
            author: $manifest->author(),
            license: $manifest->license(),
            capabilities: $manifest->capabilities(),
            credentials: $credentials,
            configuration: $configuration,
            ui: [
                'display_name' => (string) ($ui['display_name'] ?? $manifest->name()),
                'icon' => $ui['icon'] ?? null,
                'docs_url' => self::safeDocsUrl($ui['docs_url'] ?? null),
                'setup_instructions' => mb_substr((string) ($ui['setup_instructions'] ?? ''), 0, 2000),
                'capability_labels' => (array) ($ui['capability_labels'] ?? []),
            ],
            trust: $manifest->trust(),
            importFlow: $manifest->importFlow(),
            category: $manifest->category(),
            platformRequirement: $manifest->platformRequirement(),
            permissions: $manifest->permissions(),
        );
    }

    /** Docs URL must be http(s) — manifests must not smuggle other schemes. */
    private static function safeDocsUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /** @return list<array> credential fields as presentation arrays */
    public function credentialSchemaArray(): array
    {
        return array_map(fn (ConnectorCredentialField $f) => $f->toArray(), $this->credentials);
    }

    public function credentialField(string $key): ?ConnectorCredentialField
    {
        foreach ($this->credentials as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function configurationField(string $key): ?ConnectorCredentialField
    {
        foreach ($this->configuration as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'license' => $this->license,
            'capabilities' => $this->capabilities,
            'credentials' => $this->credentialSchemaArray(),
            'ui' => $this->ui,
            'trust' => $this->trust,
            'import_flow' => $this->importFlow,
            'category' => $this->category,
            'platform_requirement' => $this->platformRequirement,
            'permissions' => $this->permissions,
        ];
    }
}
