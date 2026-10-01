<?php

namespace App\Connectors\Firebase\Protocol;

/**
 * Phase 29A — read-only client for the Firebase REST surfaces.
 *
 * Implemented directly over the public REST APIs with ext-openssl JWT
 * signing (29A: no provider SDK dependency, consistent with the platform's
 * self-contained connector packages). ONLY read/list/query calls are ever
 * issued (29 read-only requirement) — the write methods of these APIs are
 * simply never used.
 *
 * Surfaces:
 * - Firestore          https://firestore.googleapis.com/v1/projects/{p}/databases/{db}
 * - Identity Toolkit   https://identitytoolkit.googleapis.com/v1/projects/{p}
 * - Firebase Storage   https://firebasestorage.googleapis.com/v0/b/{bucket}
 * - Cloud Functions    https://cloudfunctions.googleapis.com/v1/projects/{p}/locations/-/functions
 * - OAuth2 token       service-account token_uri
 *
 * Emulator mode (29J): use_emulator=true + emulator_host replaces the
 * https://api endpoints with the local emulator suite over plain http and
 * skips authentication entirely.
 */
class FirebaseRestClient
{
    protected ?ServiceAccountToken $token = null;

    public function __construct(
        protected string $projectId,
        protected string $databaseId = '(default)',
        protected string $storageBucket = '',
        protected bool $emulator = false,
        protected string $emulatorHost = '',
        protected ?ServiceAccount $account = null,
        protected ?FirebaseTransport $transport = null,
    ) {
        $this->transport ??= new HttpFirebaseTransport(function () {
            return $this->bearerToken();
        });
    }

    // ── URL surfaces ─────────────────────────────────────────────────────

    public function firestoreBase(): string
    {
        // 35.5 live finding (real Firestore emulator): the database ID's
        // parentheses are legal path characters — percent-encoding them
        // (%28default%29) makes the API return HTTP 500. Database IDs are
        // restricted to [a-z0-9()-_], so only '%' needs escaping.
        $dbId = str_replace('%', '%25', $this->databaseId);
        if ($this->emulator) {
            return 'http://'.rtrim($this->emulatorHost, '/').'/v1/projects/'.rawurlencode($this->projectId).'/databases/'.$dbId;
        }

        return 'https://firestore.googleapis.com/v1/projects/'.rawurlencode($this->projectId).'/databases/'.$dbId;
    }

    public function authBase(): string
    {
        $prefix = $this->emulator
            ? 'http://'.rtrim($this->emulatorHost, '/')
            : 'https://identitytoolkit.googleapis.com';

        return $prefix.'/v1/projects/'.$this->projectId;
    }

    public function storageBase(): string
    {
        $bucket = $this->storageBucket ?: $this->defaultBucket();
        if ($this->emulator) {
            return 'http://'.rtrim($this->emulatorHost, '/').'/v0/b/'.$bucket;
        }

        return 'https://firebasestorage.googleapis.com/v0/b/'.$bucket;
    }

    public function functionsUrl(): string
    {
        if ($this->emulator) {
            return 'http://'.rtrim($this->emulatorHost, '/').'/cloudfunctions.googleapis.com/v1/projects/'.$this->projectId.'/locations/-/functions';
        }

        return 'https://cloudfunctions.googleapis.com/v1/projects/'.$this->projectId.'/locations/-/functions';
    }

    public function defaultBucket(): string
    {
        return $this->storageBucket !== '' ? $this->storageBucket : $this->projectId.'.appspot.com';
    }

    // ── Firestore (29C/29D/29E) ──────────────────────────────────────────

    /** Top-level collection ids under the database root. */
    public function listRootCollections(): array
    {
        return $this->listCollectionIds($this->firestoreBase().'/documents:listCollectionIds');
    }

    /** Sub-collection ids directly under one document. */
    public function listSubCollections(string $documentPath): array
    {
        $docName = $documentPath;
        if (! str_starts_with($docName, 'projects/')) {
            $docName = $this->firestoreBase().'/documents/'.$documentPath;
        }

        return $this->listCollectionIds($docName.':listCollectionIds');
    }

    protected function listCollectionIds(string $url): array
    {
        $body = $this->decodeNdjsonOrJson($this->request('POST', $url, ['json' => new \stdClass]));
        $ids = [];
        foreach ($body as $entry) {
            foreach ((array) ($entry['collectionIds'] ?? []) as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * List documents of a collection (REST listDocuments) — used for
     * bounded sampling during analysis (29C). Order is not guaranteed.
     *
     * @return list<array> decoded document objects ({name, fields, createTime, updateTime})
     */
    public function listDocuments(string $collectionId, int $pageSize = 100, ?string &$pageToken = null): array
    {
        $query = ['pageSize' => max(1, min($pageSize, 1000))];
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $body = json_decode($this->request('GET', $this->firestoreBase().'/documents/'.rawurlencode($collectionId), ['query' => $query])['body'], true);
        $pageToken = is_array($body) ? (string) ($body['nextPageToken'] ?? '') : '';

        return is_array($body) ? array_values(array_filter((array) ($body['documents'] ?? []), 'is_array')) : [];
    }

    /**
     * Stream ALL documents of one collection in deterministic __name__ order
     * via runQuery, in bounded batches (29E). Resume-safe: a checkpoint is
     * the last emitted document name; pass it as $startAfter to continue.
     *
     * $collectionId may be a subcollection path ('orders/o001/timeline') —
     * the query then runs against the parent document URL with the LEAF id
     * in `from`, as the REST API requires.
     *
     * @param  callable(array):void  $onBatch  receives each decoded batch (list of documents)
     * @return int total documents streamed
     */
    public function streamCollection(string $collectionId, callable $onBatch, int $batchSize = 100, ?string $startAfter = null, ?callable $shouldStop = null): int
    {
        [$leafId, $parentDocPath] = $this->splitCollectionPath($collectionId);
        $total = 0;
        $cursor = $startAfter;
        do {
            $query = [
                'from' => [['collectionId' => $leafId, 'allDescendants' => false]],
                'orderBy' => [['field' => ['fieldPath' => '__name__'], 'direction' => 'ASCENDING']],
                'limit' => max(1, $batchSize),
            ];
            if ($cursor !== null) {
                // startAfter = EXCLUSIVE cursor — the page after the last
                // emitted document, without repeating it (29E determinism).
                $query['startAfter'] = ['values' => [['referenceValue' => $this->documentFullName($cursor)]]];
            }
            $lines = $this->decodeNdjsonOrJson($this->request('POST', $this->queryUrl($parentDocPath), ['json' => ['structuredQuery' => $query]]));
            $batch = [];
            $last = null;
            foreach ($lines as $entry) {
                if (is_array($entry) && isset($entry['document']) && is_array($entry['document'])) {
                    $batch[] = $entry['document'];
                    $last = (string) ($entry['document']['name'] ?? '');
                }
            }
            if ($batch === []) {
                break;
            }
            $onBatch($batch);
            $total += count($batch);
            $cursor = $last;
            if ($shouldStop !== null && $shouldStop($total)) {
                break;
            }
        } while (count($batch) >= $batchSize);

        return $total;
    }

    /**
     * Split a collection path: 'users' → ['users', '']; a subcollection path
     * 'orders/o001/timeline' → ['timeline', 'orders/o001'] (leaf collection
     * id + parent document path).
     *
     * @return array{0: string, 1: string}
     */
    public function splitCollectionPath(string $collectionId): array
    {
        $segments = explode('/', trim($collectionId, '/'));
        if (count($segments) === 1) {
            return [$segments[0], ''];
        }
        $leafId = (string) end($segments);

        return [$leafId, implode('/', array_slice($segments, 0, -1))];
    }

    /** runQuery/aggregation URL for a parent document path ('' = root). */
    protected function queryUrl(string $parentDocPath): string
    {
        if ($parentDocPath === '') {
            return $this->firestoreBase().'/documents:runQuery';
        }

        return $this->firestoreBase().'/documents/'.implode('/', array_map('rawurlencode', explode('/', $parentDocPath))).':runQuery';
    }

    /** Exact collection size via the count aggregation (falls back to stream). */
    public function countCollection(string $collectionId): ?int
    {
        [$leafId, $parentDocPath] = $this->splitCollectionPath($collectionId);
        try {
            $body = [
                'structuredAggregationQuery' => [
                    'structuredQuery' => ['from' => [['collectionId' => $leafId, 'allDescendants' => false]]],
                    'aggregations' => [['alias' => 'count', 'count' => new \stdClass]],
                ],
            ];
            $lines = $this->decodeNdjsonOrJson($this->request('POST', $this->queryUrl($parentDocPath), ['json' => $body]));
            foreach ($lines as $entry) {
                $count = $entry['result']['aggregateFields']['count']['integerValue'] ?? null;
                if ($count !== null) {
                    return (int) $count;
                }
            }

            return null;
        } catch (FirebaseTransportException $e) {
            if ($e->isAuthFailure() || $e->isNotFound()) {
                throw $e;
            }

            return null; // aggregation unsupported → caller falls back to enumeration
        }
    }

    public function documentFullName(string $documentName): string
    {
        if (str_starts_with($documentName, 'projects/')) {
            return $documentName;
        }

        return $this->firestoreBase().'/documents/'.$documentName;
    }

    // ── Firebase Auth (29F) ──────────────────────────────────────────────

    /**
     * List auth users via accounts:batchGet. The listing payload NEVER
     * includes password hashes or salts (29F safety property — enforced by
     * the API surface used, and asserted by tests).
     *
     * @return array{users: list<array>, nextPageToken: string}
     */
    public function authBatchGet(int $maxResults = 1000, ?string &$pageToken = null): array
    {
        $query = ['maxResults' => max(1, min($maxResults, 1000))];
        if ($pageToken !== null && $pageToken !== '') {
            $query['nextPageToken'] = $pageToken;
        }
        $body = json_decode($this->request('GET', $this->authBase().'/accounts:batchGet', ['query' => $query])['body'], true);
        $pageToken = is_array($body) ? (string) ($body['nextPageToken'] ?? '') : '';
        $users = is_array($body) ? array_values(array_filter((array) ($body['users'] ?? []), 'is_array')) : [];

        return ['users' => $users, 'nextPageToken' => $pageToken];
    }

    // ── Firebase Storage (29G) ───────────────────────────────────────────

    /** @return array{items: list<array>, nextPageToken: string, prefixes: list<string>} */
    public function storageListObjects(string $prefix = '', int $maxResults = 1000, ?string &$pageToken = null): array
    {
        $query = ['maxResults' => max(1, min($maxResults, 1000))];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $body = json_decode($this->request('GET', $this->storageBase().'/o', ['query' => $query])['body'], true);
        $pageToken = is_array($body) ? (string) ($body['nextPageToken'] ?? '') : '';

        return [
            'items' => is_array($body) ? array_values(array_filter((array) ($body['items'] ?? []), 'is_array')) : [],
            'nextPageToken' => $pageToken,
            'prefixes' => is_array($body) ? array_values(array_filter((array) ($body['prefixes'] ?? []), 'is_string')) : [],
        ];
    }

    /** Download object bytes (alt=media) — used only when a rehearsal copies content. */
    public function storageDownload(string $objectPath): string
    {
        return $this->request('GET', $this->storageBase().'/o/'.rawurlencode($objectPath).'?alt=media')['body'];
    }

    // ── Cloud Functions (29H) ────────────────────────────────────────────

    /** @return array{functions: list<array>} */
    public function functionsList(): array
    {
        $body = json_decode($this->request('GET', $this->functionsUrl())['body'], true);

        return ['functions' => is_array($body) ? array_values(array_filter((array) ($body['functions'] ?? []), 'is_array')) : []];
    }

    // ── plumbing ─────────────────────────────────────────────────────────

    protected function bearerToken(): string
    {
        if ($this->emulator || $this->account === null) {
            return '';
        }
        $this->token ??= new ServiceAccountToken($this->account, $this->transport);

        return $this->token->token();
    }

    protected function request(string $method, string $url, array $options = []): array
    {
        return $this->transport->send($method, $url, $options);
    }

    /**
     * Firebase streaming endpoints (runQuery/runAggregationQuery) answer with
     * newline-delimited JSON; listCollectionIds answers with a plain array.
     * Decode either.
     */
    protected function decodeNdjsonOrJson(array $response): array
    {
        $body = trim((string) $response['body']);
        if ($body === '') {
            return [];
        }
        if ($body[0] === '[') {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }
        $entries = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }
}
