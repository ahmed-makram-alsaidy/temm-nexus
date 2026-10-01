<?php

namespace App\Connectors\Mongodb\ChangeStreams;

use App\Connectors\Mongodb\Protocol\BsonCodec;
use App\Connectors\Mongodb\Protocol\MongoWireClient;
use App\Connectors\Mongodb\TypeMapper;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Migration\Cdc\CdcEvent;
use App\Services\ControlPlane\Migration\Cdc\CdcPositionSource;
use App\Services\ControlPlane\Migration\Cdc\CdcStreamTelemetry;
use App\Services\ControlPlane\Migration\Cdc\ChangeCaptureConnector;

/**
 * Phase 35.6 §9 — REAL MongoDB change stream capture over the pure-PHP
 * wire client (no ext-mongodb). Opens an aggregate $changeStream cursor
 * against the source database (replica set REQUIRED — probe enforces),
 * normalizes insert/update/replace/delete into generic CdcEvents and
 * persists the server's own resume token as the durable position.
 *
 * Resume semantics (§10): the checkpoint carries the LAST DELIVERED
 * position (event _id token, or the reply's postBatchResumeToken when the
 * stream was quiet — both are server-guaranteed no-loss points). An
 * INVALIDATE event is a hard, honest failure (the token can never resume —
 * a re-snapshot is required); restarting "from now" is never attempted.
 * Tampered tokens are refused by the checkpoint signature (32F).
 *
 * Transaction note (§12): single-document changes are atomic; events from
 * multi-document transactions carry txnNumber and are applied as
 * independent idempotent upserts/deletes — the applier converges, and this
 * is the documented guarantee (no cross-document atomicity is claimed).
 */
class MongoChangeStreamCapture implements ChangeCaptureConnector, CdcPositionSource
{
    protected ?\Closure $clientFactory = null;

    public function __construct(
        protected MigrationSource $source,
        /** database/uri/await_ms/get_batch_size/projector (optional callable) */
        protected array $options = [],
    ) {
    }

    public function checkpointKind(): string
    {
        return 'resume_token';
    }

    /** Test seam: fn() => MongoWireClient. */
    public function setClientFactory(\Closure $factory): void
    {
        $this->clientFactory = $factory;
    }

    public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array
    {
        $database = (string) ($this->options['database'] ?? '');
        if ($database === '') {
            throw new \InvalidArgumentException('MongoChangeStreamCapture requires a source database scope');
        }
        $maxEvents = max(1, $maxEvents);
        $awaitMs = (int) ($this->options['await_ms'] ?? 1000);
        $getBatchSize = (int) ($this->options['get_batch_size'] ?? 500);

        $client = $this->newClient();
        $client->connect();

        try {
            $stage = ['$changeStream' => array_filter([
                'fullDocument' => 'updateLookup',
                'resumeAfter' => isset($checkpoint['resume_token'])
                    ? $this->tokenFromPayload($checkpoint['resume_token'])
                    : null,
            ])];
            $reply = $client->run('aggregate', [
                'pipeline' => BsonCodec::tag([$stage], 'array'),
                'cursor' => BsonCodec::tag(['batchSize' => 0], 'document'),
            ], $database);
            MongoWireClient::assertOk($reply, 'aggregate($changeStream)');

            $cursorDoc = MongoWireClient::docField($reply, 'cursor');
            $cursorId = MongoWireClient::intField($cursorDoc, 'id', 0);
            if ($cursorId === 0) {
                throw new \RuntimeException('change stream cursor closed immediately — is the source a replica set? (change streams require one)');
            }

            $delivered = 0;
            $lastToken = $checkpoint['resume_token'] ?? null;
            $lastEventTs = $checkpoint['last_event_at'] ?? null;
            $sourceClusterTime = (int) ($checkpoint['source_cluster_time'] ?? 0);
            $appliedClusterTime = (int) ($checkpoint['applied_cluster_time'] ?? 0);
            $batch = [];

            $flush = function () use (&$batch, &$delivered, &$lastToken, &$appliedClusterTime, $onBatch): void {
                if ($batch === []) {
                    return;
                }
                $onBatch($batch); // synchronous apply — delivery == applied
                $delivered += count($batch);
                $batch = [];
            };

            $process = function (array $event) use (&$batch, &$lastToken, &$lastEventTs, &$sourceClusterTime, &$appliedClusterTime): void {
                $token = MongoWireClient::docField($event, '_id');
                if ($token !== []) {
                    $lastToken = $this->tokenToPayload($token);
                }
                $seconds = $this->clusterTimeSeconds($event['clusterTime'] ?? null);
                $sourceClusterTime = max($sourceClusterTime, $seconds);

                $op = (string) ($event['operationType']['v'] ?? '');
                $row = null;
                switch ($op) {
                    case 'insert':
                    case 'replace':
                    case 'update':
                        $full = MongoWireClient::docField($event, 'fullDocument');
                        if ($full === []) {
                            return; // document deleted concurrently — its delete event follows; never invent a row
                        }
                        $ns = MongoWireClient::docField($event, 'ns');
                        $table = (string) ($ns['coll']['v'] ?? '');
                        $row = $this->projectRow($full);
                        $ts = $this->clusterTimeToIso($seconds);
                        $eventObj = new CdcEvent(
                            $table,
                            $op === 'insert' ? CdcEvent::INSERT : CdcEvent::UPDATE,
                            $row,
                            (string) ($token['_data']['v'] ?? ''),
                            null,
                            null,
                            $ts,
                        );
                        $batch[] = $eventObj;
                        $lastEventTs = $ts;
                        $appliedClusterTime = max($appliedClusterTime, $seconds);
                        break;

                    case 'delete':
                        $ns = MongoWireClient::docField($event, 'ns');
                        $table = (string) ($ns['coll']['v'] ?? '');
                        $key = MongoWireClient::docField($event, 'documentKey');
                        $idTagged = $key['_id'] ?? null;
                        if (! is_array($idTagged)) {
                            throw new \RuntimeException("delete event for {$table} carried no documentKey._id — cannot identify the row");
                        }
                        $row = ['_id' => TypeMapper::convertValue($idTagged)];
                        $ts = $this->clusterTimeToIso($seconds);
                        $batch[] = new CdcEvent($table, CdcEvent::DELETE, $row, (string) ($token['_data']['v'] ?? ''), null, null, $ts);
                        $lastEventTs = $ts;
                        $appliedClusterTime = max($appliedClusterTime, $seconds);
                        break;

                    case 'invalidate':
                        throw new \RuntimeException('change stream INVALIDATED (drop/rename/collection replace) — the resume token can never recover; re-run the snapshot for this scope. TEMM never silently restarts capture "from now" (35.6 §10).');

                    default:
                        // drop / dropDatabase / rename / showProfile / other
                        // DDL events advance the token (below) but carry no
                        // row change to apply — documented, never silent data.
                        return;
                }
            };

            foreach ($this->documentsIn(MongoWireClient::listField($cursorDoc, 'firstBatch')) as $event) {
                $process($event);
            }
            $sourceClusterTime = max($sourceClusterTime, $this->replyClusterSeconds($reply), $this->postBatchResumeSeconds($cursorDoc));
            $flush();
            $lastToken = $this->tokenFromCursorDoc($cursorDoc) ?? $lastToken;

            while ($delivered < $maxEvents && $cursorId !== 0) {
                $ns = (string) ($cursorDoc['ns']['v'] ?? '');
                $collection = str_contains($ns, '.') ? substr($ns, strpos($ns, '.') + 1) : '';
                $reply = $client->getMoreAwait($database, $collection, $cursorId, min($getBatchSize, $maxEvents - $delivered), $awaitMs);
                MongoWireClient::assertOk($reply, 'getMore($changeStream)');
                $cursorDoc = MongoWireClient::docField($reply, 'cursor');
                $cursorId = MongoWireClient::intField($cursorDoc, 'id', 0);
                $batchEvents = MongoWireClient::listField($cursorDoc, 'nextBatch');
                foreach ($this->documentsIn($batchEvents) as $event) {
                    $process($event);
                }
                $sourceClusterTime = max($sourceClusterTime, $this->replyClusterSeconds($reply), $this->postBatchResumeSeconds($cursorDoc));
                $flush();
                $token = $this->tokenFromCursorDoc($cursorDoc);
                if ($token !== null) {
                    $lastToken = $token;
                }
                if ($batchEvents === []) {
                    break; // quiet await round — cycle complete, position advanced to postBatchResumeToken
                }
            }

            return [
                'resume_token' => $lastToken,
                'applied_cluster_time' => $appliedClusterTime,
                'source_cluster_time' => $sourceClusterTime,
                'last_event_at' => $lastEventTs,
                'database' => $database,
                'events' => $delivered,
                'captured_at' => now()->toIso8601String(),
            ];
        } finally {
            $client->close();
        }
    }

    // ── CdcPositionSource (35.6 §16/§19) ────────────────────────────────

    /**
     * The CURRENT cluster time: a throwaway $changeStream cursor's
     * postBatchResumeToken (the server's own "now" position).
     */
    public function currentSourcePosition(): array
    {
        $database = (string) ($this->options['database'] ?? '');
        $client = $this->newClient();
        $client->connect();
        try {
            $stage = ['$changeStream' => ['allChangesForCluster' => false]];
            $reply = $client->run('aggregate', [
                'pipeline' => BsonCodec::tag([$stage], 'array'),
                'cursor' => BsonCodec::tag(['batchSize' => 0], 'document'),
            ], $database);
            MongoWireClient::assertOk($reply, 'aggregate($changeStream)');
            $cursorDoc = MongoWireClient::docField($reply, 'cursor');
            $seconds = $this->postBatchResumeSeconds($cursorDoc);
            if ($seconds === 0) {
                $opTime = MongoWireClient::docField($reply, 'operationTime');
                $seconds = is_array($opTime) ? (int) ($opTime['seconds'] ?? 0) : 0;
            }

            return [
                'position' => ['cluster_time' => $seconds],
                'label' => 'cluster time T'.$seconds,
                'captured_at' => now()->toIso8601String(),
            ];
        } finally {
            $client->close();
        }
    }

    public function hasAppliedThrough(?array $checkpoint, array $sourcePosition): bool
    {
        if ($checkpoint === null) {
            return false;
        }
        $applied = (int) ($checkpoint['applied_cluster_time'] ?? 0);
        $source = (int) ($sourcePosition['cluster_time'] ?? 0);
        if ($applied === 0 || $source === 0) {
            return false;
        }
        // Cluster time has second resolution — a 1s tolerance avoids a
        // false "behind" when apply and probe land in the same second.
        return $applied + 1 >= $source;
    }

    public function lagSnapshot(?array $checkpoint): array
    {
        if ($checkpoint === null || ! isset($checkpoint['resume_token'])) {
            return [
                'status' => CdcStreamTelemetry::IDLE,
                'source_position_label' => null,
                'captured_position_label' => null,
                'applied_position_label' => null,
                'lag_seconds' => null,
                'lag_events' => null,
                'last_event_at' => null,
                'mechanism' => 'change_streams',
                'detail' => ['note' => 'no checkpoint yet'],
            ];
        }
        $applied = (int) ($checkpoint['applied_cluster_time'] ?? 0);
        $source = max($applied, (int) ($checkpoint['source_cluster_time'] ?? 0));
        $lagSeconds = null;
        if (is_string($checkpoint['last_event_at'] ?? null) && $checkpoint['last_event_at'] !== '') {
            try {
                $lagSeconds = max(0.0, now()->diffInSeconds(new \DateTimeImmutable($checkpoint['last_event_at'])));
            } catch (\Exception) {
                $lagSeconds = null;
            }
        }

        return [
            'status' => $source - $applied <= 1 ? CdcStreamTelemetry::CAUGHT_UP : CdcStreamTelemetry::ACTIVE,
            'source_position_label' => $source > 0 ? 'cluster time T'.$source : null,
            'captured_position_label' => $applied > 0 ? 'cluster time T'.$applied : null,
            'applied_position_label' => $applied > 0 ? 'cluster time T'.$applied : null,
            'lag_seconds' => $lagSeconds,
            'lag_events' => null, // change streams expose no backlog counts — honest null
            'last_event_at' => $checkpoint['last_event_at'] ?? null,
            'mechanism' => 'change_streams',
            'detail' => [
                'cluster_time_lag_seconds' => max(0, $source - $applied),
                'database' => $checkpoint['database'] ?? null,
            ],
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** Untag a batch of BSON documents (listField yields tagged docs). */
    protected function documentsIn(array $taggedBatch): \Generator
    {
        foreach ($taggedBatch as $element) {
            if (is_array($element) && ($element['t'] ?? null) === 'document' && is_array($element['v'])) {
                yield (array) $element['v'];
            }
        }
    }

    /** postBatchResumeToken seconds from a cursor document (0 when absent). */
    protected function postBatchResumeSeconds(array $cursorDoc): int
    {
        $token = MongoWireClient::docField($cursorDoc, 'postBatchResumeToken');
        if ($token === []) {
            return 0;
        }

        return $this->clusterTimeSeconds($token['clusterTime'] ?? null);
    }

    /** The postBatchResumeToken as a storable payload (null when absent). */
    protected function tokenFromCursorDoc(array $cursorDoc): ?array
    {
        $token = MongoWireClient::docField($cursorDoc, 'postBatchResumeToken');

        return $token === [] ? null : $this->tokenToPayload($token);
    }

    /**
     * Tagged BSON token → JSON-safe array (binary → hex wrapper). Resume
     * tokens ride inside checkpoint JSON, so raw bytes are not allowed.
     */
    protected function tokenToPayload(array $tagged): array
    {
        $out = [];
        foreach ($tagged as $key => $value) {
            $out[$key] = $this->jsonSafe($value);
        }

        return $out;
    }

    /** JSON-safe payload → tagged BSON document for resumeAfter. */
    protected function tokenFromPayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $out[$key] = $this->fromJsonSafe($value);
        }

        return $out;
    }

    protected function jsonSafe(mixed $tagged): mixed
    {
        if (! is_array($tagged) || ! isset($tagged['t'])) {
            return $tagged;
        }
        return match ($tagged['t']) {
            'document', 'array' => $this->tokenToPayload(is_array($tagged['v']) ? $tagged['v'] : []),
            'binary' => ['__temm_bin' => bin2hex((string) $tagged['v']), 'subtype' => (int) ($tagged['subtype'] ?? 0)],
            'objectId' => ['__temm_oid' => (string) $tagged['v']],
            'timestamp' => ['__temm_ts' => (int) ($tagged['v'] ?? 0), 'seconds' => (int) ($tagged['seconds'] ?? 0)],
            'datetime' => ['__temm_date' => (int) $tagged['v']],
            'int64' => (int) $tagged['v'],
            'int32' => (int) $tagged['v'],
            'double' => (float) $tagged['v'],
            'boolean' => (bool) $tagged['v'],
            'null' => null,
            default => (string) $tagged['v'],
        };
    }

    protected function fromJsonSafe(mixed $value): mixed
    {
        if (is_array($value)) {
            if (isset($value['__temm_bin'])) {
                return BsonCodec::tag(hex2bin((string) $value['__temm_bin']), 'binary', ['subtype' => (int) ($value['subtype'] ?? 0)]);
            }
            if (isset($value['__temm_oid'])) {
                return BsonCodec::tag((string) $value['__temm_oid'], 'objectId');
            }
            if (isset($value['__temm_ts'])) {
                return BsonCodec::tag((int) $value['__temm_ts'], 'timestamp', ['seconds' => (int) ($value['seconds'] ?? 0)]);
            }
            if (isset($value['__temm_date'])) {
                return BsonCodec::tag((int) $value['__temm_date'], 'date');
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->fromJsonSafe($v);
            }

            return BsonCodec::tag($out, 'document');
        }
        if (is_string($value)) {
            return BsonCodec::tag($value, 'string');
        }
        if (is_int($value)) {
            return BsonCodec::tag($value, 'int64');
        }
        if (is_float($value)) {
            return BsonCodec::tag($value, 'double');
        }
        if (is_bool($value)) {
            return BsonCodec::tag($value, 'boolean');
        }

        return $value; // null
    }

    /** The reply's operationTime (tagged BSON timestamp) = server "now". */
    protected function replyClusterSeconds(array $reply): int
    {
        return $this->clusterTimeSeconds($reply['operationTime'] ?? null);
    }

    /** clusterTime is a tagged BSON timestamp: seconds + increment. */
    protected function clusterTimeSeconds(mixed $tagged): int
    {
        return is_array($tagged) && ($tagged['t'] ?? null) === 'timestamp'
            ? (int) ($tagged['seconds'] ?? 0)
            : 0;
    }

    protected function clusterTimeToIso(int $seconds): string
    {
        $dt = \DateTimeImmutable::createFromFormat('U', (string) $seconds, new \DateTimeZone('UTC'));

        return $dt === false ? '' : $dt->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Fallback row projection: _id + top-level fields; scalars convert via
     * the connector TypeMapper, nested documents/arrays become canonical
     * JSON (the same representation the snapshot path uses for JSONB).
     * A connector may inject a collection-aware projector via options.
     */
    protected function projectRow(array $taggedFullDocument): array
    {
        if (isset($this->options['projector']) && is_callable($this->options['projector'])) {
            return (array) ($this->options['projector'])($taggedFullDocument);
        }
        $document = isset($taggedFullDocument['t']) && $taggedFullDocument['t'] === 'document'
            ? (array) $taggedFullDocument['v']
            : $taggedFullDocument;
        $row = [];
        foreach ($document as $field => $value) {
            $row[$field] = is_array($value) && isset($value['t']) && in_array($value['t'], ['document', 'array'], true)
                ? TypeMapper::canonicalJson($value)
                : TypeMapper::convertValue($value);
        }

        return $row;
    }

    protected function newClient(): MongoWireClient
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)();
        }
        $config = $this->source->connection ?? [];
        $uri = $this->effectiveUri($config);
        $secret = $this->sourceSecret('uri');

        return new MongoWireClient(
            $uri,
            (int) ($config['server_selection_timeout_ms'] ?? 10000),
            (string) ($config['read_preference'] ?? 'primary'),
            $secret !== '' ? MongoWireClient::parseUri($uri) : null,
        );
    }

    /** Same URI resolution as the source adapter (28B) — never logged. */
    protected function effectiveUri(array $config): string
    {
        $uri = $this->sourceSecret('uri');
        $database = (string) ($this->options['database'] ?? ($config['database'] ?? ''));
        if ($uri !== '') {
            if ($database !== '' && ! preg_match('/\/([^\/?]+)(\?|$)/', $uri)) {
                $uri = rtrim($uri, '/').'/'.$database;
            }

            return $uri;
        }
        $host = (string) ($config['host'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException('mongodb change stream capture requires host configuration');
        }
        $username = $this->sourceSecret('username');
        $password = $this->sourceSecret('password');
        $auth = $username !== '' ? rawurlencode($username).':'.rawurlencode($password).'@' : '';
        $port = (int) ($config['port'] ?? 27017);
        $tls = ($config['tls'] ?? false) ? '?tls=true' : '';
        $authSource = $config['auth_source'] ?? null;
        if ($authSource !== null) {
            $tls = $tls === '' ? '?authSource='.$authSource : $tls.'&authSource='.$authSource;
        }

        return sprintf('mongodb://%s%s:%d/%s%s', $auth, $host, $port, $database, $tls);
    }

    protected function sourceSecret(string $field): string
    {
        $refs = (array) ($this->source->secret_refs ?? []);
        $name = $refs[$field] ?? null;
        if (! is_string($name) || $name === '') {
            return '';
        }
        $values = \App\Services\ControlPlane\SecretService::valuesFor($this->source->project, [$name]);

        return (string) ($values[$name] ?? '');
    }
}
