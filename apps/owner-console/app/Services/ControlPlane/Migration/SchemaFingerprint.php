<?php

namespace App\Services\ControlPlane\Migration;

/**
 * Deterministic schema fingerprint (24E.1) shared by the Migration Center
 * and the Schema Diff tool. Covers tables, columns, types, nullability,
 * defaults, PK, FK, indexes, views, materialized views, functions,
 * triggers, enums, and extensions where relevant.
 */
class SchemaFingerprint
{
    /**
     * Compute a stable sha256 over a normalized inventory array. Only shape
     * matters — row counts, comments and internal ordering do not.
     */
    public static function compute(array $inventory): string
    {
        $normalized = self::normalize($inventory);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Normalize an inventory into a canonical sorted shape. */
    public static function normalize(array $inventory): array
    {
        $out = [];

        foreach (['tables', 'views', 'matviews', 'enums', 'functions', 'triggers', 'extensions'] as $kind) {
            $items = $inventory[$kind] ?? [];
            $list = [];
            foreach ($items as $item) {
                $entry = [];
                foreach (['name', 'schema', 'security', 'volatility'] as $field) {
                    if (array_key_exists($field, $item)) {
                        $entry[$field] = $item[$field];
                    }
                }
                switch ($kind) {
                    case 'tables':
                        $cols = [];
                        foreach ($item['columns'] ?? [] as $c) {
                            $cols[] = [
                                'name' => $c['name'],
                                'type' => strtolower($c['type'] ?? ''),
                                'nullable' => (bool) ($c['nullable'] ?? true),
                                'default' => isset($c['default']) ? strtolower(trim((string) $c['default'])) : null,
                            ];
                        }
                        usort($cols, fn ($a, $b) => strcmp($a['name'], $b['name']));
                        $entry['columns'] = $cols;
                        $entry['primary_key'] = array_values($item['primary_key'] ?? []);
                        $fks = [];
                        foreach ($item['foreign_keys'] ?? [] as $fk) {
                            $fks[] = [
                                'column' => $fk['column'],
                                'references_table' => $fk['references_table'],
                                'references_column' => $fk['references_column'],
                            ];
                        }
                        usort($fks, fn ($a, $b) => strcmp($a['column'].$a['references_table'], $b['column'].$b['references_table']));
                        $entry['foreign_keys'] = $fks;
                        $idx = [];
                        foreach ($item['indexes'] ?? [] as $ix) {
                            $idx[] = ['name' => $ix['name'], 'columns' => array_values($ix['columns'] ?? []), 'unique' => (bool) ($ix['unique'] ?? false)];
                        }
                        usort($idx, fn ($a, $b) => strcmp($a['name'], $b['name']));
                        $entry['indexes'] = $idx;
                        break;
                    case 'enums':
                        $entry['values'] = array_values($item['values'] ?? []);
                        break;
                    default:
                        break;
                }
                $list[] = $entry;
            }
            usort($list, fn ($a, $b) => strcmp(($a['schema'] ?? '').'.'.($a['name'] ?? ''), ($b['schema'] ?? '').'.'.($b['name'] ?? '')));
            $out[$kind] = $list;
        }

        return $out;
    }
}
