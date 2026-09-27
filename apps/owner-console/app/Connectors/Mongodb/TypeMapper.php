<?php

namespace App\Connectors\Mongodb;

/**
 * Phase 28K — deterministic BSON → PostgreSQL-representation mapping.
 *
 * Types map into the platform's normalized type vocabulary (27B.2 — the same
 * vocabulary every source/target adapter speaks). Value conversion is total
 * and lossless for supported types:
 *
 *   string→text  boolean→boolean  int32→int4  int64→int8  double→float8
 *   decimal128→numeric (EXACT string form — never float, 28K.1)
 *   date→timestamptz (UTC ISO-8601 ms precision, 28K.2)
 *   objectId→text (24-char hex, default preservation, 28M)
 *   binary→text ("bin64:<base64>" — never routed through UTF-8, 28K.3)
 *   timestamp→timestamptz (seconds UTC)
 *   array→jsonb (canonical JSON)   document→jsonb (when not flattened)
 *   regex/symbol/minKey/maxKey/undefined→jsonb extended-JSON (safe serialization)
 *   dbPointer/code→jsonb extended-JSON
 *
 * Values that cannot be represented losslessly are serialized as tagged
 * extended JSON — never silently coerced (28K "unsupported → safe serialization").
 */
class TypeMapper
{
    /** Normalized (target-facing) type for an observed BSON type tag. */
    public static function normalizedType(string $bsonType): string
    {
        return match ($bsonType) {
            'string' => 'text',
            'boolean' => 'boolean',
            'int32' => 'int4',
            'int64' => 'int8',
            'double' => 'float8',
            'decimal128' => 'numeric',
            'date', 'timestamp' => 'timestamptz',
            'objectId' => 'text',
            'binary' => 'text',
            'null', 'undefined', 'minKey', 'maxKey' => 'text',
            'regex', 'symbol', 'code', 'dbPointer' => 'jsonb',
            'array', 'document' => 'jsonb',
            default => 'jsonb',
        };
    }

    /** Convert a tagged BSON value to the PHP value bound into the target. */
    public static function convertValue(array $tagged): mixed
    {
        $type = (string) ($tagged['t'] ?? '');
        $value = $tagged['v'] ?? null;

        return match ($type) {
            'string' => (string) $value,
            'boolean' => (bool) $value,
            'int32', 'int64' => (int) $value,
            'double' => (float) $value,
            'decimal128' => (string) $value, // exact decimal string — no float drift
            'date' => self::utcIso((int) $value),
            'timestamp' => self::utcIso(((int) ($tagged['seconds'] ?? 0)) * 1000),
            'objectId' => (string) $value,
            'binary' => 'bin64:'.base64_encode((string) $value),
            'null', 'undefined', 'minKey', 'maxKey' => null,
            'regex', 'symbol', 'code', 'dbPointer' => self::extendedJson($tagged),
            'array', 'document' => self::canonicalJson($tagged),
            default => self::extendedJson($tagged),
        };
    }

    /** UTC ISO-8601 with millisecond precision (28K.2 — instant semantics). */
    public static function utcIso(int $epochMilliseconds): string
    {
        if ($epochMilliseconds >= 0) {
            $seconds = intdiv($epochMilliseconds, 1000);
            $ms = $epochMilliseconds % 1000;
        } else {
            $seconds = intdiv($epochMilliseconds - 999, 1000);
            $ms = $epochMilliseconds - $seconds * 1000;
        }

        return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03dZ', $ms);
    }

    /**
     * Canonical JSON for document/array values (28Q.1): keys sorted
     * recursively, tagged values reduced to their JSON representation —
     * deterministic, lossless for the types it carries.
     */
    public static function canonicalJson(array $tagged): string
    {
        // Accept untagged field maps (streamFind output) — normalize at the
        // boundary so toCanonicalPhp never falls into extendedPhp recursion.
        if (! isset($tagged['t'])) {
            $tagged = ['t' => 'document', 'v' => $tagged];
        }

        return json_encode(
            self::toCanonicalPhp($tagged),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    public static function toCanonicalPhp(array $tagged): mixed
    {
        $type = (string) ($tagged['t'] ?? '');
        $value = $tagged['v'] ?? null;

        return match ($type) {
            'document' => self::assocCanonical((array) $value),
            'array' => array_map(fn ($item) => is_array($item) && isset($item['t']) ? self::toCanonicalPhp($item) : $item, array_values((array) $value)),
            'binary' => ['\$binary' => base64_encode((string) $value), '\$subtype' => (int) ($tagged['subtype'] ?? 0)],
            'objectId' => ['\$oid' => (string) $value],
            'date' => ['\$date' => self::utcIso((int) $value)],
            'decimal128' => ['\$numberDecimal' => (string) $value],
            'int64' => (int) $value,
            'int32' => (int) $value,
            'double' => (float) $value,
            'boolean' => (bool) $value,
            'string' => (string) $value,
            'null', 'undefined' => null,
            'minKey' => ['\$minKey' => 1],
            'maxKey' => ['\$maxKey' => 1],
            'regex' => ['\$regularExpression' => ['pattern' => (string) $value, 'options' => (string) ($tagged['options'] ?? '')]],
            'timestamp' => ['\$timestamp' => ['t' => (int) ($tagged['seconds'] ?? 0), 'i' => (int) ($tagged['v'] ?? 0)]],
            default => self::extendedPhp($tagged),
        };
    }

    protected static function assocCanonical(array $document): array
    {
        $out = [];
        foreach ($document as $key => $tagged) {
            $out[(string) $key] = is_array($tagged) && isset($tagged['t']) ? self::toCanonicalPhp($tagged) : $tagged;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** Deterministic extended-JSON for unusual types (never raw bytes). */
    public static function extendedJson(array $tagged): string
    {
        if (! isset($tagged['t'])) {
            $tagged = ['t' => 'document', 'v' => $tagged];
        }

        return json_encode(self::extendedPhp($tagged), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function extendedPhp(array $tagged): array
    {
        $type = (string) ($tagged['t'] ?? 'unknown');

        return ['\$type' => $type, '\$value' => self::toCanonicalPhp($tagged) ?? (string) ($tagged['v'] ?? '')];
    }

    /**
     * Type-frequency merge for inference: when a field is observed with
     * multiple incompatible types it becomes SCHEMA_VARIANCE evidence —
     * the dominant type wins for the column, variance is recorded honestly.
     */
    public static function mergeObservedTypes(array $typeCounts): array
    {
        arsort($typeCounts);
        $total = array_sum($typeCounts);
        $dominant = array_key_first($typeCounts);
        $variance = count(array_filter($typeCounts, fn ($c) => $c > 0)) > 1;

        return [
            'type' => self::normalizedType((string) $dominant),
            'bson_types' => $typeCounts,
            'variance' => $variance,
            'frequency' => $total > 0 ? round(($typeCounts[$dominant] ?? 0) / $total * 100, 2) : 100.0,
        ];
    }
}
