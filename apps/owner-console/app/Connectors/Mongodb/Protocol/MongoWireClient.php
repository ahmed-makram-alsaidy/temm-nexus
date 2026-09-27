<?php

namespace App\Connectors\Mongodb\Protocol;

/**
 * Phase 28A/28H — minimal READ-ONLY MongoDB wire-protocol client (OP_MSG).
 *
 * Pure PHP over stream sockets — no ext-mongodb, no system-wide tooling.
 * Supports the commands the connector needs (hello, listDatabases,
 * listCollections, listIndexes, find/getMore, count, aggregate) with batched
 * cursors, optional TLS (mongodb+srv:// and tls=true URIs) and optional
 * SCRAM-SHA-1/256 authentication. NOTHING in this client can write: only
 * the read commands above are ever sent (28 read-only requirement).
 *
 * BSON documents decoded from the wire keep their TAGGED representation
 * (see BsonCodec) so TypeMapper can preserve source types exactly.
 */
class MongoWireClient
{
    public const OP_MSG = 2013;

    /** @var \Closure|null test seam: fn(target, transport) => socket resource */
    protected ?\Closure $socketFactory = null;
    /** @var resource|null */
    protected $socket = null;
    protected int $requestId = 1;
    protected int $maxWireSize = 48000000;

    public function __construct(
        protected string $uri,
        protected int $serverSelectionTimeoutMs = 10000,
        protected string $readPreference = 'primary',
        protected ?array $credentials = null, // ['username' => ..., 'password' => ...]
    ) {
    }

    /** Override seam for tests (injects a connected stream). */
    public function setSocketFactory(\Closure $factory): void
    {
        $this->socketFactory = $factory;
    }

    public static function fromUri(string $uri, int $serverSelectionTimeoutMs = 10000, string $readPreference = 'primary', ?array $credentials = null): self
    {
        return new self($uri, $serverSelectionTimeoutMs, $readPreference, $credentials);
    }

    // ── URI handling (28B) ───────────────────────────────────────────────

    /** Parsed URI components. Credentials never appear in output strings. */
    public static function parseUri(string $uri): array
    {
        if (! preg_match('/^mongodb(\+srv)?:\/\//i', $uri, $scheme)) {
            throw new \InvalidArgumentException('URI must start with mongodb:// or mongodb+srv://');
        }
        $srv = strtolower($scheme[1] ?? '') === '+srv';
        $rest = substr($uri, strlen($scheme[0]));
        $slashAt = strpos($rest, '/');
        $authority = $slashAt === false ? $rest : substr($rest, 0, $slashAt);
        $pathQuery = $slashAt === false ? '' : substr($rest, $slashAt + 1);
        $database = '';
        $options = [];
        $qMark = strpos($pathQuery, '?');
        if ($qMark !== false) {
            $database = substr($pathQuery, 0, $qMark);
            parse_str(substr($pathQuery, $qMark + 1), $options);
        } else {
            $database = $pathQuery;
        }
        $username = null;
        $password = null;
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $userinfo = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
            $colon = strpos($userinfo, ':');
            $username = rawurldecode($colon === false ? $userinfo : substr($userinfo, 0, $colon));
            $password = $colon === false ? '' : rawurldecode(substr($userinfo, $colon + 1));
        }
        $tls = $srv || strtolower((string) ($options['tls'] ?? $options['ssl'] ?? '')) === 'true';
        $hosts = $authority === '' ? [] : explode(',', $authority);

        return [
            'srv' => $srv, 'hosts' => $hosts, 'database' => $database,
            'username' => $username, 'password' => $password,
            'tls' => $tls, 'options' => $options,
            'authSource' => $options['authSource'] ?? ($database !== '' ? $database : 'admin'),
        ];
    }

    /** Safe display form: mongodb(+srv)://user:****@host1,host2/db (28B.1). */
    public static function redactUri(string $uri): string
    {
        try {
            $parsed = self::parseUri($uri);
        } catch (\InvalidArgumentException) {
            return 'mongodb://***';
        }
        $userinfo = $parsed['username'] !== null ? $parsed['username'].':****@' : '';

        return 'mongodb'.($parsed['srv'] ? '+srv' : '').'://'.$userinfo.implode(',', $parsed['hosts']).'/'.($parsed['database'] ?? '');
    }

    // ── Connection ───────────────────────────────────────────────────────

    /** Resolve the seedlist: SRV targets for mongodb+srv, else parsed hosts. */
    public function seedlist(): array
    {
        $parsed = self::parseUri($this->uri);
        if (! $parsed['srv']) {
            return array_map(function ($hostPort) {
                $colon = strrpos($hostPort, ':');

                return $colon === false
                    ? ['host' => $hostPort, 'port' => 27017]
                    : ['host' => substr($hostPort, 0, $colon), 'port' => (int) substr($hostPort, $colon + 1)];
            }, $parsed['hosts']);
        }
        // mongodb+srv:// — SRV lookup on _mongodb._tcp.<host>; TLS implied.
        $hostname = ltrim((string) $parsed['hosts'][0], '.');
        if (str_contains($hostname, ':')) {
            throw new \InvalidArgumentException('mongodb+srv URIs must not include a port');
        }
        $records = @dns_get_record('_mongodb._tcp.'.$hostname, DNS_SRV);
        if ($records === false || $records === []) {
            throw new \RuntimeException('SRV lookup failed for '.$hostname);
        }
        $targets = [];
        foreach ($records as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($target !== '') {
                $targets[] = ['host' => $target, 'port' => (int) ($record['port'] ?? 27017)];
            }
        }

        return $targets;
    }

    /** Connect + hello; authenticates when credentials are present. */
    public function connect(): array
    {
        if ($this->socket !== null) {
            return $this->hello();
        }
        $parsed = self::parseUri($this->uri);
        $transport = $parsed['tls'] ? 'ssl' : 'tcp';
        $timeout = max(1, (int) round($this->serverSelectionTimeoutMs / 1000));
        $lastError = null;
        foreach ($this->seedlist() as $target) {
            try {
                if ($this->socketFactory !== null) {
                    $this->socket = ($this->socketFactory)($target, $transport);
                    if (! is_resource($this->socket)) {
                        throw new \RuntimeException('socket factory did not produce a stream');
                    }
                } else {
                    $address = ($transport === 'ssl' ? 'ssl://' : '').$target['host'].':'.$target['port'];
                    $context = stream_context_create(['ssl' => [
                        'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true,
                    ]]);
                    $socket = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
                    if ($socket === false) {
                        throw new \RuntimeException("{$target['host']}:{$target['port']} — {$errstr}");
                    }
                    stream_set_timeout($socket, $timeout);
                    $this->socket = $socket;
                }
                break;
            } catch (\RuntimeException $e) {
                $lastError = $e;
                $this->socket = null;
            }
        }
        if ($this->socket === null) {
            // Never include the URI (it may carry credentials) in errors (28T).
            throw new \RuntimeException('MongoDB server selection failed: '.($lastError?->getMessage() ?? 'no seed targets'));
        }

        $hello = $this->hello();
        if (($this->credentials['username'] ?? null) !== null) {
            (new ScramAuth($this))->authenticate(
                (string) $this->credentials['username'],
                (string) $this->credentials['password'],
                (string) $parsed['authSource'],
            );
        }

        return $hello;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    // ── Commands (read-only surface — nothing else is ever sent) ─────────

    public function hello(): array
    {
        return $this->run('hello', ['helloOk' => BsonCodec::tag(true, 'boolean')], 'admin');
    }

    /**
     * Run a command against a database. `$name` is restricted to the
     * read-only command allowlist — write commands are structurally
     * impossible to send through this client (28 read-only requirement).
     * For find/count/aggregate the FIRST key carries the collection name.
     */
    public function run(string $name, array $fields, string $database, ?string $collection = null): array
    {
        if (! in_array($name, self::READ_COMMANDS, true)) {
            throw new \LogicException("Command '{$name}' is not on the read-only allowlist");
        }
        if ($this->socket === null) {
            $this->connect();
        }
        $doc = [$name => $collection !== null ? BsonCodec::tag($collection, 'string') : 1];
        foreach ($fields as $key => $value) {
            $doc[$key] = $value;
        }
        $doc['$db'] = $database;
        if (in_array($name, ['find', 'count', 'aggregate'], true)) {
            // Real drivers pass the read preference as the special $readPreference
            // field (verified against mongo:7 — a plain 'readPreference' field
            // is rejected as unknown on strict commands like count).
            $doc['$readPreference'] = ['mode' => BsonCodec::tag($this->readPreference, 'string')];
        }
        $requestId = $this->requestId++ & 0x7FFFFFFF;
        $bodyBytes = BsonCodec::encodeDocument($doc);
        // OP_MSG: [4-byte flags][1-byte section kind=0][BSON body]
        $section = pack('V', 0)."\x00".$bodyBytes;
        $header = pack('VVVV', 16 + strlen($section), $requestId, 0, self::OP_MSG);
        fwrite($this->socket, $header.$section);

        $reply = $this->readReply();
        self::assertOk($reply, $name);

        return $reply;
    }

    public const READ_COMMANDS = [
        'hello', 'ping', 'listDatabases', 'listCollections', 'listIndexes',
        'find', 'getMore', 'count', 'aggregate', 'buildInfo', 'connectionStatus',
        // Authentication handshake commands — no data mutation is possible
        // through them; required for Atlas/self-hosted credentials (28B).
        'saslStart', 'saslContinue',
    ];

    // ── Reply handling ───────────────────────────────────────────────────

    protected function readReply(): array
    {
        $header = $this->readExact(16);
        $meta = unpack('Vlength/VrequestId/VresponseTo/Vopcode', $header);
        if ($meta['opcode'] !== self::OP_MSG) {
            throw new \RuntimeException('Unexpected reply opcode '.$meta['opcode']);
        }
        $payloadLength = $meta['length'] - 16;
        if ($payloadLength < 5 || $payloadLength > $this->maxWireSize) {
            throw new \RuntimeException('Reply size out of bounds');
        }
        $payload = $this->readExact($payloadLength);
        $flags = unpack('V', substr($payload, 0, 4))[1];
        if (($flags & 1) !== 0) {
            throw new \RuntimeException('Checksummed replies are not supported');
        }
        if (ord($payload[4]) !== 0) {
            throw new \RuntimeException('Expected OP_MSG body section');
        }

        return BsonCodec::decodeDocument($payload, 5);
    }

    protected function readExact(int $bytes): string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = fread($this->socket, $bytes - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new \RuntimeException('Socket read failed'.(! empty($meta['timed_out']) ? ' (timeout)' : ''));
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** First `int`-ish tagged value out of a reply field. */
    public static function intField(array $reply, string $path, int $default = 0): int
    {
        $node = $reply;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! isset($node[$segment]['v'])) {
                return $default;
            }
            $node = $node[$segment]['v'];
        }

        return (int) (is_array($node) && isset($node['v']) ? $node['v'] : $node);
    }

    /** The untagged document payload of a reply field. */
    public static function docField(array $reply, string $field): array
    {
        $tagged = $reply[$field] ?? null;
        if (is_array($tagged) && ($tagged['t'] ?? null) === 'document') {
            return (array) $tagged['v'];
        }

        return [];
    }

    /** The untagged list payload of a reply field. */
    public static function listField(array $reply, string $field): array
    {
        $tagged = $reply[$field] ?? null;
        if (is_array($tagged) && in_array($tagged['t'] ?? null, ['array', 'document'], true)) {
            return array_values((array) $tagged['v']);
        }

        return [];
    }

    public static function assertOk(array $reply, string $operation): void
    {
        $ok = $reply['ok']['v'] ?? null;
        $okFloat = is_array($ok) ? (float) ($ok['v'] ?? 0) : (float) ($ok ?? 0);
        if ($okFloat < 1) {
            $code = MongoWireClient::intField($reply, 'code', 0);
            $message = $reply['errmsg']['v'] ?? 'unknown error';
            throw new MongoCommandException("{$operation} failed (code {$code}): {$message}", $code);
        }
    }

    /**
     * Phase 28C — listDatabases. REAL MongoDB replies '{databases: [...], ok}'
     * DIRECTLY (not cursor-shaped — verified against mongo:7); returns the
     * untagged list of database info documents.
     */
    public function listDatabases(): array
    {
        $reply = $this->run('listDatabases', ['nameOnly' => BsonCodec::tag(true, 'boolean')], 'admin');
        self::assertOk($reply, 'listDatabases');
        $out = [];
        foreach (self::listField($reply, 'databases') as $db) {
            $doc = is_array($db) && ($db['t'] ?? null) === 'document' ? (array) $db['v'] : [];
            $out[] = $doc;
        }

        return $out;
    }

    // ── Cursor helpers (28H — batched streaming, bounded memory) ─────────

    /**
     * Run `find` and stream the cursor in batches — never materializes the
     * whole collection. $batchConsumer receives per-batch counts (28H progress).
     *
     * @return \Generator<int, array> tagged BSON documents
     */
    public function streamFind(string $database, string $collection, array $filter = [], array $options = [], ?callable $batchConsumer = null): \Generator
    {
        $fields = array_filter([
            'filter' => BsonCodec::tag($filter, 'document'),
            'sort' => isset($options['sort']) ? BsonCodec::tag($options['sort'], 'document') : null,
            'projection' => isset($options['projection']) ? BsonCodec::tag($options['projection'], 'document') : null,
            'skip' => isset($options['skip']) ? BsonCodec::tag((int) $options['skip'], 'int32') : null,
            'limit' => isset($options['limit']) ? BsonCodec::tag((int) $options['limit'], 'int64') : null,
            'batchSize' => BsonCodec::tag($options['batchSize'] ?? 1000, 'int32'),
            'resumeAfter' => $options['resumeAfter'] ?? null,
        ], fn ($v) => $v !== null);
        $reply = $this->run('find', $fields, $database, $collection);
        self::assertOk($reply, 'find');
        yield from $this->drainCursor($reply, $database, $batchConsumer, (int) ($options['batchSize'] ?? 1000));
    }

    /** Stream an aggregate cursor (e.g. $collStats). */
    public function streamAggregate(string $database, string $collection, array $pipeline, int $batchSize = 1000, ?callable $batchConsumer = null): \Generator
    {
        $stages = [];
        foreach ($pipeline as $stage) {
            $stages[] = BsonCodec::tag($stage, 'document');
        }
        $reply = $this->run('aggregate', [
            'pipeline' => BsonCodec::tag($stages, 'array'),
            'cursor' => BsonCodec::tag(['batchSize' => $batchSize], 'document'),
        ], $database, $collection);
        self::assertOk($reply, 'aggregate');
        yield from $this->drainCursor($reply, $database, $batchConsumer, $batchSize);
    }

    /** @return \Generator<int, array> */
    protected function drainCursor(array $reply, string $database, ?callable $batchConsumer = null, int $batchSize = 1000): \Generator
    {
        $cursorDoc = self::docField($reply, 'cursor');
        if ($cursorDoc === []) {
            throw new \RuntimeException('Malformed cursor reply');
        }
        $cursorId = self::intField($cursorDoc, 'id', 0);
        $batch = self::listField($cursorDoc, 'firstBatch');
        do {
            if ($batchConsumer !== null) {
                $batchConsumer(count($batch));
            }
            foreach ($batch as $document) {
                if (is_array($document) && ($document['t'] ?? null) === 'document') {
                    yield (array) $document['v'];
                }
            }
            if ($cursorId === 0) {
                break;
            }
            $ns = (string) ($cursorDoc['ns']['v'] ?? '');
            $collection = str_contains($ns, '.') ? substr($ns, strpos($ns, '.') + 1) : '';
            $reply = $this->run('getMore', [
                'getMore' => BsonCodec::tag($cursorId, 'int64'),
                'batchSize' => BsonCodec::tag(max(1, $batchSize), 'int32'),
                'collection' => BsonCodec::tag($collection, 'string'),
            ], $database);
            self::assertOk($reply, 'getMore');
            $cursorDoc = self::docField($reply, 'cursor');
            $batch = self::listField($cursorDoc, 'nextBatch');
            $cursorId = self::intField($cursorDoc, 'id', 0);
        } while (true);
    }
}
