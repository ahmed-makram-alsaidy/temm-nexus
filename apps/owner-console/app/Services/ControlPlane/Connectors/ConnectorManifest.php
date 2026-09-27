<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27J.2/27J.3 — connector package manifest (connector.json).
 *
 * The manifest is the ONLY discovery surface for a connector package. It is
 * strictly validated before a connector can register: malformed, oversized,
 * injection-carrying or platform-incompatible manifests are rejected safely
 * (27T) and never partially loaded.
 */
final class ConnectorManifest
{
    public const SCHEMA_VERSION = 1;
    public const SUPPORTED_SCHEMA_VERSIONS = [1];
    public const TRUST_LEVELS = ['first_party', 'trusted', 'unverified'];
    public const MAX_BYTES = 65536; // 27T — oversized metadata guard

    /** Import flow the generic onboarding wizard should drive. */
    public const FLOW_SUPABASE_ACCOUNT = 'supabase-account';
    public const FLOW_CREDENTIALS_FORM = 'credentials-form';
    public const FLOW_NONE = 'none';
    public const FLOWS = [self::FLOW_SUPABASE_ACCOUNT, self::FLOW_CREDENTIALS_FORM, self::FLOW_NONE];

    /** Phase 27K.2 — connector permission vocabulary. */
    public const PERMISSIONS = [
        'network.outbound',
        'network.local_source',
        'source.db.read',
        'source.storage.read',
        'client.repo.read',
        'filesystem.dataset.read',
    ];

    /** @param array<string, mixed> $data validated raw manifest data */
    private function __construct(
        public readonly array $data,
        public readonly string $path,
    ) {
    }

    /** @throws ConnectorManifestInvalid with every validation problem listed */
    public static function parseFile(string $path): self
    {
        if (! is_file($path)) {
            throw ConnectorManifestInvalid::with(['manifest file not found: '.$path]);
        }
        $raw = (string) file_get_contents($path);
        if (strlen($raw) > self::MAX_BYTES) {
            throw ConnectorManifestInvalid::with(['manifest exceeds '.self::MAX_BYTES.' bytes']);
        }
        try {
            $data = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConnectorManifestInvalid::with(['manifest is not valid JSON: '.$e->getMessage()]);
        }
        if (! is_array($data)) {
            throw ConnectorManifestInvalid::with(['manifest must be a JSON object']);
        }

        return self::validate($data, $path);
    }

    /** @throws ConnectorManifestInvalid */
    public static function validate(array $data, string $path = 'connector.json'): self
    {
        $errors = [];

        // Root-level shape: no unexpected keys (typo / injection surface).
        $allowed = ['schema_version', 'key', 'name', 'version', 'description', 'author',
            'license', 'platform_requirement', 'entrypoint', 'capabilities', 'permissions',
            'trust', 'import_flow', 'category', 'ui'];
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = "unknown manifest field '{$key}'";
            }
        }

        $schemaVersion = $data['schema_version'] ?? null;
        if (! in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $errors[] = 'unsupported manifest schema_version "'.var_export($schemaVersion, true).'" (supported: '.implode(',', self::SUPPORTED_SCHEMA_VERSIONS).')';
        }

        $key = $data['key'] ?? null;
        if (! is_string($key) || preg_match('/^[a-z][a-z0-9]{1,20}(-[a-z0-9]{1,20}){0,4}$/', $key) !== 1) {
            $errors[] = 'manifest key must be lowercase kebab (max 32 chars), got "'.var_export($key, true).'"';
        } elseif (in_array($key, ['core', 'platform', 'registry'], true)) {
            $errors[] = "manifest key '{$key}' is reserved";
        }

        $name = $data['name'] ?? null;
        if (! is_string($name) || $name === '' || mb_strlen($name) > 60 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            $errors[] = 'manifest name must be 1-60 chars without control characters';
        }

        $version = $data['version'] ?? null;
        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.\-]+)?$/', $version) !== 1) {
            $errors[] = 'manifest version must be semantic (X.Y.Z[-suffix])';
        }

        $description = $data['description'] ?? null;
        if (! is_string($description) || mb_strlen($description) > 500 || preg_match('/[\x00-\x1f\x7f]/', $description)) {
            $errors[] = 'manifest description must be a plain string up to 500 chars';
        }

        $author = $data['author'] ?? null;
        if (! is_string($author) || mb_strlen($author) > 100) {
            $errors[] = 'manifest author must be a string up to 100 chars';
        }

        if (isset($data['license']) && (! is_string($data['license']) || mb_strlen($data['license']) > 40)) {
            $errors[] = 'manifest license must be a string up to 40 chars';
        }

        $entrypoint = $data['entrypoint'] ?? null;
        if (! is_string($entrypoint) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $entrypoint) !== 1) {
            $errors[] = 'manifest entrypoint must be a PHP class name (no slashes, dots or traversal)';
        } elseif (! class_exists($entrypoint)) {
            $errors[] = "manifest entrypoint class '{$entrypoint}' does not exist";
        } elseif (! is_subclass_of($entrypoint, Contracts\Connector::class)) {
            $errors[] = "manifest entrypoint class '{$entrypoint}' must implement ".Contracts\Connector::class;
        }

        $capabilities = $data['capabilities'] ?? [];
        if (! is_array($capabilities)) {
            $errors[] = 'manifest capabilities must be a list';
            $capabilities = [];
        }
        foreach ($capabilities as $capability) {
            if (! is_string($capability) || ! ConnectorCapability::isValid($capability)) {
                $errors[] = 'manifest capability "'.var_export($capability, true).'" is outside the platform vocabulary';
            }
        }
        if (count($capabilities) > 32) {
            $errors[] = 'manifest declares too many capabilities (max 32)';
        }

        $permissions = $data['permissions'] ?? [];
        if (! is_array($permissions)) {
            $errors[] = 'manifest permissions must be a list';
            $permissions = [];
        }
        foreach ($permissions as $permission) {
            if (! is_string($permission) || ! in_array($permission, self::PERMISSIONS, true)) {
                $errors[] = 'manifest permission "'.var_export($permission, true).'" is not a known permission';
            }
        }

        $trust = $data['trust'] ?? 'unverified';
        if (! in_array($trust, self::TRUST_LEVELS, true)) {
            $errors[] = 'manifest trust must be one of: '.implode(',', self::TRUST_LEVELS);
        }

        $flow = $data['import_flow'] ?? self::FLOW_NONE;
        if (! in_array($flow, self::FLOWS, true)) {
            $errors[] = 'manifest import_flow must be one of: '.implode(',', self::FLOWS);
        }

        $requirement = $data['platform_requirement'] ?? null;
        if ($requirement !== null && ! is_string($requirement)) {
            $errors[] = 'manifest platform_requirement must be a string like ">=0.2.0"';
        }

        if (isset($data['ui']) && ! is_array($data['ui'])) {
            $errors[] = 'manifest ui must be an object';
        }

        if ($errors !== []) {
            throw ConnectorManifestInvalid::with($errors, $path);
        }

        // Phase 27R — platform compatibility is a hard gate on registration.
        $requirement = $data['platform_requirement'] ?? null;
        if (is_string($requirement) && $requirement !== '' && ! self::satisfiesPlatform($requirement)) {
            throw ConnectorManifestInvalid::with(
                ['connector requires platform '.$requirement.' but this platform is '.Support\Platform::version()],
                $path
            );
        }

        return new self($data, $path);
    }

    /**
     * Phase 27R — supported constraint forms: ">=X.Y.Z" (and plain "X.Y.Z"
     * treated as >=). Requirements are compared against the platform's
     * version LINE (major.minor.patch core, e.g. "0.1.0-rc.2" → "0.1.0"),
     * so prerelease builds of a line satisfy that line's requirement.
     */
    public static function satisfiesPlatform(string $requirement, ?string $platform = null): bool
    {
        $requirement = trim($requirement);
        if ($requirement === '' || $requirement === '*') {
            return true;
        }
        if (! preg_match('/^(>=|>)?\s*(\d+\.\d+\.\d+(?:-[0-9A-Za-z.\-]+)?)$/', $requirement, $m)) {
            return false;
        }
        $operator = $m[1] ?: '>=';
        $platform = Support\Platform::core($platform ?? Support\Platform::version());

        return version_compare($platform, $m[2], $operator === '>=' ? '>=' : '>');
    }

    // ── Typed accessors ──────────────────────────────────────────────────

    public function schemaVersion(): int
    {
        return (int) $this->data['schema_version'];
    }

    public function key(): string
    {
        return (string) $this->data['key'];
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function description(): string
    {
        return (string) $this->data['description'];
    }

    public function author(): string
    {
        return (string) $this->data['author'];
    }

    public function license(): ?string
    {
        return isset($this->data['license']) ? (string) $this->data['license'] : null;
    }

    public function entrypoint(): string
    {
        return (string) $this->data['entrypoint'];
    }

    /** @return list<string> capability keys */
    public function capabilities(): array
    {
        return array_values((array) ($this->data['capabilities'] ?? []));
    }

    /** @return list<string> permission keys */
    public function permissions(): array
    {
        return array_values((array) ($this->data['permissions'] ?? []));
    }

    public function trust(): string
    {
        return (string) ($this->data['trust'] ?? 'unverified');
    }

    public function importFlow(): string
    {
        return (string) ($this->data['import_flow'] ?? self::FLOW_NONE);
    }

    public function category(): string
    {
        return (string) ($this->data['category'] ?? 'source');
    }

    /** UI metadata block (display name, icon key, docs url, setup steps). */
    public function ui(): array
    {
        return (array) ($this->data['ui'] ?? []);
    }

    public function platformRequirement(): ?string
    {
        return isset($this->data['platform_requirement']) ? (string) $this->data['platform_requirement'] : null;
    }

    /** Safe presentation view — everything an operator may see. */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion(),
            'key' => $this->key(),
            'name' => $this->name(),
            'version' => $this->version(),
            'description' => $this->description(),
            'author' => $this->author(),
            'license' => $this->license(),
            'entrypoint' => $this->entrypoint(),
            'capabilities' => $this->capabilities(),
            'permissions' => $this->permissions(),
            'trust' => $this->trust(),
            'import_flow' => $this->importFlow(),
            'category' => $this->category(),
            'platform_requirement' => $this->platformRequirement(),
            'ui' => $this->ui(),
        ];
    }
}
