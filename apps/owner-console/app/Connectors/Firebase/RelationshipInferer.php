<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29D — relationship inference for Firestore collections.
 *
 * Candidates are inferred from, in decreasing strength:
 *   EXPLICIT         — DocumentReference-typed fields (structural evidence);
 *                      subcollection parent links (Firestore tree semantics).
 *   HIGH_CONFIDENCE  — *_id fields whose values populate the target
 *                      collection's document-id domain (sampled).
 *   POSSIBLE         — same-named fields with matching value domains across
 *                      collections (no structural evidence).
 *   UNKNOWN          — *_id-shaped fields with no observable target.
 *
 * ONLY EXPLICIT candidates may become proposed foreign keys (auto_fk) —
 * "strong mappings only" is the 29D contract; everything else stays
 * advisory metadata for the Migration Center and human review.
 */
class RelationshipInferer
{
    public const EXPLICIT = 'EXPLICIT';
    public const HIGH_CONFIDENCE = 'HIGH_CONFIDENCE';
    public const POSSIBLE = 'POSSIBLE';
    public const UNKNOWN = 'UNKNOWN';

    public function __construct(protected int $minDomainOverlap = 1)
    {
    }

    /**
     * @param  array<string, array{fields: array<string, array>}>  $inference  per collection
     * @param  array<string, array<string, true>>  $idDomains  per collection: observed document ids
     * @param  array<string, array<string, array<string, list<string>>>>  $valueDomains  per collection: field path => values (sampled)
     * @return list<array<string, mixed>>
     */
    public function infer(array $inference, array $idDomains, array $valueDomains): array
    {
        $candidates = [];

        foreach ($inference as $collection => $inf) {
            foreach ($inf['fields'] as $path => $field) {
                $types = $field['firestore_types'] ?? [];
                // 1. DocumentReference fields — EXPLICIT structural evidence.
                if (($types['reference'] ?? 0) > 0 && ! str_contains($path, '[]')) {
                    $this->addReferenceCandidates($candidates, $collection, $path, $valueDomains[$collection][$path] ?? [], $idDomains);
                    continue;
                }
                // 2. *_id naming patterns — HIGH_CONFIDENCE when the value
                //    domain overlaps a known collection's document ids.
                if ($this->isIdShaped($path) && ! str_contains($path, '[]')) {
                    $this->addIdPatternCandidates($candidates, $collection, $path, $valueDomains[$collection][$path] ?? [], $idDomains);
                }
            }
        }

        // 3. Matching field domains across collections — POSSIBLE.
        $this->addDomainOverlapCandidates($candidates, $inference, $valueDomains);

        usort($candidates, fn ($a, $b) => [$a['collection'], $a['path']] <=> [$b['collection'], $b['path']]);

        return $candidates;
    }

    /** @param list<array<string, mixed>> $candidates */
    protected function addReferenceCandidates(array &$candidates, string $collection, string $path, array $values, array $idDomains): void
    {
        $references = [];
        foreach ($values as $referenceValue) {
            if (! is_string($referenceValue) || $referenceValue === '') {
                continue;
            }
            $target = FirebaseTypeMapper::referenceCollection($referenceValue);
            if ($target !== '') {
                $references[$target] = true;
            }
        }
        if ($references === []) {
            $candidates[] = $this->candidate($collection, $path, null, self::EXPLICIT, false, 'DocumentReference field — target collection not observed in the sample');
            return;
        }
        foreach (array_keys($references) as $target) {
            $exists = isset($idDomains[$target]);
            $candidates[] = $this->candidate(
                $collection,
                $path,
                $target,
                self::EXPLICIT,
                $exists, // auto FK only when the target collection is part of this import
                'DocumentReference field → '.$target.' (structural reference semantics)'
            );
        }
    }

    /** @param list<array<string, mixed>> $candidates */
    protected function addIdPatternCandidates(array &$candidates, string $collection, string $path, array $values, array $idDomains): void
    {
        $best = null;
        foreach ($idDomains as $target => $ids) {
            if ($target === $collection || $ids === []) {
                continue;
            }
            $overlap = 0;
            foreach ($values as $value) {
                if (is_string($value) && isset($ids[$value])) {
                    $overlap++;
                }
            }
            if ($overlap >= $this->minDomainOverlap) {
                $best = $best === null || $overlap > $best['overlap']
                    ? ['target' => $target, 'overlap' => $overlap]
                    : $best;
            }
        }
        if ($best !== null) {
            $candidates[] = $this->candidate(
                $collection,
                $path,
                $best['target'],
                self::HIGH_CONFIDENCE,
                false, // strong-but-not-structural: stays advisory (29D)
                sprintf('%s values match %d document id(s) of %s', $path, $best['overlap'], $best['target'])
            );

            return;
        }
        if ($values !== []) {
            $candidates[] = $this->candidate($collection, $path, null, self::UNKNOWN, false, $path.' names an id but no target collection matches in the sample');
        }
    }

    /** @param list<array<string, mixed>> $candidates */
    protected function addDomainOverlapCandidates(array &$candidates, array $inference, array $valueDomains): void
    {
        $fieldOwners = [];
        foreach ($valueDomains as $collection => $fields) {
            foreach ($fields as $path => $values) {
                if ($values === [] || str_contains($path, '[]') || $this->isIdShaped($path)) {
                    continue; // *_id naming is handled with its own class
                }
                $fieldOwners[$path][$collection] = array_fill_keys(array_map('strval', $values), true);
            }
        }
        foreach ($fieldOwners as $path => $owners) {
            $collections = array_keys($owners);
            if (count($collections) < 2 || count($collections) > 4) {
                continue;
            }
            foreach ($collections as $source) {
                foreach ($collections as $target) {
                    if ($source === $target) {
                        continue;
                    }
                    $overlap = count(array_intersect_key($owners[$source], $owners[$target]));
                    if ($overlap >= max(2, $this->minDomainOverlap)) {
                        $candidates[] = $this->candidate(
                            $source,
                            $path,
                            $target,
                            self::POSSIBLE,
                            false,
                            sprintf('%d shared %s value(s) with %s — naming/domain similarity only', $overlap, $path, $target)
                        );
                    }
                }
            }
        }
    }

    protected function isIdShaped(string $path): bool
    {
        $base = preg_replace('/^.*\./', '', $path) ?? $path;

        return $base === 'id' || str_ends_with($base, '_id') || $base === 'uid' || str_ends_with($base, 'Id');
    }

    /** @return array<string, mixed> */
    protected function candidate(string $collection, string $path, ?string $target, string $confidence, bool $autoFk, string $reason): array
    {
        return [
            'collection' => $collection,
            'path' => $path,
            'references' => $target,
            'confidence' => $confidence,
            'auto_fk' => $autoFk,
            'reason' => $reason,
        ];
    }
}
