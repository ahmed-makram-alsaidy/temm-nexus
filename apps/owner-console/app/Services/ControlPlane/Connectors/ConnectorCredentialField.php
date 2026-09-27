<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27E.1 — one declarative credential field of a connector.
 *
 * The schema drives the generic connection UI, secret minimization (27E.3)
 * and the dynamic capability matrix (27C.2). Fields never carry values —
 * only the shape of what the connector needs.
 */
final class ConnectorCredentialField
{
    public const TYPES = ['text', 'password', 'url', 'host', 'port', 'select', 'boolean', 'file'];

    // Scope: where the value lives / who it is shared with.
    public const SCOPE_ACCOUNT = 'account';          // account-level (e.g. PAT) — account connection record
    public const SCOPE_SOURCE = 'source';            // source-level secret (e.g. DB password) — vault
    public const SCOPE_CONFIGURATION = 'configuration'; // non-secret configuration — source connection JSON

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $secret = false,
        public readonly bool $required = false,
        public readonly string $scope = self::SCOPE_SOURCE,
        public readonly ?string $validation = null,   // PCRE pattern the value must match
        public readonly ?string $help = null,
        public readonly ?string $capability = null,   // capability key this field unlocks
        public readonly ?array $options = null,       // select options: value => label
        public readonly ?string $default = null,
    ) {
    }

    public static function make(array $attributes): self
    {
        return new self(
            key: (string) ($attributes['key'] ?? ''),
            label: (string) ($attributes['label'] ?? ''),
            type: in_array($attributes['type'] ?? 'text', self::TYPES, true) ? $attributes['type'] : 'text',
            secret: (bool) ($attributes['secret'] ?? false),
            required: (bool) ($attributes['required'] ?? false),
            scope: in_array($attributes['scope'] ?? self::SCOPE_SOURCE, [self::SCOPE_ACCOUNT, self::SCOPE_SOURCE, self::SCOPE_CONFIGURATION], true)
                ? $attributes['scope'] : self::SCOPE_SOURCE,
            validation: isset($attributes['validation']) ? (string) $attributes['validation'] : null,
            help: isset($attributes['help']) ? (string) $attributes['help'] : null,
            capability: isset($attributes['capability']) ? (string) $attributes['capability'] : null,
            options: isset($attributes['options']) && is_array($attributes['options']) ? $attributes['options'] : null,
            default: isset($attributes['default']) ? (string) $attributes['default'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key, 'label' => $this->label, 'type' => $this->type,
            'secret' => $this->secret, 'required' => $this->required, 'scope' => $this->scope,
            'validation' => $this->validation, 'help' => $this->help,
            'capability' => $this->capability, 'options' => $this->options, 'default' => $this->default,
        ];
    }
}
