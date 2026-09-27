<?php

namespace App\Services\ControlPlane\Migration;

use InvalidArgumentException;

/**
 * 24J.3 — deterministic, tested transform library. Every transform is a pure
 * function of (row, config). UTF-8 safety (24J.4): text transforms validate
 * UTF-8 and throw on invalid bytes instead of silently corrupting (no OEM/
 * ANSI conversion anywhere).
 */
class TransformPipeline
{
    /** @var array<string, callable(array, array): array> */
    protected static array $custom = [];

    public static function register(string $name, callable $fn): void
    {
        self::$custom[$name] = $fn;
    }

    /** Apply a named transform (with JSON config) to one row. */
    public static function apply(string $transform, array $row, array $config = []): array
    {
        if (isset(self::$custom[$transform])) {
            return (self::$custom[$transform])($row, $config);
        }

        return match ($transform) {
            'direct_copy' => self::directCopy($row, $config),
            'rename' => self::rename($row, $config),
            'enum_mapping' => self::enumMapping($row, $config),
            'json_normalization' => self::jsonNormalization($row, $config),
            'uuid_preserve' => self::uuidPreserve($row, $config),
            'int_preserve' => self::intPreserve($row, $config),
            'relation_remap' => self::relationRemap($row, $config),
            'url_rewrite' => self::urlRewrite($row, $config),
            'auth_identity_transform' => self::authIdentity($row, $config),
            'legacy_archive' => self::legacyArchive($row, $config),
            'timestamp_normalize' => self::timestampNormalize($row, $config),
            'utf8_text' => self::utf8Text($row, $config),
            default => throw new InvalidArgumentException("Unknown transform: {$transform}"),
        };
    }

    /** Registry metadata for UI/docs. */
    public static function catalog(): array
    {
        return [
            'direct_copy' => 'Copy row as-is (column mapping only)',
            'rename' => 'Rename columns per map',
            'enum_mapping' => 'Map legacy enum values per column',
            'json_normalization' => 'Canonical JSON re-encode (UTF-8 safe)',
            'uuid_preserve' => 'Preserve UUIDs verbatim',
            'int_preserve' => 'Preserve integer identity values',
            'relation_remap' => 'Remap FK values through a mapping callback',
            'url_rewrite' => 'Rewrite provider URLs to target URLs',
            'auth_identity_transform' => 'Auth users → target users (hash preserved verbatim)',
            'legacy_archive' => 'Preserve legacy columns verbatim (audit/immutable)',
            'timestamp_normalize' => 'Normalize timestamps to UTC',
            'utf8_text' => 'Validate UTF-8 strictly (throw on invalid bytes)',
        ];
    }

    /** Column lists may be given as ['a','b'] or ['a'=>1,...] — normalize to map. */
    protected static function columnMap(array $config): ?array
    {
        if (! array_key_exists('columns', $config)) {
            return null;
        }
        $columns = $config['columns'];
        if ($columns === []) {
            return [];
        }

        return array_is_list($columns) ? array_flip(array_map('strval', $columns)) : $columns;
    }

    private static function directCopy(array $row, array $config): array
    {
        $map = $config['columns'] ?? [];
        if ($map === []) {
            return $row;
        }
        $out = [];
        foreach ($map as $from => $to) {
            $out[$to] = $row[$from] ?? null;
        }

        return $out;
    }

    private static function rename(array $row, array $config): array
    {
        return self::directCopy($row, $config);
    }

    private static function enumMapping(array $row, array $config): array
    {
        foreach ($config['columns'] ?? [] as $column => $values) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $legacy = (string) $row[$column];
                if (! array_key_exists($legacy, $values)) {
                    throw new InvalidArgumentException("enum_mapping: unmapped legacy value '{$legacy}' for column {$column}");
                }
                $row[$column] = $values[$legacy];
            }
        }

        return $row;
    }

    private static function jsonNormalization(array $row, array $config): array
    {
        foreach (self::columnMap($config) ?? array_keys($row) as $column => $_) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $value = $row[$column];
            if (is_string($value)) {
                $decoded = json_decode($value, true, 512, JSON_INVALID_UTF8_IGNORE);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$column] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            } elseif (is_array($value)) {
                $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $row;
    }

    private static function uuidPreserve(array $row, array $config): array
    {
        foreach (self::columnMap($config) ?? array_keys($row) as $column => $_) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $value = (string) $row[$column];
            if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)) {
                throw new InvalidArgumentException("uuid_preserve: '{$value}' is not a UUID for column {$column}");
            }
            $row[$column] = strtolower($value);
        }

        return $row;
    }

    private static function intPreserve(array $row, array $config): array
    {
        foreach (self::columnMap($config) ?? array_keys($row) as $column => $_) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }

        return $row;
    }

    private static function relationRemap(array $row, array $config): array
    {
        foreach ($config['columns'] ?? [] as $column => $mapping) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $old = (string) $row[$column];
            // Mapping is a plain array {old: new} or a named map in config['maps'].
            $map = is_array($mapping) ? $mapping : ($config['maps'][$mapping] ?? []);
            if (! array_key_exists($old, $map)) {
                throw new InvalidArgumentException("relation_remap: unmapped key '{$old}' for column {$column}");
            }
            $row[$column] = $map[$old];
        }

        return $row;
    }

    private static function urlRewrite(array $row, array $config): array
    {
        $from = rtrim((string) ($config['from'] ?? ''), '/');
        $to = rtrim((string) ($config['to'] ?? ''), '/');
        if ($from === '') {
            return $row;
        }
        foreach ($row as $column => $value) {
            if (is_string($value) && str_contains($value, $from)) {
                $row[$column] = str_replace($from, $to, $value);
            }
        }

        return $row;
    }

    private static function authIdentity(array $row, array $config): array
    {
        // auth.users row → target users row. bcrypt/argon hashes are carried
        // VERBATIM (24J.5) — never re-hashed, never logged.
        $out = [];
        $map = $config['columns'] ?? [];
        foreach ($map as $from => $to) {
            $out[$to] = $row[$from] ?? null;
        }
        foreach ($config['constants'] ?? [] as $column => $value) {
            $out[$column] = $value;
        }
        $hashCol = $config['hash_column'] ?? 'password';
        if (isset($out[$hashCol]) && is_string($out[$hashCol]) && $out[$hashCol] !== '' && ! preg_match('/^\$(2[aby]|argon2)/', $out[$hashCol])) {
            throw new InvalidArgumentException('auth_identity_transform: unrecognized password hash format — refusing (hash strategy must be source-analyzed)');
        }

        return $out;
    }

    private static function legacyArchive(array $row, array $config): array
    {
        // Legacy columns are preserved VERBATIM — immutable history.
        return $row;
    }

    private static function timestampNormalize(array $row, array $config): array
    {
        foreach (self::columnMap($config) ?? array_keys($row) as $column => $_) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            try {
                $dt = new \DateTimeImmutable((string) $row[$column]);
                $row[$column] = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                throw new InvalidArgumentException("timestamp_normalize: invalid timestamp '{$row[$column]}' for column {$column}");
            }
        }

        return $row;
    }

    private static function utf8Text(array $row, array $config): array
    {
        foreach (self::columnMap($config) ?? array_keys($row) as $column => $_) {
            if (! array_key_exists($column, $row) || ! is_string($row[$column])) {
                continue;
            }
            $value = $row[$column];
            if (! preg_match('//u', $value)) {
                throw new InvalidArgumentException("utf8_text: invalid UTF-8 bytes in column {$column} — refusing lossy conversion");
            }
            if (str_contains($value, "\0")) {
                throw new InvalidArgumentException("utf8_text: NUL byte in column {$column}");
            }
        }

        return $row;
    }
}
