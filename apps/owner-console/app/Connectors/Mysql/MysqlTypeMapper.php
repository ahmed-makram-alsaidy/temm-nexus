<?php

namespace App\Connectors\Mysql;

/**
 * Phase 31B/31C — MySQL/MariaDB → PostgreSQL type mapping.
 *
 * Explicit, deterministic mappings. UNSIGNED SAFETY (31C) is the hard rule:
 * no unsigned value can ever overflow the signed PostgreSQL target type, so
 * unsigned ranges are widened UP, never squeezed:
 *   unsigned tinyint  (0..255)        → smallint
 *   unsigned smallint (0..65535)      → integer
 *   unsigned mediumint(0..16777215)   → integer
 *   unsigned int      (0..4294967295) → bigint
 *   unsigned bigint   (0..2^64-1)     → numeric(20,0)
 * Boundary values are validated by tests (31C).
 */
class MysqlTypeMapper
{
    /** Known base types (COLUMN_TYPE base name, lowercase) → engine type. */
    protected const MAP = [
        'tinyint' => 'int2',
        'smallint' => 'int2',
        'mediumint' => 'int4',
        'int' => 'int4',
        'integer' => 'int4',
        'bigint' => 'int8',
        'decimal' => 'numeric',
        'numeric' => 'numeric',
        'float' => 'float4',
        'double' => 'float8',
        'real' => 'float8',
        'bit' => 'int8',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'char' => 'char',
        'varchar' => 'varchar',
        'nchar' => 'char',
        'nvarchar' => 'varchar',
        'tinyltext' => 'text',
        'text' => 'text',
        'mediumtext' => 'text',
        'longtext' => 'text',
        'tinyblob' => 'bytea',
        'blob' => 'bytea',
        'mediumblob' => 'bytea',
        'longblob' => 'bytea',
        'binary' => 'bytea',
        'varbinary' => 'bytea',
        'json' => 'jsonb',
        'date' => 'date',
        'time' => 'time',
        'datetime' => 'timestamp',
        'timestamp' => 'timestamptz',
        'year' => 'int2',
        'geometry' => 'text',
        'point' => 'text',
        'linestring' => 'text',
        'polygon' => 'text',
        'multipoint' => 'text',
        'multilinestring' => 'text',
        'multipolygon' => 'text',
        'geometrycollection' => 'text',
        'enum' => 'text',
        'set' => 'text',
    ];

    /**
     * Normalize one COLUMN_TYPE value, e.g. 'int unsigned', 'decimal(10,2)',
     * 'varchar(180)', 'enum('a','b')', 'tinyint(1)', 'bit(8)'.
     *
     * @return array{type: string, needs_review: bool, note: ?string}
     */
    public static function normalize(string $columnType): array
    {
        $type = strtolower(trim($columnType));
        $unsigned = (bool) preg_match('/\bunsigned\b/', $type);
        $base = preg_replace('/\(.*/', '', $type) ?? $type;
        $base = preg_replace('/\s+(unsigned|signed|zerofill).*/', '', $base) ?? $base;
        $base = trim($base);

        // BOOLEAN / BOOL are aliases for tinyint(1) — map to boolean.
        if (in_array($base, ['tinyint'], true) && preg_match('/\(\s*1\s*\)/', $type) && ! $unsigned) {
            return ['type' => 'boolean', 'needs_review' => false, 'note' => 'tinyint(1) → boolean'];
        }
        if (in_array($base, ['boolean', 'bool'], true)) {
            return ['type' => 'boolean', 'needs_review' => false, 'note' => null];
        }

        // BIT(1) → boolean; wider BIT → needs review (bitmask semantics).
        if ($base === 'bit') {
            if (preg_match('/\(\s*1\s*\)/', $type)) {
                return ['type' => 'boolean', 'needs_review' => false, 'note' => 'bit(1) → boolean'];
            }

            return ['type' => 'int8', 'needs_review' => true, 'note' => 'BIT(n>1) semantics need review'];
        }

        $mapped = self::MAP[$base] ?? null;
        if ($mapped === null) {
            return ['type' => 'text', 'needs_review' => true, 'note' => 'unknown MySQL type: '.$columnType];
        }

        // 31C — deterministic unsigned widening (never overflow the target).
        if ($unsigned) {
            $widened = match ($base) {
                'tinyint' => 'int2',
                'smallint' => 'int4',
                'mediumint' => 'int4',
                'int', 'integer' => 'int8',
                'bigint' => 'numeric(20,0)',
                default => null,
            };
            if ($widened !== null) {
                return ['type' => $widened, 'needs_review' => false, 'note' => 'unsigned widened: '.$base.' → '.$widened];
            }
        }

        // Carry precision verbatim for size-bearing types.
        if (preg_match('/\((\d+)\)/', $type, $m) && in_array($mapped, ['varchar', 'char'], true)) {
            return ['type' => $mapped.'('.$m[1].')', 'needs_review' => false, 'note' => null];
        }
        if (preg_match('/\((\d+)\s*,\s*(\d+)\)/', $type, $m) && $mapped === 'numeric') {
            return ['type' => 'numeric('.$m[1].','.$m[2].')', 'needs_review' => false, 'note' => null];
        }
        if (preg_match('/\((\d+)\)/', $type, $m) && in_array($mapped, ['float4', 'float8', 'time', 'timestamp', 'datetime'], true)) {
            // float(p) / time/datetimefsp — the engine type needs no fsp; seconds precision preserved in values.
            return ['type' => $mapped, 'needs_review' => false, 'note' => null];
        }

        // ENUM('a','b') → PostgreSQL enum via ensureEnum; SET → text + review.
        if ($base === 'set') {
            return ['type' => 'text', 'needs_review' => true, 'note' => 'SET members become a PostgreSQL text[]/check decision — review'];
        }

        return ['type' => $mapped, 'needs_review' => false, 'note' => null];
    }

    /** Extract enum member labels from a COLUMN_TYPE like enum('a','b'). */
    public static function enumValues(string $columnType): array
    {
        if (! preg_match('/^enum\((.*)\)$/i', trim($columnType), $m)) {
            return [];
        }
        preg_match_all("/'(?:[^']|'')*'/", $m[1], $labels);

        return array_map(fn ($label) => str_replace("''", "'", substr($label, 1, -1)), $labels[0]);
    }

    /** 31C — unsigned boundary values that MUST survive the mapping. */
    public const UNSIGNED_BOUNDARIES = [
        'tinyint unsigned' => '255',
        'smallint unsigned' => '65535',
        'mediumint unsigned' => '16777215',
        'int unsigned' => '4294967295',
        'bigint unsigned' => '18446744073709551615',
    ];
}
