<?php

namespace App\Services\ControlPlane\Connectors;

use Illuminate\Support\Str;

/**
 * Phase 27E.3 — resolved credential values handed to a connector operation.
 *
 * The host builds this from the vault / connection records, scoped to exactly
 * the fields the operation needs (secret minimization). Values are
 * server-side only: every debug/serialization surface is redacted, and the
 * raw array is never exposed through tostring/debuginfo.
 */
final class ConnectorCredentials
{
    /** @param array<string, mixed> $values field key => value (secrets included, memory only) */
    private function __construct(
        private readonly array $values,
        private readonly array $secretKeys = [],
    ) {
    }

    public static function fromArray(array $values, array $secretKeys = []): self
    {
        return new self($values, array_values($secretKeys));
    }

    /** The field keys this credential set was built from. */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values)
            && $this->values[$key] !== null
            && $this->values[$key] !== '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Secret minimization (27E.3): derive a narrower credential set holding
     * ONLY the requested keys. Operations declare what they need; the host
     * never hands more than that.
     */
    public function only(array $keys): self
    {
        $scoped = array_intersect_key($this->values, array_flip($keys));
        $scopedSecrets = array_values(array_intersect($this->secretKeys, $keys));

        return new self($scoped, $scopedSecrets);
    }

    /** Field keys carrying secret material (for redaction, never listing). */
    public function secretKeys(): array
    {
        return $this->secretKeys;
    }

    /** Raw values — for the connector's own server-side use only. */
    public function raw(): array
    {
        return $this->values;
    }

    /** Redacted view safe for logs/exceptions/UI echoes. */
    public function redacted(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[$key] = in_array($key, $this->secretKeys, true)
                ? '***'.substr((string) Str::of(md5((string) $value))->take(4), 0, 4)
                : $value;
        }

        return $out;
    }

    /** Prevent accidental secret leakage through var_dump/echo. */
    public function __debugInfo(): array
    {
        return ['values' => $this->redacted(), 'redacted' => true];
    }

    public function __toString(): string
    {
        return 'ConnectorCredentials['.implode(',', array_keys($this->values)).'] (secrets redacted)';
    }
}
