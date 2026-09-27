<?php

namespace App\Services\ControlPlane\Connectors\Support;

use App\Models\ExternalAccountConnection;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorCredentialField;
use App\Services\ControlPlane\Connectors\ConnectorDefinition;
use App\Services\ControlPlane\SecretService;

/**
 * Phase 27E.2/27E.3 + 27K.3 — connector-scoped secret resolution.
 *
 * Secrets are stored in the platform vault (project_secrets, encrypted at
 * rest) or the account connection record (encrypted cast). This resolver is
 * the ONLY way a connector obtains credential values:
 *
 * - it resolves ONLY fields declared in the connector's credential schema —
 *   a connector cannot fish for arbitrary vault names (scope escape, 27T);
 * - secret values exist in server memory only and are wrapped in a
 *   ConnectorCredentials value object whose every debug surface is redacted;
 * - presence probing (`resolvableFieldKeys`) lets the capability matrix
 *   report honestly without exposing values (27C.2).
 */
class ScopedSecretResolver
{
    /**
     * All declarative fields of a connector (secret credentials AND
     * non-secret configuration), in schema order.
     *
     * @return list<ConnectorCredentialField>
     */
    protected static function allFields(ConnectorDefinition $definition): array
    {
        return array_merge($definition->credentials, $definition->configuration);
    }

    /**
     * Which declared fields can be resolved for this source
     * (values NOT included). Drives the dynamic capability matrix.
     *
     * @return list<string> field keys
     */
    public static function resolvableFieldKeys(MigrationSource $source, ConnectorDefinition $definition): array
    {
        $keys = [];
        foreach (self::allFields($definition) as $field) {
            if (self::fieldResolvable($source, $definition, $field)) {
                $keys[] = $field->key;
            }
        }

        return $keys;
    }

    protected static function fieldResolvable(MigrationSource $source, ConnectorDefinition $definition, ConnectorCredentialField $field): bool
    {
        return match ($field->scope) {
            ConnectorCredentialField::SCOPE_ACCOUNT => self::accountValue($source) !== null,
            ConnectorCredentialField::SCOPE_SOURCE => self::secretRefName($source, $definition, $field->key) !== null,
            ConnectorCredentialField::SCOPE_CONFIGURATION => self::configValue($source, $definition, $field) !== null,
            default => false,
        };
    }

    /**
     * Resolve the FULL declared schema for a source (credentials +
     * configuration). Missing fields are simply absent from the result.
     */
    public static function resolveForSource(MigrationSource $source, ConnectorDefinition $definition): ConnectorCredentials
    {
        $keys = array_map(fn (ConnectorCredentialField $f) => $f->key, self::allFields($definition));

        return self::resolveScoped($source, $definition, $keys);
    }

    /**
     * Resolve ONLY the requested declared fields (27E.3 secret minimization:
     * e.g. project discovery must not receive the database password).
     */
    public static function resolveScoped(MigrationSource $source, ConnectorDefinition $definition, array $fieldKeys): ConnectorCredentials
    {
        $values = [];
        $secretKeys = [];
        foreach (self::allFields($definition) as $field) {
            if (! in_array($field->key, $fieldKeys, true)) {
                continue;
            }
            $value = match ($field->scope) {
                ConnectorCredentialField::SCOPE_ACCOUNT => self::accountValue($source),
                ConnectorCredentialField::SCOPE_SOURCE => self::sourceSecretValue($source, $definition, $field),
                ConnectorCredentialField::SCOPE_CONFIGURATION => self::configValue($source, $definition, $field),
                default => null,
            };
            if ($value !== null) {
                $values[$field->key] = $value;
                if ($field->secret) {
                    $secretKeys[] = $field->key;
                }
            }
        }

        return ConnectorCredentials::fromArray($values, $secretKeys);
    }

    /** The secret-ref NAME declared for a field — or null. Never a value. */
    protected static function secretRefName(MigrationSource $source, ConnectorDefinition $definition, string $fieldKey): ?string
    {
        // Only vault names mapped onto DECLARED field keys are honored.
        if ($definition->credentialField($fieldKey) === null) {
            return null;
        }
        $name = $source->secret_refs[$fieldKey] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    protected static function sourceSecretValue(MigrationSource $source, ConnectorDefinition $definition, ConnectorCredentialField $field): ?string
    {
        $name = self::secretRefName($source, $definition, $field->key);
        if ($name === null) {
            return $field->default;
        }
        $values = SecretService::valuesFor($source->project, [$name]);

        return isset($values[$name]) && $values[$name] !== '' ? (string) $values[$name] : $field->default;
    }

    /** Account-level secret (e.g. PAT) from the encrypted connection record. */
    protected static function accountValue(MigrationSource $source): ?string
    {
        $connection = $source->externalAccountConnection;
        if (! $connection instanceof ExternalAccountConnection || (string) $connection->secret_encrypted === '') {
            return null;
        }

        return (string) $connection->secret_encrypted;
    }

    /** Non-secret configuration value from the source connection JSON. */
    protected static function configValue(MigrationSource $source, ConnectorDefinition $definition, ConnectorCredentialField $field): ?string
    {
        $value = $source->connection[$field->key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $field->default;
    }
}
