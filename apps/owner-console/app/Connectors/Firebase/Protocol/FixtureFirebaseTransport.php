<?php

namespace App\Connectors\Firebase\Protocol;

/**
 * Phase 29J — in-memory transport serving the synthetic project fixture.
 *
 * Answers with the EXACT wire format of the production Firebase REST APIs
 * (typed value wrappers, NDJSON query streams, Identity Toolkit payloads),
 * so every parser in the connector is exercised against realistic responses
 * with zero network I/O and zero risk to any real project. This is the
 * connector's sandbox/dogfood mode — it never touches production Firebase.
 */
class FixtureFirebaseTransport implements FirebaseTransport
{
    protected array $data;
    protected array $restDocuments = [];   // collectionId => list of REST document objects (ordered by __name__)
    protected array $storageIndex = [];    // object name => ['contentType', 'content', 'metadata']

    /** Every request served — used to prove the connector only reads (29). */
    public array $requests = [];

    public function __construct(?array $dataset = null)
    {
        $this->data = $dataset ?? require __DIR__.'/../datasets/synthetic-project.php';
        $this->buildIndexes();
    }

    public function send(string $method, string $url, array $options = []): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url];
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        $json = $options['json'] ?? null;

        // ── Firestore ────────────────────────────────────────────────────
        if (str_contains($path, ':listCollectionIds')) {
            return ['status' => 200, 'body' => json_encode(['collectionIds' => $this->collectionIdsFor($path)])];
        }
        if (str_contains($path, ':runAggregationQuery')) {
            $collectionId = $json['structuredAggregationQuery']['structuredQuery']['from'][0]['collectionId'] ?? '';

            return ['status' => 200, 'body' => json_encode([
                ['result' => ['aggregateFields' => ['count' => ['integerValue' => (string) count($this->restDocuments[$collectionId] ?? [])]]]],
            ])];
        }
        if (str_contains($path, ':runQuery')) {
            return $this->runQuery($json);
        }
        if (preg_match('#/documents/([^/:]+)$#', $path, $m) && isset($query['pageSize'])) {
            return $this->listDocumentsPage($m[1], $query);
        }

        // ── Identity Toolkit (29F) ───────────────────────────────────────
        if (str_contains($path, 'accounts:batchGet')) {
            return $this->authBatchGet($query);
        }

        // ── Storage (29G) ────────────────────────────────────────────────
        if (preg_match('#/v0/b/[^/]+/o$#', $path)) {
            return $this->storageList($query);
        }
        if (preg_match('#/v0/b/[^/]+/o/(.+)$#', $path, $m)) {
            $name = rawurldecode($m[1]);
            $object = $this->storageIndex[$name] ?? null;
            if ($object === null) {
                return ['status' => 404, 'body' => json_encode(['error' => ['code' => 404, 'message' => 'Not Found.']])];
            }

            return ['status' => 200, 'body' => (string) $object['content']];
        }

        // ── Cloud Functions (29H) ────────────────────────────────────────
        if (str_contains($path, '/functions')) {
            return ['status' => 200, 'body' => json_encode(['functions' => $this->data['functions'] ?? []])];
        }

        throw new FirebaseTransportException('fixture transport cannot route request to '.HttpFirebaseTransport::safeUrl($url), 0);
    }

    // ── wire-format builders ─────────────────────────────────────────────

    protected function collectionIdsFor(string $path): array
    {
        // Document-scoped listCollectionIds: .../documents/<docPath>:listCollectionIds
        if (preg_match('#/documents/[^:]+:listCollectionIds$#', $path) && ! preg_match('#/documents:listCollectionIds$#', $path)) {
            $before = explode(':listCollectionIds', $path)[0];
            $docPath = preg_replace('#^.*/documents/#', '', $before) ?? '';
            $sub = $this->data['collections']['subcollections'][$docPath] ?? [];

            return array_keys($sub);
        }

        return array_keys(array_diff_key($this->data['collections'], ['subcollections' => 1]));
    }

    protected function runQuery(array $json): array
    {
        $query = $json['structuredQuery'] ?? [];
        $collectionId = $query['from'][0]['collectionId'] ?? '';
        $docs = $this->restDocuments[$collectionId] ?? [];
        $limit = (int) ($query['limit'] ?? 100);
        // startAfter cursor (29E resume, exclusive): resume AFTER the named doc.
        $startRef = $query['startAfter']['values'][0]['referenceValue'] ?? null;
        if ($startRef !== null) {
            foreach ($docs as $i => $doc) {
                if (($doc['name'] ?? '') === $startRef) {
                    $docs = array_slice($docs, $i + 1);

                    break;
                }
            }
        }
        $page = array_slice($docs, 0, $limit);
        $lines = array_map(fn ($doc) => json_encode(['document' => $doc], JSON_UNESCAPED_UNICODE), $page);

        return ['status' => 200, 'body' => implode("\n", $lines)];
    }

    protected function listDocumentsPage(string $collectionId, array $query): array
    {
        $docs = $this->restDocuments[$collectionId] ?? [];
        $pageSize = max(1, (int) ($query['pageSize'] ?? 100));
        $offset = 0;
        if (isset($query['pageToken'])) {
            $decoded = json_decode(base64_decode((string) $query['pageToken']) ?: '0', true);
            $offset = is_array($decoded) ? (int) ($decoded['offset'] ?? 0) : 0;
        }
        $page = array_slice($docs, $offset, $pageSize);
        $next = $offset + $pageSize < count($docs)
            ? base64_encode(json_encode(['offset' => $offset + $pageSize]))
            : '';

        $response = ['documents' => $page];
        if ($next !== '') {
            $response['nextPageToken'] = $next;
        }

        return ['status' => 200, 'body' => json_encode($response, JSON_UNESCAPED_UNICODE)];
    }

    protected function authBatchGet(array $query): array
    {
        $users = $this->data['auth_users'] ?? [];
        $max = max(1, (int) ($query['maxResults'] ?? 1000));
        $decoded = json_decode(base64_decode((string) ($query['nextPageToken'] ?? '')) ?: 'null', true);
        $offset = is_array($decoded) ? (int) ($decoded['offset'] ?? 0) : 0;
        $page = array_slice($users, $offset, $max);
        $next = $offset + $max < count($users) ? base64_encode(json_encode(['offset' => $offset + $max])) : '';
        $response = ['users' => $page];
        if ($next !== '') {
            $response['nextPageToken'] = $next;
        }

        return ['status' => 200, 'body' => json_encode($response, JSON_UNESCAPED_UNICODE)];
    }

    protected function storageList(array $query): array
    {
        $prefix = (string) ($query['prefix'] ?? '');
        $items = [];
        foreach ($this->data['storage_objects'] ?? [] as $object) {
            if ($prefix !== '' && ! str_starts_with($object['name'], $prefix)) {
                continue;
            }
            $content = (string) $object['content'];
            $item = [
                'name' => $object['name'],
                'bucket' => $this->data['storage_bucket'],
                'size' => (string) strlen($content),
                'contentType' => $object['contentType'],
                'md5Hash' => base64_encode(md5($content, true)),
                'timeCreated' => '2026-01-01T00:00:00Z',
                'updated' => '2026-01-01T00:00:00Z',
            ];
            if (isset($object['metadata'])) {
                $item['metadata'] = $object['metadata'];
            }
            $items[] = $item;
        }

        return ['status' => 200, 'body' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)];
    }

    /** Convert the native fixture into REST wire documents, ordered by __name__. */
    protected function buildIndexes(): void
    {
        $project = $this->data['project_id'];
        $database = $this->data['database_id'];
        $base = "projects/{$project}/databases/{$database}/documents";

        foreach ($this->data['collections'] as $key => $docs) {
            if ($key === 'subcollections') {
                foreach ($docs as $parentPath => $children) {
                    foreach ($children as $subId => $subDocs) {
                        $this->restDocuments[$subId] = array_map(
                            fn ($doc) => $this->restDocument($doc, $base.'/'.$parentPath.'/'.$subId.'/'.$doc['_id']),
                            $subDocs
                        );
                    }
                }
                continue;
            }
            $this->restDocuments[$key] = array_map(
                fn ($doc) => $this->restDocument($doc, $base.'/'.$key.'/'.$doc['_id']),
                $docs
            );
        }

        foreach ($this->restDocuments as $collectionId => $docs) {
            usort($docs, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
            $this->restDocuments[$collectionId] = $docs;
        }

        foreach ($this->data['storage_objects'] ?? [] as $object) {
            $this->storageIndex[$object['name']] = $object;
        }
    }

    protected function restDocument(array $native, string $fullName): array
    {
        return [
            'name' => $fullName,
            'fields' => array_map(fn ($value) => $this->wrapValue($value), $native['fields']),
            'createTime' => '2026-01-01T00:00:00.000000Z',
            'updateTime' => '2026-01-01T00:00:00.000000Z',
        ];
    }

    /** Native fixture value → Firestore REST typed wrapper (production format). */
    protected function wrapValue(mixed $value): mixed
    {
        if ($value === null) {
            return ['nullValue' => null];
        }
        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }
        if (is_int($value)) {
            return ['integerValue' => (string) $value]; // int64 travels as string
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }
        if (is_string($value)) {
            if (str_starts_with($value, '@')) {
                if (str_starts_with($value, '@ref:')) {
                    $ref = substr($value, 5);

                    return ['referenceValue' => 'projects/'.$this->data['project_id'].'/databases/'.$this->data['database_id'].'/documents/'.$ref];
                }
                if (str_starts_with($value, '@bytes:')) {
                    return ['bytesValue' => substr($value, 7)];
                }
                if (preg_match('/^@(\d{4}-\d{2}-\d{2}T.*)$/', $value, $m)) {
                    return ['timestampValue' => $m[1]];
                }
            }

            return ['stringValue' => $value];
        }
        if (is_array($value) && isset($value['lat'], $value['lng'])) {
            return ['geoPointValue' => ['latitude' => $value['lat'], 'longitude' => $value['lng']]];
        }
        if (is_array($value) && array_is_list($value)) {
            return ['arrayValue' => ['values' => array_map(fn ($item) => $this->wrapValue($item), $value)]];
        }
        if (is_array($value)) {
            return ['mapValue' => ['fields' => array_map(fn ($item) => $this->wrapValue($item), $value)]];
        }

        return ['stringValue' => (string) $value];
    }
}
