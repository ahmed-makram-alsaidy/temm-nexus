<?php

/**
 * Phase 28 test infrastructure — a scripted MongoDB wire-protocol server
 * (OP_MSG over TCP) implementing exactly the read commands the connector
 * uses. Runs as a child process on 127.0.0.1:<port>; lets the full Phase 28
 * suite (discovery, inference, extraction, strategies, security) run without
 * Docker or ext-mongodb. The Docker dogfood (28V) exercises the REAL server.
 *
 * Usage: php fake-mongo-server.php <dataset.php> [port] [--auth user:pass]
 * The dataset file must return an array: ['databases' => [...], 'auth' => [...]]
 * (see RunsFakeMongoServer::writeDataset()).
 */

require __DIR__.'/../../../../vendor/autoload.php';

use App\Connectors\Mongodb\Protocol\BsonCodec;

$datasetPath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$authArg = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--auth' && isset($argv[$i + 1])) {
        [$u, $p] = explode(':', $argv[$i + 1], 2);
        $authArg = ['user' => $u, 'pass' => $p];
    }
}
$dataset = require $datasetPath;
// Normalize: every plain value in the dataset becomes tagged BSON (tagged
// fixture values pass through untouched).
$normalize = function (mixed $v) use (&$normalize): mixed {
    if (is_array($v) && isset($v['t']) && is_string($v['t'])) {
        if (isset($v['v']) && is_array($v['v']) && in_array($v['t'], ['document', 'array'], true)) {
            $v['v'] = array_map($normalize, $v['v']);
        }

        return $v;
    }
    if (is_array($v)) {
        return array_map($normalize, $v);
    }

    return $v;
};
$dataset = $normalize($dataset);

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "server failed: {$errstr}\n");
    exit(1);
}
// Signal readiness + the ACTUAL bound port (port 0 → ephemeral).
fwrite(STDOUT, "READY ".stream_socket_get_name($server, false)."\n");
fflush(STDOUT);

$cursorSeq = 1000;
$openCursors = [];
$scramState = [];

function bsonTagged(mixed $v): array
{
    if (is_array($v) && isset($v['t'])) {
        return $v;
    }
    if (is_int($v)) {
        return abs($v) <= 2147483647 ? ['t' => 'int32', 'v' => $v] : ['t' => 'int64', 'v' => $v];
    }
    if (is_bool($v)) {
        return ['t' => 'boolean', 'v' => $v];
    }
    if (is_float($v)) {
        return ['t' => 'double', 'v' => $v];
    }
    if (is_string($v)) {
        return ['t' => 'string', 'v' => $v];
    }
    if ($v === null) {
        return ['t' => 'null', 'v' => null];
    }
    if (is_array($v)) {
        return array_is_list($v)
            ? ['t' => 'array', 'v' => array_map('bsonTagged', $v)]
            : ['t' => 'document', 'v' => array_map('bsonTagged', $v)];
    }
    throw new RuntimeException('unsupported fixture value');
}

/** BSON value comparison (subset: type-order then value) for sort/$gt. */
function bsonCompare(array $a, array $b): int
{
    $order = ['null' => 0, 'int32' => 1, 'int64' => 1, 'double' => 1, 'string' => 2, 'document' => 3, 'array' => 4, 'binary' => 5, 'objectId' => 6, 'boolean' => 7, 'date' => 8];
    $ta = $order[$a['t']] ?? 99;
    $tb = $order[$b['t']] ?? 99;
    if ($ta !== $tb) {
        return $ta <=> $tb;
    }
    $va = $a['v'];
    $vb = $b['v'];
    if (is_float($va) || is_float($vb)) {
        return (float) $va <=> (float) $vb;
    }
    if (is_bool($va) || is_bool($vb)) {
        return (int) $va <=> (int) $vb;
    }

    return strcmp((string) $va, (string) $vb);
}

/** Parse SCRAM attribute lists without URL-decoding (base64 '+' is literal). */
function scramAttrs(string $payload): array
{
    $attrs = [];
    foreach (explode(',', $payload) as $part) {
        $eq = strpos($part, '=');
        if ($eq === false) {
            continue;
        }
        $attrs[substr($part, 0, $eq)] = substr($part, $eq + 1);
    }

    return $attrs;
}

function reply(int $responseTo, array $doc): string
{
    // OP_MSG reply: [4-byte flags=0][1-byte kind=0][BSON body]
    $body = BsonCodec::encodeDocument(array_map('bsonTagged', $doc));
    $section = "\x00\x00\x00\x00"."\x00".$body;
    $header = pack('VVVV', 16 + strlen($section), 1, $responseTo, 2013);

    return $header.$section;
}

function cursorReply(array $docs, int $cursorId, string $ns, string $batchField = 'firstBatch'): array
{
    return [
        'cursor' => ['id' => ['t' => 'int64', 'v' => $cursorId], 'ns' => $ns, $batchField => $docs],
        'ok' => 1,
    ];
}

/** @return resource */
function handleClient($client, array $dataset, ?array $auth, int &$cursorSeq, array &$openCursors, array &$scramState)
{
    while (true) {
        $header = fread($client, 16);
        if ($header === false || strlen($header) < 16) {
            return $client;
        }
        $meta = unpack('Vlength/VrequestId/VresponseTo/Vopcode', $header);
        $bodyLen = $meta['length'] - 16;
        if ($bodyLen <= 0) {
            return $client;
        }
        $payload = '';
        while (strlen($payload) < $bodyLen) {
            $chunk = fread($client, $bodyLen - strlen($payload));
            if ($chunk === false || $chunk === '') {
                return $client;
            }
            $payload .= $chunk;
        }
        $flags = unpack('V', substr($payload, 0, 4))[1];
        $doc = BsonCodec::decodeDocument($payload, 5);
        $command = (string) array_key_first($doc);
        $database = (string) (BsonCodec::untag($doc['$db'] ?? null) ?? 'admin');

        // ── Auth gating ──────────────────────────────────────────────────
        if ($auth !== null && ! in_array($command, ['hello', 'ping', 'saslStart', 'saslContinue'], true) && empty($scramState['authenticated'])) {
            fwrite($client, reply($meta['requestId'], ['ok' => 0, 'errmsg' => 'command requires authentication', 'code' => 13]));

            continue;
        }

        fwrite(STDERR, "CMD {$command} db={$database}
");
        switch ($command) {
            case 'hello':
            case 'ping':
                fwrite($client, reply($meta['requestId'], ['ok' => 1, 'isWritablePrimary' => true, 'maxWireVersion' => 21, 'version' => '7.0.0-fake', 'helloOk' => true]));
                break;

            case 'buildInfo':
                fwrite($client, reply($meta['requestId'], ['ok' => 1, 'version' => '7.0.0-fake']));
                break;

            case 'saslStart':
                // Scripted SCRAM-SHA-256: single round with deterministic salt.
                $payloadStr = (string) (BsonCodec::untag($doc['payload'] ?? null) ?? '');
                // Strip the gs2 header ("n,," or "y,,") then parse n=/r=.
                $bare = preg_match('/^(?:n|y),,/', $payloadStr) === 1 ? substr($payloadStr, 3) : $payloadStr;
                $first = scramAttrs($bare);
                $user = (string) ($first['n'] ?? '');
                if ($user !== ($auth['user'] ?? '')) {
                    fwrite($client, reply($meta['requestId'], ['ok' => 0, 'errmsg' => 'Authentication failed.', 'code' => 18]));

                    break;
                }
                $scramState['nonce'] = ($first['r'] ?? '').'SERVERNONCE';
                $scramState['user'] = $user;
                $scramState['bare'] = $bare;
                $scramState['conversationId'] = 1;
                fwrite($client, reply($meta['requestId'], [
                    'conversationId' => 1, 'done' => false,
                    'payload' => 'r='.$scramState['nonce'].',s='.base64_encode('0123456789abcdef').',i=4096',
                    'ok' => 1,
                ]));
                break;

            case 'saslContinue':
                $payloadStr = (string) (BsonCodec::untag($doc['payload'] ?? null) ?? '');
                // Verify client proof properly (RFC 5802) against the known password.
                $verification = verifyScramProof($payloadStr, $scramState, $auth['pass'] ?? '');
                if (! $verification['ok']) {
                    fwrite($client, reply($meta['requestId'], ['ok' => 0, 'errmsg' => 'Authentication failed.', 'code' => 18]));

                    break;
                }
                $scramState['authenticated'] = true;
                fwrite($client, reply($meta['requestId'], ['conversationId' => 1, 'done' => true, 'payload' => 'v='.$verification['v'], 'ok' => 1]));
                break;

            case 'listDatabases':
                // REAL MongoDB replies {databases: [...], ok} directly (not
                // cursor-shaped) — verified against mongo:7 (28V dogfood).
                $dbs = [];
                foreach ($dataset['databases'] as $name => $db) {
                    $dbs[] = ['name' => $name, 'sizeOnDisk' => 4096, 'empty' => false];
                }
                fwrite($client, reply($meta['requestId'], ['databases' => $dbs, 'ok' => 1]));
                break;

            case 'listCollections':
                $docs = [];
                foreach ($dataset['databases'][$database]['collections'] ?? [] as $name => $coll) {
                    $docs[] = ['name' => $name, 'type' => $coll['type'] ?? 'collection', 'options' => $coll['options'] ?? []];
                }
                fwrite($client, reply($meta['requestId'], cursorReply($docs, 0, $database.'.$cmd.listCollections')));
                break;

            case 'listIndexes':
                $docs = $dataset['databases'][$database]['collections'][collectionName($doc)]['indexes'] ?? [];
                fwrite($client, reply($meta['requestId'], cursorReply($docs, 0, $database.'.$cmd.listIndexes')));
                break;

            case 'count':
                $coll = (string) (BsonCodec::untag($doc['count'] ?? null) ?? '');
                $docs = documentsFor($dataset, $database, $coll);
                fwrite($client, reply($meta['requestId'], ['n' => count($docs), 'ok' => 1]));
                break;

            case 'find':
                $coll = (string) (BsonCodec::untag($doc['find'] ?? null) ?? '');
                $docs = documentsFor($dataset, $database, $coll);
                // sort: {_id: 1}
                $sort = BsonCodec::untag($doc['sort'] ?? null);
                if (is_array($sort) && isset($sort['_id'])) {
                    usort($docs, fn ($a, $b) => bsonCompare($a['_id'], $b['_id']));
                }
                // filter support: {_id: {$gt: X}} and exact matches
                $filter = BsonCodec::untag($doc['filter'] ?? null);
                $docs = applyFilter($docs, is_array($filter) ? $filter : []);
                // limit/skip
                $skip = (int) (BsonCodec::untag($doc['skip'] ?? null) ?: 0);
                $limit = (int) (BsonCodec::untag($doc['limit'] ?? null) ?: 0);
                if ($skip > 0) {
                    $docs = array_slice($docs, $skip);
                }
                if ($limit > 0) {
                    $docs = array_slice($docs, 0, $limit);
                }
                $batchSize = (int) (BsonCodec::untag($doc['batchSize'] ?? null) ?: 101);
                if ($batchSize <= 0) {
                    $batchSize = 101;
                }
                [$batch, $rest] = [$docs, []];
                if (count($docs) > $batchSize) {
                    [$batch, $rest] = [array_slice($docs, 0, $batchSize), array_slice($docs, $batchSize)];
                }
                $cursorId = 0;
                if ($rest !== []) {
                    $cursorId = $cursorSeq++;
                    $openCursors[$cursorId] = ['docs' => $rest, 'ns' => $database.'.'.$coll];
                }
                fwrite($client, reply($meta['requestId'], cursorReply($batch, $cursorId, $database.'.'.$coll)));
                break;

            case 'getMore':
                $cursorId = (int) (BsonCodec::untag($doc['getMore'] ?? null) ?: 0);
                $rest = $openCursors[$cursorId]['docs'] ?? [];
                $ns = $openCursors[$cursorId]['ns'] ?? '';
                $batchSize = (int) (BsonCodec::untag($doc['batchSize'] ?? null) ?: 101);
                if ($batchSize <= 0) {
                    $batchSize = 101;
                }
                $batch = array_slice($rest, 0, $batchSize);
                $remaining = array_slice($rest, $batchSize);
                if ($remaining === []) {
                    unset($openCursors[$cursorId]);
                    $newId = 0;
                } else {
                    $openCursors[$cursorId]['docs'] = $remaining;
                    $newId = $cursorId;
                }
                fwrite($client, reply($meta['requestId'], cursorReply($batch, $newId, $ns, 'nextBatch')));
                break;

            case 'aggregate':
                $pipeline = BsonCodec::untag($doc['pipeline'] ?? null);
                $stages = is_array($pipeline) ? $pipeline : [];
                $isCollStats = false;
                foreach ($stages as $stageTagged) {
                    $stage = BsonCodec::untag($stageTagged);
                    if (is_array($stage) && array_key_exists('$collStats', $stage)) {
                        $isCollStats = true;
                    }
                }
                $coll = (string) (BsonCodec::untag($doc['aggregate'] ?? null) ?? '');
                if ($isCollStats) {
                    $size = 0;
                    $count = 0;
                    foreach (documentsFor($dataset, $database, $coll) as $d) {
                        $size += strlen(BsonCodec::encodeDocument($d));
                        $count++;
                    }
                    fwrite($client, reply($meta['requestId'], cursorReply([['storageStats' => ['size' => $size, 'avgObjSize' => $count > 0 ? (int) ($size / $count) : 0, 'count' => $count]]], 0, $database.'.'.$coll)));
                } else {
                    fwrite($client, reply($meta['requestId'], cursorReply([], 0, $database.'.'.$coll)));
                }
                break;

            default:
                // Write commands are refused — the fake server models the
                // platform's read-only guarantee at the protocol level too.
                fwrite($client, reply($meta['requestId'], ['ok' => 0, 'errmsg' => "not allowed (read-only fake server): {$command}", 'code' => 8000]));
        }
    }
}

function collectionName(array $doc): string
{
    foreach ($doc as $key => $value) {
        if (! in_array($key, ['$db', 'readPreference'], true)) {
            return (string) (BsonCodec::untag($value) ?? '');
        }
    }

    return '';
}

function documentsFor(array $dataset, string $database, string $collection): array
{
    $coll = $dataset['databases'][$database]['collections'][$collection] ?? null;

    return $coll['documents'] ?? [];
}

function applyFilter(array $docs, array $filter): array
{
    if ($filter === []) {
        return $docs;
    }
    return array_values(array_filter($docs, function ($doc) use ($filter) {
        foreach ($filter as $field => $conditionTagged) {
            $condition = BsonCodec::untag($conditionTagged);
            $value = $doc[$field] ?? ['t' => 'null', 'v' => null];
            if (is_array($condition) && isset($condition['$gt'])) {
                $gt = bsonTagged($condition['$gt']);
                if (bsonCompare($value, $gt) <= 0) {
                    return false;
                }
                continue;
            }
            if (bsonCompare($value, bsonTagged($condition)) !== 0) {
                return false;
            }
        }

        return true;
    }));
}

function verifyScramProof(string $clientFinalPayload, array $scramState, string $password): array
{
    $final = scramAttrs($clientFinalPayload);
    $clientProof = base64_decode((string) ($final['p'] ?? ''), true);
    if ($clientProof === false) {
        return false;
    }
    // The auth message covers only the client-final message WITHOUT proof.
    $withoutProof = substr($clientFinalPayload, 0, (int) strrpos($clientFinalPayload, ',p='));
    $bare = $scramState['bare'] ?? '';
    $serverFirst = 'r='.($scramState['nonce'] ?? '').',s='.base64_encode('0123456789abcdef').',i=4096';
    $authMessage = $bare.','.$serverFirst.','.$withoutProof;
    $saltedPassword = hash_pbkdf2('sha256', $password, '0123456789abcdef', 4096, 32, true);
    $clientKey = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
    $storedKey = hash('sha256', $clientKey, true);
    $clientSignature = hash_hmac('sha256', $authMessage, $storedKey, true);
    $expected = $clientKey ^ $clientSignature;
    if (! hash_equals($expected, (string) $clientProof)) {
        return ['ok' => false, 'v' => ''];
    }
    // Server signature over the SAME auth message (RFC 5802).
    $serverKey = hash_hmac('sha256', 'Server Key', $saltedPassword, true);

    return ['ok' => true, 'v' => base64_encode(hash_hmac('sha256', $authMessage, $serverKey, true))];
}

// Serialize access one client at a time (the connector is single-connection).
while ($client = @stream_socket_accept($server, 300)) {
    // Idle timeout: a client that stops sending is dropped so the next
    // connection is served (the connector closes sockets; tests may not).
    stream_set_timeout($client, 5);
    handleClient($client, $dataset, $authArg, $cursorSeq, $openCursors, $scramState);
    fclose($client);
}
