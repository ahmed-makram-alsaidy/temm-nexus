<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29C — schema inference for Firestore collections.
 *
 * Firestore has NO authoritative schema. Everything this analyzer produces
 * is EVIDENCE from observed documents: field paths, observed types, missing/
 * null frequency, nested maps, array shapes, DocumentReference / GeoPoint /
 * Timestamp / Bytes occurrences. Sample values are NEVER stored — only
 * structural metadata (29B privacy).
 *
 * Bounded by design (29E): inference walks a bounded sample; depth is capped;
 * memory stays proportional to DISTINCT paths, not collection size.
 */
class FirestoreAnalyzer
{
    public const DEFAULT_SAMPLE_SIZE = 100;
    public const MAX_DEPTH = 8;
    public const MAX_FIELDS_PER_COLLECTION = 400;

    public function __construct(
        protected int $maxDepth = self::MAX_DEPTH,
        protected int $maxFields = self::MAX_FIELDS_PER_COLLECTION,
    ) {
    }

    /**
     * Infer the field structure from a sample of decoded REST documents.
     *
     * @param  iterable<array>  $documents  decoded REST document objects ({name, fields})
     * @return array{fields: array<string, array>, max_observed_depth: int, samples: int, truncated: bool, subcollection_ids: list<string>}
     */
    public function infer(iterable $documents): array
    {
        $fields = [];
        $samples = 0;
        $maxDepth = 0;
        $truncated = false;
        $subcollectionIds = [];

        foreach ($documents as $document) {
            $samples++;
            if ($samples >= 100000) {
                $truncated = true;
                break;
            }
            $fieldsMap = (array) ($document['fields'] ?? []);
            $depth = $this->walkFields($fieldsMap, '', 0, $fields);
            $maxDepth = max($maxDepth, $depth);
            if (count($fields) >= $this->maxFields) {
                $truncated = true;
                break;
            }
        }

        $out = [];
        foreach ($fields as $path => $observation) {
            $occurrences = array_sum($observation['types']);
            $missing = max(0, $samples - $occurrences);
            // Dominant type comes from OBSERVED types only — absence is not
            // a type (29C). Missingness is reported separately.
            $observedTypes = $observation['types'];
            arsort($observedTypes);
            $dominant = (string) (array_key_first($observedTypes) ?? 'null');
            $typeCounts = $observation['types'];
            if ($missing > 0) {
                $typeCounts['(missing)'] = $missing;
            }
            arsort($typeCounts);
            $total = array_sum($typeCounts);
            // Variance = OBSERVED TYPE diversity — the '(missing)' bucket is
            // absence, not a type: missing-ness alone is never variance (29C).
            $distinctTypes = count(array_filter($typeCounts, fn ($count, $type) => $count > 0 && $type !== '(missing)', ARRAY_FILTER_USE_BOTH));
            $variance = $distinctTypes > 1; // polymorphism flagged; nullability alone is not variance
            $dominantCount = $typeCounts[$dominant] ?? 0;
            $out[$path] = [
                'path' => $path,
                'column' => NameSanitizer::baseName($path),
                'normalized_type' => FirebaseTypeMapper::normalizedType($dominant),
                'firestore_types' => $observation['types'],
                'frequency_percent' => $total > 0 ? round($dominantCount / $total * 100, 2) : 100.0,
                'missing_or_null_percent' => $samples > 0 ? round((($missing + ($observation['types']['null'] ?? 0)) / $samples) * 100, 2) : 0.0,
                'nullable' => ($observation['types']['null'] ?? 0) > 0 || $missing > 0,
                'missing' => $missing,
                'variance' => $variance,
                'array_shape' => $observation['array_shape'], // null|scalar|map|mixed
                'array_item_types' => $observation['array_item_types'],
                'max_depth' => $observation['max_depth'],
                'overflow' => $observation['overflow'],
            ];
        }
        uksort($out, 'strcmp');

        return ['fields' => $out, 'max_observed_depth' => $maxDepth, 'samples' => $samples, 'truncated' => $truncated, 'subcollection_ids' => $subcollectionIds];
    }

    /** @param array<string, array> $fields accumulator */
    protected function walkFields(array $fieldsMap, string $prefix, int $depth, array &$fields): int
    {
        if ($depth > $this->maxDepth) {
            return $depth; // depth-capped; parents carry the overflow flag
        }
        $localMax = $depth;
        foreach ($fieldsMap as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $type = FirebaseTypeMapper::observedType($value);

            if ($type === 'map') {
                $overflow = $depth + 1 > $this->maxDepth;
                $this->record($fields, $path, ['map'], 0, null, [], $depth + 1, $overflow);
                if (! $overflow) {
                    // mapValue.fields preserves the typed wrappers one level down.
                    $localMax = max($localMax, $this->walkFields((array) ($value['mapValue']['fields'] ?? []), $path, $depth + 1, $fields));
                }
                continue;
            }
            if ($type === 'array') {
                $items = array_values((array) ($value['arrayValue']['values'] ?? []));
                $itemTypes = [];
                foreach ($items as $item) {
                    $itemTypes[FirebaseTypeMapper::observedType($item)] = ($itemTypes[FirebaseTypeMapper::observedType($item)] ?? 0) + 1;
                }
                $shape = $itemTypes === []
                    ? null
                    : (count($itemTypes) === 1 ? array_key_first($itemTypes) : 'mixed');
                $this->record($fields, $path, ['array'], 0, $shape, $itemTypes, $depth + 1);
                // Arrays of MAPS also contribute their element structure as
                // path[] subtrees (bounded by depth) — used for child tables.
                if ($shape === 'map' || $shape === 'mixed') {
                    foreach ($items as $item) {
                        if (FirebaseTypeMapper::observedType($item) === 'map') {
                            $localMax = max($localMax, $this->walkFields((array) ($item['mapValue']['fields'] ?? []), $path.'[]', $depth + 1, $fields));
                        }
                    }
                }
                continue;
            }
            $this->record($fields, $path, [$type], 0, null, [], $depth + 1);
        }

        return $localMax;
    }

    /** @param array<string, array> $fields */
    protected function record(array &$fields, string $path, array $types, int $nulls, ?string $arrayShape, array $arrayItemTypes, int $maxDepth, bool $overflow = false): void
    {
        if (! isset($fields[$path])) {
            $fields[$path] = ['types' => [], 'array_shape' => null, 'array_item_types' => [], 'max_depth' => 0, 'overflow' => false];
        }
        $entry = &$fields[$path];
        foreach ($types as $type) {
            $entry['types'][$type] = ($entry['types'][$type] ?? 0) + 1;
        }
        $entry['array_shape'] = $arrayShape ?? $entry['array_shape'];
        foreach ($arrayItemTypes as $type => $count) {
            $entry['array_item_types'][$type] = ($entry['array_item_types'][$type] ?? 0) + $count;
        }
        $entry['max_depth'] = max($entry['max_depth'], $maxDepth);
        $entry['overflow'] = $entry['overflow'] || $overflow;
    }
}
