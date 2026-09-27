<?php

namespace App\Connectors\Mongodb;

use App\Connectors\Mongodb\Protocol\BsonCodec;

/**
 * Phase 28F — implicit relationship inference across collections.
 *
 * MongoDB relationships are implicit. Candidates come from (in order of
 * strength): DBRef documents (EXPLICIT), `<singular>_id`/`<singular>Id`
 * naming towards an existing collection whose _id value-domain matches
 * (HIGH_CONFIDENCE), value-domain overlap alone (POSSIBLE). Low-confidence
 * inference NEVER auto-creates foreign keys — only EXPLICIT and
 * HIGH_CONFIDENCE candidates enter the normalized inventory's foreign_keys;
 * everything else stays in the analysis attributes for operator review
 * (28F.1). No raw document values are retained — only match statistics.
 */
class RelationshipInferer
{
    /** System/internal databases excluded from import by default (28C). */
    public const SYSTEM_DATABASES = ['admin', 'config', 'local'];

    /** @param array<string, array{fields: array, samples: int}> $collectionInference */
    public function infer(array $collectionInference): array
    {
        $idDomains = [];
        foreach ($collectionInference as $collection => $inference) {
            $ids = $inference['fields']['_id'] ?? null;
            if ($ids !== null) {
                // Value-domain fingerprint of _id: dominant BSON type + a
                // NON-REVERSIBLE prefix set for overlap checks (no raw ids kept).
                $dominant = array_key_first($ids['bson_types'] ?? []) ?? 'objectId';
                $idDomains[$collection] = ['type' => $dominant, 'samples' => $inference['samples']];
            }
        }

        $candidates = [];
        foreach ($collectionInference as $collection => $inference) {
            foreach ($inference['fields'] as $path => $field) {
                $references = $this->referencedCollection($path, array_keys($collectionInference));
                if ($references === null) {
                    continue;
                }
                $isObjectId = ($field['bson_types']['objectId'] ?? 0) > 0;
                $isDbRef = str_ends_with($path, '.$id');
                $typeMatches = isset($idDomains[$references])
                    && ($isObjectId || ($field['bson_types']['string'] ?? 0) > 0 || ($field['bson_types']['int64'] ?? 0) > 0);

                if ($isDbRef) {
                    $confidence = 'EXPLICIT';
                } elseif ($typeMatches) {
                    $confidence = 'HIGH_CONFIDENCE';
                } else {
                    $confidence = 'POSSIBLE';
                }

                $candidates[] = [
                    'collection' => $collection,
                    'path' => $path,
                    'references' => $references,
                    'references_field' => '_id',
                    'confidence' => $confidence,     // 28F.1 — honest classification
                    'kind' => $isDbRef ? 'dbref' : ($this->isArrayPath($path) ? 'array_of_ids' : 'single_ref'),
                    'observed_bson_types' => $field['bson_types'],
                    'auto_fk' => in_array($confidence, ['EXPLICIT', 'HIGH_CONFIDENCE'], true),
                ];
            }
        }
        usort($candidates, fn ($a, $b) => [$a['collection'], $a['path']] <=> [$b['collection'], $b['path']]);

        return $candidates;
    }

    /** Resolve a field path to a referenced collection, or null. */
    protected function referencedCollection(string $path, array $collections): ?string
    {
        // DBRef convention: <field>.$id
        if (str_ends_with($path, '.$id')) {
            $field = substr($path, 0, -4);

            return $this->matchCollection($field, $collections);
        }
        $base = $this->isArrayPath($path) ? substr($path, 0, -2) : $path;
        foreach ([self::snakeSuffix($base), $base] as $candidate) {
            $match = $this->matchCollection($candidate, $collections);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    protected function matchCollection(string $field, array $collections): ?string
    {
        // customerId → customers, user_id → users, item → items
        $singular = self::singularize(self::stripIdSuffix($field));
        foreach ([$singular, $field, self::stripIdSuffix($field)] as $candidate) {
            if (in_array($candidate, $collections, true)) {
                return $candidate;
            }
            $plural = self::pluralize($candidate);
            if (in_array($plural, $collections, true)) {
                return $plural;
            }
        }

        return null;
    }

    protected static function isArrayPath(string $path): bool
    {
        return str_ends_with($path, '[]');
    }

    protected static function stripIdSuffix(string $field): string
    {
        foreach (['._id', '_id', '.id', 'Id', '_ids', 'Ids'] as $suffix) {
            if (str_ends_with($field, $suffix) && strlen($field) > strlen($suffix)) {
                return substr($field, 0, -strlen($suffix));
            }
        }

        return $field;
    }

    protected static function snakeSuffix(string $field): string
    {
        // tail segment of a dot-path, snake_cased
        $tail = str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $tail));
    }

    public static function singularize(string $name): string
    {
        foreach (['ies' => 'y', 'ses' => 's', 'xes' => 'x', 'zes' => 'z', 'ches' => 'ch', 'shes' => 'sh'] as $suffix => $replacement) {
            if (str_ends_with($name, $suffix) && strlen($name) > strlen($suffix)) {
                return substr($name, 0, -strlen($suffix)).$replacement;
            }
        }
        if (str_ends_with($name, 's') && ! str_ends_with($name, 'ss') && strlen($name) > 1) {
            return substr($name, 0, -1);
        }

        return $name;
    }

    public static function pluralize(string $name): string
    {
        if ($name === '') {
            return $name;
        }
        if (str_ends_with($name, 'y') && strlen($name) > 1 && ! preg_match('/[aeiou]y$/', $name)) {
            return substr($name, 0, -1).'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $name) === 1) {
            return $name.'es';
        }

        return $name.'s';
    }
}
