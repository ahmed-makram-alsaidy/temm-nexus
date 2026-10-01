<?php

namespace App\Connectors\Postgres\Replication;

use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;

/**
 * Phase 35.6 — pure-PHP PostgreSQL streaming replication client.
 *
 * Speaks the PostgreSQL wire protocol over a raw stream socket (no ext-pgsql,
 * no libpq, no system tooling — same philosophy as the MongoDB wire client):
 * startup with replication=database, md5 / cleartext / SCRAM-SHA-256 auth,
 * the simple query protocol for slot management, and the CopyBoth streaming
 * loop for START_REPLICATION ... LOGICAL pgoutput (XLogData + Keepalive in,
 * Standby Status Update out).
 *
 * Secrets NEVER appear in error messages; hosts pass the network guard.
 */
class PgReplicationClient
{
    public const PROTOCOL_VERSION = 196608; // 3.0

    /** @var resource|null */
    protected $socket = null;
    protected ?\Closure $socketFactory = null;
    /** Server-reported end of WAL, advanced by keepalives and XLogData. */
    protected int $serverWalEnd = 0;
    protected bool $inCopyBoth = false;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected string $sslMode = 'disable',
        protected string $appName = 'temm-nexus-cdc',
        protected float $timeoutSeconds = 15,
    ) {
        ConnectorNetworkGuard::assertSafeHost($this->host, $this->port, (bool) config('connectors.allow_private_networks', false));
    }

    /** Test seam: fn(host, port, ssl, timeout) => socket resource. */
    public function setSocketFactory(\Closure $factory): void
    {
        $this->socketFactory = $factory;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public function serverWalEnd(): int
    {
        return $this->serverWalEnd;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->inCopyBoth = false;
    }

    // ── Connection + authentication ─────────────────────────────────────

    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }
        $ssl = $this->sslMode === 'require' || $this->sslMode === 'verify-ca' || $this->sslMode === 'verify-full';
        try {
            if ($this->socketFactory !== null) {
                $socket = ($this->socketFactory)($this->host, $this->port, $ssl, $this->timeoutSeconds);
            } else {
                $address = ($ssl ? 'ssl://' : 'tcp://').$this->host.':'.$this->port;
                $socket = @stream_socket_client($address, $errno, $errstr, $this->timeoutSeconds);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("PostgreSQL replication connect to {$this->host}:{$this->port} failed: ".$e->getMessage());
        }
        if (! is_resource($socket)) {
            throw new \RuntimeException("PostgreSQL replication connect to {$this->host}:{$this->port} failed (no stream)");
        }
        $this->socket = $socket;
        $this->setTimeout($this->timeoutSeconds);

        // StartupMessage: [len][3.0][key\0value\0...]\0
        $params = [
            'user' => $this->username,
            'database' => $this->database,
            'replication' => 'database',
            'application_name' => $this->appName,
        ];
        $body = pack('N', self::PROTOCOL_VERSION);
        foreach ($params as $key => $value) {
            $body .= $key."\0".$value."\0";
        }
        $body .= "\0";
        $this->writeRaw(pack('N', 4 + strlen($body)).$body);

        $this->authenticate();
        $this->awaitReady();
    }

    protected function authenticate(): void
    {
        while (true) {
            $msg = $this->readMessage();
            if ($msg['type'] === 'R') {
                $c = new PgWireCursor($msg['payload']);
                $mode = $c->int32();
                if ($mode === 0) {
                    return; // AuthenticationOk
                }
                if ($mode === 3) {
                    // CleartextPassword
                    $this->writeMessage('p', $this->password."\0");
                    continue;
                }
                if ($mode === 5) {
                    // MD5Password: 'md5' + md5(md5(password+user) + salt)
                    $salt = substr($msg['payload'], 4, 4);
                    $inner = md5($this->password.$this->username, true);
                    $this->writeMessage('p', 'md5'.md5($inner.$salt)."\0");
                    continue;
                }
                if ($mode === 10) {
                    // SCRAM consumes its own final AuthenticationOk — the
                    // handshake is complete when it returns (35.6 fix).
                    $this->scramAuth($msg['payload']);

                    return;
                }
                throw new \RuntimeException("unsupported PostgreSQL auth method {$mode} (supported: md5, cleartext, scram-sha-256)");
            }
            if ($msg['type'] === 'E') {
                throw new \RuntimeException('PostgreSQL auth failed: '.(self::errorFields($msg['payload'])['M'] ?? 'unknown'));
            }
        }
    }

    /** RFC 5802 SCRAM-SHA-256 (PostgreSQL SASL, mode 10/11/12). */
    protected function scramAuth(string $authBody): void
    {
        $c = new PgWireCursor($authBody);
        $c->int32(); // 10
        $mechanisms = [];
        while ($c->remaining() > 0) {
            $m = $c->cstring();
            if ($m !== '') {
                $mechanisms[] = $m;
            }
        }
        if (! in_array('SCRAM-SHA-256', $mechanisms, true)) {
            throw new \RuntimeException('server offers no supported SASL mechanism (need SCRAM-SHA-256)');
        }

        $clientNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $clientFirstBare = 'n=,r='.$clientNonce;
        $clientFirst = 'n,,'.$clientFirstBare;
        $init = 'SCRAM-SHA-256'."\0".pack('N', strlen($clientFirst)).$clientFirst;
        $this->writeMessage('p', $init);

        // SASLContinue: server-first-message
        $msg = $this->readMessage();
        if ($msg['type'] === 'E') {
            throw new \RuntimeException('SCRAM failed: '.(self::errorFields($msg['payload'])['M'] ?? 'unknown'));
        }
        $c = new PgWireCursor($msg['payload']);
        if ($c->int32() !== 11) {
            throw new \RuntimeException('expected SASLContinue, got '.$msg['type']);
        }
        $serverFirst = $c->cstringLen($c->remaining());
        $parts = [];
        foreach (explode(',', $serverFirst) as $part) {
            if ($part !== '' && isset($part[1]) && $part[1] === '=') {
                $parts[$part[0]] = substr($part, 2);
            }
        }
        $serverNonce = $parts['r'] ?? '';
        $salt = base64_decode((string) ($parts['s'] ?? ''), true);
        $iterations = (int) ($parts['i'] ?? 0);
        if ($serverNonce === '' || $salt === false || $iterations < 1 || ! str_starts_with($serverNonce, $clientNonce)) {
            throw new \RuntimeException('SCRAM server-first-message failed validation');
        }

        $saltedPassword = hash_pbkdf2('sha256', $this->password, $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
        $storedKey = hash('sha256', $clientKey, true);
        $clientFinalBare = 'c=biws,r='.$serverNonce;
        $authMessage = $clientFirstBare.','.$serverFirst.','.$clientFinalBare;
        $clientSignature = hash_hmac('sha256', $authMessage, $storedKey, true);
        $proof = $clientKey ^ $clientSignature;
        // SASLResponse payload is the client-final-message bytes directly.
        $this->writeMessage('p', $clientFinalBare.',p='.base64_encode($proof));

        // SASLContinue? No: SASLFinal (R mode 12) — verify server signature.
        $msg = $this->readMessage();
        if ($msg['type'] === 'E') {
            throw new \RuntimeException('SCRAM failed: '.(self::errorFields($msg['payload'])['M'] ?? 'unknown'));
        }
        $c = new PgWireCursor($msg['payload']);
        if ($c->int32() !== 12) {
            throw new \RuntimeException('expected SASLFinal');
        }
        $serverFinal = $c->cstringLen($c->remaining());
        $serverKey = hash_hmac('sha256', 'Server Key', $saltedPassword, true);
        $expected = base64_encode(hash_hmac('sha256', $authMessage, $serverKey, true));
        if (! preg_match('/^v=(.+)$/', $serverFinal, $m) || ! hash_equals($expected, $m[1])) {
            throw new \RuntimeException('SCRAM server signature mismatch — aborting (possible MITM)');
        }

        // Final AuthenticationOk arrives as a normal R message.
        $msg = $this->readMessage();
        if ($msg['type'] !== 'R') {
            throw new \RuntimeException('expected AuthenticationOk after SCRAM final, got '.$msg['type']);
        }
        if (unpack('N', substr($msg['payload'], 0, 4))[1] !== 0) {
            throw new \RuntimeException('auth did not complete after SCRAM');
        }
    }

    protected function awaitReady(): void
    {
        while (true) {
            $msg = $this->readMessage();
            if ($msg['type'] === 'Z') {
                return; // ReadyForQuery
            }
            if ($msg['type'] === 'E') {
                throw new \RuntimeException('PostgreSQL startup failed: '.(self::errorFields($msg['payload'])['M'] ?? 'unknown'));
            }
            // S (ParameterStatus), K (BackendKeyData), N (Notice): expected, ignored.
        }
    }

    // ── Simple query protocol (slot management, position probes) ────────

    /** Run one SQL statement, return result rows. */
    public function query(string $sql): array
    {
        $this->assertConnected();
        if ($this->inCopyBoth) {
            throw new \RuntimeException('cannot run SQL while streaming replication');
        }
        $this->writeMessage('Q', $sql."\0");
        $rows = [];
        $columns = [];
        while (true) {
            $msg = $this->readMessage();
            switch ($msg['type']) {
                case 'T': // RowDescription
                    $c = new PgWireCursor($msg['payload']);
                    $columns = [];
                    $ncols = $c->int16();
                    for ($i = 0; $i < $ncols; $i++) {
                        $columns[] = $c->cstring();
                        $c->int32(); $c->int16(); $c->int32(); $c->int16(); $c->int32(); $c->int16();
                    }
                    break;
                case 'D': // DataRow
                    $c = new PgWireCursor($msg['payload']);
                    $ncols = $c->int16();
                    $row = [];
                    foreach ($columns as $index => $name) {
                        $len = $c->int32();
                        $row[$name] = $len === -1 ? null : $c->cstringLen($len);
                    }
                    $rows[] = $row;
                    break;
                case 'C': // CommandComplete
                    break;
                case 'E':
                    throw new \RuntimeException('PostgreSQL query failed: '.(self::errorFields($msg['payload'])['M'] ?? 'unknown'));
                case 'Z':
                    return $rows;
            }
        }
    }

    public function identifySystem(): array
    {
        $rows = $this->query('IDENTIFY_SYSTEM');
        $row = $rows[0] ?? [];
        if (isset($row['xlogpos'])) {
            $this->serverWalEnd = PgLsn::toInt($row['xlogpos']);
        }

        return [
            'systemid' => $row['systemid'] ?? null,
            'timeline' => (int) ($row['timeline'] ?? 0),
            'xlogpos' => $row['xlogpos'] ?? null,
            'dbname' => $row['dbname'] ?? null,
        ];
    }

    // ── Replication slot lifecycle (35.6 §4) ────────────────────────────

    /**
     * CREATE_REPLICATION_SLOT — returns the consistent point (the snapshot
     * boundary LSN every initial capture starts from).
     *
     * @return array{slot_name: string, consistent_point: string, output_plugin: string}
     */
    public function createSlot(string $name, string $plugin = 'pgoutput'): array
    {
        $rows = $this->query("CREATE_REPLICATION_SLOT ".self::quoteIdent($name)." LOGICAL {$plugin}");
        $row = $rows[0] ?? [];
        if (($row['slot_name'] ?? '') !== $name) {
            throw new \RuntimeException('CREATE_REPLICATION_SLOT returned no slot row');
        }

        return [
            'slot_name' => $name,
            'consistent_point' => PgLsn::normalize((string) $row['consistent_point']),
            'output_plugin' => (string) $row['output_plugin'],
        ];
    }

    public function dropSlot(string $name): void
    {
        $this->query('DROP_REPLICATION_SLOT '.self::quoteIdent($name));
    }

    /** @return array<string, mixed>|null row from pg_replication_slots */
    public function slotInfo(string $name): ?array
    {
        $rows = $this->query(
            "SELECT slot_name, plugin, slot_type, database, active, restart_lsn, confirmed_flush_lsn, "
            ."wal_status, safe_wal_size, active_pid FROM pg_replication_slots WHERE slot_name = ".self::quoteLiteral($name)
        );

        return $rows[0] ?? null;
    }

    // ── Streaming replication (CopyBoth) ────────────────────────────────

    /**
     * START_REPLICATION SLOT … LOGICAL <lsn> — after this the connection is
     * in CopyBoth mode; read with readCopyMessage(), acknowledge with
     * sendFeedback().
     *
     * @param  array<string, string>  $options  e.g. ['proto_version' => '1', 'publication_names' => '"pub"']
     */
    public function startReplication(string $slot, string $lsn, array $options): void
    {
        $this->assertConnected();
        $opts = '';
        foreach ($options as $key => $value) {
            $opts .= ($opts === '' ? '' : ', ').$key.' '.$value;
        }
        $this->writeMessage('Q', 'START_REPLICATION SLOT '.self::quoteIdent($slot).' LOGICAL '.PgLsn::normalize($lsn).($opts !== '' ? " ({$opts})" : '')."\0");
        $msg = $this->readMessage();
        if ($msg['type'] === 'E') {
            throw new \RuntimeException('START_REPLICATION failed: '.(self::errorFields($msg['payload'])['M'] ?? 'unknown'));
        }
        if ($msg['type'] !== 'W') {
            throw new \RuntimeException('expected CopyBothResponse, got '.$msg['type']);
        }
        $this->inCopyBoth = true;
    }

    /**
     * Read one message in streaming mode (blocks up to $timeout seconds).
     *
     * @return array{type: string, ...} 'xlog' (start/walEnd/ts/data), 'keepalive' (walEnd/reply), 'notice'
     */
    public function readCopyMessage(float $timeout): ?array
    {
        $this->assertConnected();
        $this->setTimeout($timeout);
        $type = fread($this->socket, 1);
        if ($type === false || $type === '') {
            $meta = stream_get_meta_data($this->socket);
            if (! empty($meta['timed_out'])) {
                return null; // quiet read — caller decides (caught-up / stale)
            }
            throw new \RuntimeException('replication stream closed by server');
        }
        $header = $this->readExact(4);
        $len = unpack('N', $header)[1] - 4; // payload after the length word
        if ($len < 0 || $len > 1024 * 1024 * 1024) {
            throw new \RuntimeException('absurd replication message length '.$len);
        }
        $payload = $len > 0 ? $this->readExact($len) : '';

        if ($type === 'd') { // CopyData → streaming replication sub-messages
            $sub = $payload[0] ?? '';
            $c = new PgWireCursor($payload);
            $c->byte(); // subtype
            if ($sub === 'w') { // XLogData: int64 start, int64 walEnd, int64 ts, bytea data
                $start = $c->int64();
                $walEnd = $c->int64();
                $c->int64(); // sendTime
                $this->serverWalEnd = max($this->serverWalEnd, $walEnd);

                return ['type' => 'xlog', 'start' => $start, 'wal_end' => $walEnd, 'data' => $c->cstringLen($c->remaining())];
            }
            if ($sub === 'k') { // Primary keepalive: int64 walEnd, int64 ts, byte replyRequested
                $walEnd = $c->int64();
                $c->int64(); // sendTime
                $reply = ord($c->byte()) === 1;
                $this->serverWalEnd = max($this->serverWalEnd, $walEnd);

                return ['type' => 'keepalive', 'wal_end' => $walEnd, 'reply_requested' => $reply];
            }

            throw new \RuntimeException("unexpected replication CopyData subtype '{$sub}'");
        }
        if ($type === 'E') {
            throw new \RuntimeException('replication stream error: '.(self::errorFields($payload)['M'] ?? 'unknown'));
        }
        if ($type === 'c' || $type === 'n') { // CopyDone / Notice in copy mode
            return ['type' => 'copy_done'];
        }

        throw new \RuntimeException("unexpected replication message type '{$type}'");
    }

    /**
     * Standby Status Update — acknowledge WAL up to $lsn (applied + flush).
     * NEVER send a position for changes that were not applied: the caller
     * only acknowledges LSNs of fully-applied transactions.
     */
    public function sendFeedback(string $lsn, bool $replyRequested = false): void
    {
        $this->assertConnected();
        $lsnInt = PgLsn::toInt($lsn);
        $words = [($lsnInt >> 32) & 0xFFFFFFFF, $lsnInt & 0xFFFFFFFF];
        // Standby Status Update: walEnd + flush + apply (THREE int64s),
        // then the client timestamp and the reply-requested flag.
        $body = 'r'.pack('N2', ...$words).pack('N2', ...$words).pack('N2', ...$words)
            .self::pgTimestampNow().chr($replyRequested ? 1 : 0);
        // CopyData message ('d') carrying the Standby Status Update.
        $this->writeRaw(chr(0x64).pack('N', 4 + strlen($body)).$body);
    }

    // ── Wire helpers ────────────────────────────────────────────────────

    protected function assertConnected(): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('PostgreSQL replication client is not connected');
        }
    }

    protected function readMessage(): array
    {
        $type = $this->readExact(1);
        $len = unpack('N', $this->readExact(4))[1] - 4;
        if ($len < 0 || $len > 1024 * 1024 * 1024) {
            throw new \RuntimeException('absurd message length '.$len);
        }

        return ['type' => $type, 'payload' => $len > 0 ? $this->readExact($len) : ''];
    }

    protected function writeMessage(string $type, string $payload): void
    {
        $this->writeRaw($type.pack('N', 4 + strlen($payload)).$payload);
    }

    protected function writeRaw(string $bytes): void
    {
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = fwrite($this->socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('replication socket write failed');
            }
            $written += $n;
        }
    }

    protected function readExact(int $bytes): string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = fread($this->socket, $bytes - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new \RuntimeException('replication socket read failed'.(! empty($meta['timed_out']) ? ' (timeout)' : ''));
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    protected function setTimeout(float $seconds): void
    {
        $sec = (int) floor($seconds);
        $usec = (int) (($seconds - $sec) * 1000000);
        stream_set_timeout($this->socket, $sec, $usec);
    }

    /** Microseconds since 2000-01-01 as the packed int64 (big-endian). */
    public static function pgTimestampNow(): string
    {
        $micros = (int) ((microtime(true) - 946684800) * 1000000);
        $hi = ($micros >> 32) & 0xFFFFFFFF;
        $lo = $micros & 0xFFFFFFFF;

        return pack('N2', $hi, $lo);
    }

    /** Parse an ErrorResponse payload into severity/code/message. */
    public static function errorFields(string $payload): array
    {
        $fields = [];
        foreach (explode("\0", $payload) as $field) {
            if (strlen($field) >= 2) {
                $fields[$field[0]] = substr($field, 1);
            }
        }

        return $fields;
    }

    /** Double-quote identifier (replication SQL uses quoted slot names). */
    public static function quoteIdent(string $name): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("replication identifier '{$name}' must be [a-z0-9_]+");
        }

        return '"'.$name.'"';
    }

    /** Single-quote literal (escape ' → ''). */
    public static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
