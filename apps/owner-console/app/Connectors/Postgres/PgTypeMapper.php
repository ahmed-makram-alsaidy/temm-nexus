<?php

namespace App\Connectors\Postgres;

/**
 * Phase 30C — PostgreSQL type handling.
 *
 * The catalog's format_type() output is preserved VERBATIM as the column's
 * source_type (30C type preservation); a normalized engine type is derived
 * for the target. Types that are NOT in the known set (extension types such
 * as PostGIS geometry, pgvector vector, hstore, custom composites) are
 * flagged NEEDS_REVIEW instead of silently approximated (30C).
 */
class PgTypeMapper
{
    /** Known PG types → normalized engine vocabulary. */
    protected const MAP = [
        'smallint' => 'int2',
        'integer' => 'int4',
        'bigint' => 'int8',
        'real' => 'float4',
        'double precision' => 'float8',
        'numeric' => 'numeric',
        'decimal' => 'numeric',
        'boolean' => 'boolean',
        'text' => 'text',
        'character varying' => 'varchar',
        'character' => 'char',
        'char' => 'char',
        'uuid' => 'uuid',
        'json' => 'json',
        'jsonb' => 'jsonb',
        'bytea' => 'bytea',
        'date' => 'date',
        'time without time zone' => 'time',
        'time with time zone' => 'timetz',
        'timestamp without time zone' => 'timestamp',
        'timestamp with time zone' => 'timestamptz',
        'interval' => 'interval',
        'inet' => 'inet',
        'cidr' => 'cidr',
        'macaddr' => 'macaddr',
        'macaddr8' => 'macaddr8',
        'point' => 'point',
        'line' => 'line',
        'lseg' => 'lseg',
        'box' => 'box',
        'path' => 'path',
        'polygon' => 'polygon',
        'circle' => 'circle',
        'money' => 'money',
        'xml' => 'xml',
        'tsvector' => 'tsvector',
        'tsquery' => 'tsquery',
        'bit' => 'bit',
        'bit varying' => 'varbit',
        'smallserial' => 'int2',
        'serial' => 'int4',
        'bigserial' => 'int8',
        'oid' => 'oid',
        'name' => 'text',
        'regclass' => 'text',
    ];

    /**
     * Normalize one format_type() value, e.g. 'character varying(50)',
     * 'numeric(10,2)', 'integer[]', 'information_schema.cardinal_number'.
     *
     * @return array{type: string, needs_review: bool}
     */
    public static function normalize(string $pgType): array
    {
        $base = self::baseType($pgType);
        // Domains resolve to their base type name already (catalog joins);
        // anything unknown (extension types, composites) needs review.
        $known = self::MAP[$base] ?? null;
        if ($known === null) {
            return ['type' => 'text', 'needs_review' => true];
        }
        // Carry the typmod verbatim for size/precision-bearing types.
        if (preg_match('/\((\d+(?:\s*,\s*\d+)?)\)$/', $pgType, $m)) {
            $type = $known;
            if (in_array($type, ['varchar', 'char', 'numeric'], true)) {
                $args = str_replace(' ', '', $m[1]);

                return ['type' => $type.'('.$args.')', 'needs_review' => false];
            }
        }

        return ['type' => $known, 'needs_review' => false];
    }

    /** Strip array marker + typmod to find the base type name. */
    public static function baseType(string $pgType): string
    {
        $type = trim($pgType);
        if (str_ends_with($type, '[]')) {
            $type = substr($type, 0, -2);
        }
        $type = preg_replace('/\([^)]*\)$/', '', $type) ?? $type;

        return strtolower(trim($type));
    }

    /** Is this a PostgreSQL array type ('integer[]')? */
    public static function isArray(string $pgType): bool
    {
        return str_ends_with(trim($pgType), '[]');
    }

    /** Normalized array type (element wrapped in the engine's jsonb rule). */
    public static function normalizedArrayType(string $pgType): array
    {
        $element = self::normalize(substr(trim($pgType), 0, -2));

        return ['type' => $element['type'].'[]', 'needs_review' => $element['needs_review']];
    }
}
