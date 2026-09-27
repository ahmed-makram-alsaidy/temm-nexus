<?php

namespace App\Connectors\Mongodb;

/**
 * Phase 28E — probabilistic schema inference for schemaless collections.
 *
 * MongoDB has no authoritative schema: inferred fields are EVIDENCE, never
 * hard schema. For each dot-path the inferer records observed BSON types,
 * type frequency, null/missing rate, array shape, and nesting depth, plus
 * SCHEMA_VARIANCE flags for polymorphic fields (28E.3). Sample values are
 * NEVER stored — only structural metadata (28J.1 privacy).
 *
 * Bounded by design (28E.1): samples come from a bounded cursor; inference
 * depth is capped (28L.2); memory stays proportional to DISTINCT paths, not
 * to collection size.
 */
class SchemaInferer
{
    public const DEFAULT_SAMPLE_SIZE = 100;
    public const MAX_DEPTH = 8;             // 28L.2 — safe inference depth
    public const MAX_FIELDS_PER_COLLECTION = 400;

    public function __construct(
        protected int $maxDepth = self::MAX_DEPTH,
        protected int $maxFields = self::MAX_FIELDS_PER_COLLECTION,
    ) {
    }

    /**
     * Infer the field structure from a sample of tagged BSON documents.
     *
     * @param  iterable<array>  $documents  tagged BSON documents
     * @return array{fields: array<string, array>, max_observed_depth: int, samples: int, truncated: bool}
     */
    public function infer(iterable $documents): array
    {
        $fields = [];
        $samples = 0;
        $maxDepth = 0;
        $truncated = false;

        foreach ($documents as $document) {
            $samples++;
            if ($samples >= 100000) {
                $truncated = true; // hard safety bound
                break;
            }
            // Documents arrive as UNTAGGED field maps (streamFind output) with
            // tagged values; normalize the rare tagged-wrapper case.
            if (isset($document['t']) && $document['t'] === 'document') {
                $document = (array) $document['v'];
            }
            $depth = $this->walkDocument((array) $document, '', 0, $fields);
            $maxDepth = max($maxDepth, $depth);
            if (count($fields) >= $this->maxFields) {
                $truncated = true;
                break;
            }
        }

        // Fold per-field observations into normalized inference records.
        $out = [];
        foreach ($fields as $path => $observation) {
            $occurrences = array_sum($observation['types']);
            $missing = max(0, $samples - $occurrences); // docs where the path is absent
            $typeCounts = $observation['types'];
            if ($missing > 0) {
                $typeCounts['(missing)'] = $missing;
            }
            arsort($typeCounts);
            $total = array_sum($typeCounts);
            $dominant = (string) array_key_first($typeCounts);
            $distinctTypes = count(array_filter($typeCounts, fn ($c) => $c > 0 && $dominant !== '(missing)'));
            $variance = $distinctTypes > 1; // 28E.3 — polymorphic shapes flagged, missing-ness alone is not variance
            $dominantCount = $typeCounts[$dominant] ?? 0;
            $out[$path] = [
                'path' => $path,
                'column' => FieldNameSanitizer::baseName($path),
                'normalized_type' => TypeMapper::normalizedType($dominant),
                'bson_types' => $observation['types'],
                'frequency_percent' => $total > 0 ? round($dominantCount / $total * 100, 2) : 100.0, // 28E.2 — e.g. string 99.8%
                'missing_or_null_percent' => $samples > 0 ? round((($missing + ($observation['types']['null'] ?? 0)) / $samples) * 100, 2) : 0.0,
                'nullable' => ($observation['types']['null'] ?? 0) > 0 || $missing > 0,
                'missing' => $missing,
                'variance' => $variance,
                'array_shape' => $observation['array_shape'], // null|scalar|document|mixed
                'array_item_types' => $observation['array_item_types'],
                'max_depth' => $observation['max_depth'],
                'overflow' => $observation['overflow'],
            ];
        }
        uksort($out, 'strcmp');

        return ['fields' => $out, 'max_observed_depth' => $maxDepth, 'samples' => $samples, 'truncated' => $truncated];
    }

    /** @param array<string, array> $fields accumulator */
    protected function walkDocument(array $document, string $prefix, int $depth, array &$fields): int
    {
        if ($depth > $this->maxDepth) {
            return $depth; // 28L.2 — depth-capped; parents already carry overflow depth
        }
        $localMax = $depth;
        foreach ($document as $key => $tagged) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $type = (string) ($tagged['t'] ?? gettype($tagged));

            if ($type === 'document') {
                $overflow = $depth + 1 > $this->maxDepth;
                $this->record($fields, $path, ['document'], 0, 0, null, [], $depth + 1, $overflow);
                if (! $overflow) {
                    $localMax = max($localMax, $this->walkDocument((array) ($tagged['v'] ?? []), $path, $depth + 1, $fields));
                }
                continue;
            }
            if ($type === 'array') {
                $items = array_values((array) ($tagged['v'] ?? []));
                $itemTypes = [];
                $shape = null;
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['t'])) {
                        $itemTypes[$item['t']] = ($itemTypes[$item['t']] ?? 0) + 1;
                    }
                }
                if ($itemTypes !== []) {
                    $shape = count($itemTypes) === 1 ? array_key_first($itemTypes) : 'mixed';
                } elseif ($items !== []) {
                    $shape = 'scalar';
                }
                $this->record($fields, $path, ['array'], 0, 0, $shape, $itemTypes, $depth + 1);
                // Array of OBJECTS also contributes its element structure as
                // path[] subtrees (28L.1) — bounded by depth.
                if ($shape === 'document' || $shape === 'mixed') {
                    foreach ($items as $item) {
                        if (is_array($item) && ($item['t'] ?? '') === 'document') {
                            $localMax = max($localMax, $this->walkDocument((array) ($item['v'] ?? []), $path.'[]', $depth + 1, $fields));
                        }
                    }
                }
                continue;
            }
            if ($type === 'null' || $type === 'undefined') {
                $this->record($fields, $path, [$type], 1, 0, null, [], $depth + 1);
                continue;
            }
            $this->record($fields, $path, [$type], 0, 0, null, [], $depth + 1);
        }

        return $localMax;
    }

    /** @param array<string, array> $fields */
    protected function record(array &$fields, string $path, array $types, int $nulls, int $missing, ?string $arrayShape, array $arrayItemTypes, int $maxDepth, bool $overflow = false): void
    {
        if (! isset($fields[$path])) {
            $fields[$path] = ['types' => [], 'nulls' => 0, 'missing' => 0, 'array_shape' => null, 'array_item_types' => [], 'max_depth' => 0, 'overflow' => false];
        }
        $entry = &$fields[$path];
        foreach ($types as $type) {
            $entry['types'][$type] = ($entry['types'][$type] ?? 0) + 1;
        }
        $entry['nulls'] += $nulls;
        $entry['missing'] += $missing;
        $entry['array_shape'] = $arrayShape ?? $entry['array_shape'];
        foreach ($arrayItemTypes as $type => $count) {
            $entry['array_item_types'][$type] = ($entry['array_item_types'][$type] ?? 0) + $count;
        }
        $entry['max_depth'] = max($entry['max_depth'], $maxDepth);
        $entry['overflow'] = $entry['overflow'] || $overflow;
    }
}
