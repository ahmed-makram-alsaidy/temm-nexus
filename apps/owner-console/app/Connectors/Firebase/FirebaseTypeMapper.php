<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29C — Firestore value semantics → normalized type vocabulary.
 *
 * Firestore value types (29C): string, number (int/double), boolean, null,
 * timestamp, geo point, reference, bytes, map, array. Firestore has NO
 * authoritative schema — every mapping here is an inference applied to the
 * observed DOMINANT type of a field, never a claim about the source.
 *
 * Normalized vocabulary is the engine's PostgreSQL-style set
 * (text/int8/float8/boolean/timestamptz/jsonb) — identical to what the
 * MongoDB connector emits, so the Migration Center stays provider-agnostic.
 *
 * Values that cannot be represented losslessly are serialized safely
 * (base64 marker for bytes, canonical JSON for structured values) — never
 * silently coerced.
 */
class FirebaseTypeMapper
{
    /** Normalized (target-facing) type for an observed Firestore value type. */
    public static function normalizedType(string $firestoreType): string
    {
        return match ($firestoreType) {
            'string', 'null', 'reference' => 'text',
            'boolean' => 'boolean',
            'integer' => 'int8',
            'double' => 'float8',
            'number' => 'float8',
            'timestamp' => 'timestamptz',
            'geopoint', 'map', 'array' => 'jsonb',
            'bytes' => 'text', // bin64: marker — exact bytes preserved as base64
            default => 'jsonb',
        };
    }

    /**
     * Convert a decoded Firestore REST value (typed wrapper array) to the PHP
     * value bound into the target.
     *
     * @param  array  $value  e.g. ['stringValue' => 'x'] / ['integerValue' => '42']
     */
    public static function convertValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $type = array_key_first($value);
        $raw = $value[$type];

        return match ($type) {
            'nullValue' => null,
            'booleanValue' => (bool) $raw,
            'integerValue' => (int) $raw,       // Firestore encodes int64 as string
            'doubleValue' => (float) $raw,
            'stringValue' => (string) $raw,
            'timestampValue' => (string) $raw,  // RFC3339 — instant semantics preserved
            'referenceValue' => (string) $raw,  // full document path
            'geoPointValue' => json_encode([
                'latitude' => (float) ($raw['latitude'] ?? 0),
                'longitude' => (float) ($raw['longitude'] ?? 0),
            ]),
            'bytesValue' => 'bin64:'.(string) $raw, // base64 — exact bytes preserved
            'mapValue', 'arrayValue' => self::canonicalJson($value),
            default => self::canonicalJson($value),
        };
    }

    /** The observed Firestore type tag of a decoded REST value wrapper. */
    public static function observedType(mixed $value): string
    {
        if (! is_array($value)) {
            return is_null($value) ? 'null' : 'unknown';
        }
        $type = array_key_first($value);

        return match ($type) {
            'nullValue' => 'null',
            'booleanValue' => 'boolean',
            'integerValue' => 'integer',
            'doubleValue' => 'double',
            'stringValue' => 'string',
            'timestampValue' => 'timestamp',
            'referenceValue' => 'reference',
            'geoPointValue' => 'geopoint',
            'bytesValue' => 'bytes',
            'mapValue' => 'map',
            'arrayValue' => 'array',
            default => 'unknown',
        };
    }

    /** Decode an arrayValue/mapValue wrapper into plain nested PHP values. */
    public static function decodeWrapper(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $type = array_key_first($value);
        $raw = $value[$type] ?? null;

        return match ($type) {
            'mapValue' => array_map([self::class, 'decodeWrapper'], (array) ($raw['fields'] ?? [])),
            'arrayValue' => array_map([self::class, 'decodeWrapper'], array_values((array) ($raw['values'] ?? []))),
            'geoPointValue' => ['latitude' => (float) ($raw['latitude'] ?? 0), 'longitude' => (float) ($raw['longitude'] ?? 0)],
            default => $value,
        };
    }

    /** Deterministic canonical JSON of a Firestore value wrapper (29C/29Q). */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::decodeWrapper($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: 'null';
    }

    /** Extract the referenced collection id from a referenceValue path (29D). */
    public static function referenceCollection(string $referenceValue): string
    {
        // "projects/p/databases/(default)/documents/users/uid1" → "users"
        $after = explode('/documents/', $referenceValue, 2)[1] ?? '';

        return $after === '' ? '' : explode('/', $after)[0];
    }

    /** Extract the referenced document id (final segment) from a referenceValue. */
    public static function referenceId(string $referenceValue): string
    {
        $after = explode('/documents/', $referenceValue, 2)[1] ?? '';
        $segments = explode('/', $after);
        if (count($segments) % 2 !== 0) {
            array_pop($segments);
        }

        return (string) end($segments);
    }
}
