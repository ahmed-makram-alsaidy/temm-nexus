<?php

namespace App\Connectors\Mysql\Replication;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Migration\Cdc\CdcEvent;
use App\Services\ControlPlane\Migration\Cdc\CdcPositionSource;
use App\Services\ControlPlane\Migration\Cdc\CdcStreamTelemetry;
use App\Services\ControlPlane\Migration\Cdc\ChangeCaptureConnector;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\DTO\GTIDLogDTO;
use MySQLReplication\Event\DTO\RowsDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

/**
 * Phase 35.6 §6 — REAL MySQL 8 binlog capture (row-based).
 *
 * The wire/binlog protocol, row-image decoding (DECIMAL, BIGINT UNSIGNED,
 * JSON, ENUM, BLOB, utf8mb4) and the replica handshake are handled by the
 * pure-PHP krowinski/php-mysql-replication package INSIDE this connector
 * (the generic core never sees it); this class adapts its events into the
 * generic CdcEvent vocabulary and owns the DURABLE position semantics:
 *
 *  - the checkpoint is the binlog FILE+OFFSET at the end of the last
 *    COMMITTED AND APPLIED transaction (kind 'binlog_position'), with the
 *    executed GTID set recorded as supporting evidence;
 *  - FILE+POSITION streaming (COM_BINLOG_DUMP) is used because the
 *    package's COM_BINLOG_DUMP_GTID packet receives only heartbeats from
 *    MySQL 8.0 (verified live, 35.6) — file/pos resume is equally
 *    crash-safe here: replay after the checkpoint is idempotent;
 *  - row events are grouped per transaction (GTID/BEGIN → rows → Xid) and
 *    delivered ON COMMIT — no partial transactions are ever applied (§12).
 */
class MysqlBinlogCapture implements ChangeCaptureConnector, CdcPositionSource
{
    public function __construct(
        protected MigrationSource $source,
        /** host/port/username/password/database/server_id/heartbeat_seconds/idle_seconds/connect_retries */
        protected array $options = [],
    ) {
    }

    public function checkpointKind(): string
    {
        return 'binlog_position';
    }

    /**
     * Capture up to $maxEvents (whole transactions) from the checkpoint.
     * The position payload advances ONLY with committed transactions whose
     * events were handed to $onBatch synchronously.
     */
    public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array
    {
        $maxEvents = max(1, $maxEvents);
        $idleSeconds = (float) ($this->options['idle_seconds'] ?? 2.0);
        $heartbeat = (float) ($this->options['heartbeat_seconds'] ?? 1.0);

        $collector = new BinlogCollector;
        $factory = $this->newFactory($checkpoint, $collector);

        $applied = MysqlGtidSet::parse((string) ($checkpoint['gtid_set'] ?? ''));
        $lastGtid = $checkpoint['last_gtid'] ?? null;
        // The SNAPSHOT BOUNDARY: where this connection started streaming
        // (SHOW MASTER STATUS at connect, or the checkpoint). Recorded even
        // when the cycle sees zero events, so a later cycle resumes from
        // here instead of silently re-starting "from now".
        $startCurrent = $factory->getBinLogCurrent();
        $hasCheckpoint = (string) ($checkpoint['file'] ?? '') !== '';
        $file = $hasCheckpoint ? (string) $checkpoint['file'] : (string) $startCurrent->getBinFileName();
        $pos = $hasCheckpoint ? (int) ($checkpoint['pos'] ?? 0) : (int) $startCurrent->getBinLogPosition();
        $lastEventTs = $checkpoint['last_event_at'] ?? null;
        $delivered = 0;
        $idleSince = null;

        try {
            while ($delivered < $maxEvents) {
                $factory->consume(); // blocks until an event/heartbeat (heartbeat keeps this bounded)

                $event = $collector->next();
                if ($event === null) {
                    // heartbeat / ignored event — idle bookkeeping
                    $idleSince ??= microtime(true);
                    if (microtime(true) - $idleSince >= $idleSeconds) {
                        break; // drained — cycle complete
                    }
                    continue;
                }
                $idleSince = null;

                if ($event instanceof GTIDLogDTO) {
                    $collector->beginTransaction((string) $event->gtid);
                    continue;
                }
                if ($event instanceof XidDTO) {
                    // COMMIT: deliver the transaction as one atomic batch.
                    $batch = $collector->commitTransaction();
                    if ($batch !== []) {
                        $onBatch($batch); // synchronous apply — delivery == applied
                        $delivered += count($batch);
                    }
                    $gtid = $collector->lastGtid();
                    if ($gtid !== null && str_contains($gtid, ':')) {
                        [$uuid, $trx] = explode(':', $gtid, 2);
                        $applied = $applied->add($uuid, (int) $trx);
                        $lastGtid = $gtid;
                    }
                    // The durable position is the END of the committed
                    // transaction. EventInfo->pos IS the header's log_pos:
                    // the end of this event = the next event's start, so it
                    // is already boundary-aligned (35.6 live finding: adding
                    // the event size again landed mid-event and MySQL
                    // refused the resume with 'bogus data in log event').
                    $info = $event->getEventInfo();
                    $file = (string) ($info->binLogCurrent->getBinFileName() ?: $file);
                    $pos = (int) $info->pos;
                    continue;
                }
                if ($event instanceof RowsDTO) {
                    $events = $this->normalize($event);
                    $lastEventTs = $event->getEventInfo()->getDateTime();
                    if ($events !== []) {
                        $collector->buffer($events);
                    }
                    continue;
                }
                if ($event instanceof \MySQLReplication\Event\DTO\QueryDTO) {
                    // ROLLBACK / non-row statements end the open transaction
                    // without applying anything.
                    if (str_contains(strtolower((string) $event->query), 'rollback')) {
                        $collector->rollbackTransaction();
                    }
                    continue;
                }
            }
        } finally {
            unset($factory); // close the binlog connection
        }

        return [
            'gtid_set' => $applied->toString(),
            'last_gtid' => $lastGtid,
            'file' => $file,
            'pos' => $pos,
            'last_event_at' => $lastEventTs,
            'events' => $delivered,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    // ── CdcPositionSource (35.6 §16/§19) ────────────────────────────────

    /** Current source position: the executed GTID set + binlog coordinates. */
    public function currentSourcePosition(): array
    {
        $pdo = $this->sourcePdo();
        try {
            $gtid = (string) $pdo->query('SELECT @@GLOBAL.gtid_executed AS g')->fetch()['g'];

            // MySQL 8.4 removed SHOW MASTER STATUS (renamed to SHOW BINARY
            // LOG STATUS); 8.0 and older only know the old name. Found live
            // by the Phase K CDC smoke against mysql:8 — try the new name
            // first and fall back, so both server generations work.
            try {
                $status = $pdo->query('SHOW BINARY LOG STATUS')->fetch();
            } catch (\PDOException) {
                $status = $pdo->query('SHOW MASTER STATUS')->fetch();
            }

            $file = is_array($status) ? (string) ($status['File'] ?? '') : '';
            $pos = is_array($status) ? (int) ($status['Position'] ?? 0) : 0;

            return [
                'position' => ['gtid_set' => $gtid, 'file' => $file, 'pos' => $pos],
                'label' => "binlog {$file}:{$pos}".($gtid !== '' ? ' (GTID '.$gtid.')' : ''),
                'captured_at' => now()->toIso8601String(),
            ];
        } finally {
            $pdo = null;
        }
    }

    /** Has the applied position reached the given source position? */
    public function hasAppliedThrough(?array $checkpoint, array $sourcePosition): bool
    {
        if ($checkpoint === null) {
            return false;
        }
        $appliedFile = (string) ($checkpoint['file'] ?? '');
        $sourceFile = (string) ($sourcePosition['file'] ?? '');
        if ($appliedFile === '' || $sourceFile === '') {
            return false;
        }
        if ($appliedFile === $sourceFile) {
            return (int) ($checkpoint['pos'] ?? 0) >= (int) ($sourcePosition['pos'] ?? 0);
        }

        return self::fileIndex($appliedFile) >= self::fileIndex($sourceFile);
    }

    /** Numeric index of a binlog file name (binlog.000003 → 3). */
    protected static function fileIndex(string $file): int
    {
        $dot = strrpos($file, '.');

        return $dot === false ? 0 : (int) substr($file, $dot + 1);
    }

    public function lagSnapshot(?array $checkpoint): array
    {
        if ($checkpoint === null || (string) ($checkpoint['file'] ?? '') === '') {
            return [
                'status' => CdcStreamTelemetry::IDLE,
                'source_position_label' => null,
                'captured_position_label' => null,
                'applied_position_label' => null,
                'lag_seconds' => null,
                'lag_events' => null,
                'last_event_at' => null,
                'mechanism' => 'binlog (ROW, file+position)',
                'detail' => ['note' => 'no checkpoint yet'],
            ];
        }
        // The source's CURRENT coordinates — one cheap probe per cycle.
        try {
            $source = $this->currentSourcePosition();
            $sourceLabel = $source['label'];
        } catch (\Throwable) {
            $source = null;
            $sourceLabel = null;
        }
        $appliedFile = (string) ($checkpoint['file'] ?? '');
        $appliedPos = (int) ($checkpoint['pos'] ?? 0);
        $sourceFile = (string) ($source['position']['file'] ?? '');
        $sourcePos = (int) ($source['position']['pos'] ?? 0);
        $caughtUp = $sourceFile === '' || ($appliedFile === $sourceFile
            ? $appliedPos >= $sourcePos
            : self::fileIndex($appliedFile) >= self::fileIndex($sourceFile));
        $bytesBehind = ($sourceFile !== '' && $appliedFile === $sourceFile) ? max(0, $sourcePos - $appliedPos) : null;

        $lagSeconds = null;
        if (is_string($checkpoint['last_event_at'] ?? null) && $checkpoint['last_event_at'] !== '') {
            try {
                $lagSeconds = max(0.0, now()->diffInSeconds(new \DateTimeImmutable($checkpoint['last_event_at'], new \DateTimeZone('UTC'))));
            } catch (\Exception) {
                $lagSeconds = null;
            }
        }

        return [
            'status' => $caughtUp ? CdcStreamTelemetry::CAUGHT_UP : CdcStreamTelemetry::ACTIVE,
            'source_position_label' => $sourceLabel,
            'captured_position_label' => "binlog {$appliedFile}:{$appliedPos}",
            'applied_position_label' => "binlog {$appliedFile}:{$appliedPos}",
            'lag_seconds' => $lagSeconds,
            'lag_events' => null, // binlog offsets expose no backlog counts — honest null
            'last_event_at' => $checkpoint['last_event_at'] ?? null,
            'mechanism' => 'binlog (ROW, file+position)',
            'detail' => [
                'file' => $appliedFile,
                'pos' => $appliedPos,
                'bytes_behind' => $bytesBehind,
                'gtid_set' => (string) ($checkpoint['gtid_set'] ?? ''),
            ],
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** Map one Rows event into generic CdcEvents (whole statement batch). */
    protected function normalize(RowsDTO $event): array
    {
        $table = (string) $event->tableMap->table;
        $schema = (string) $event->tableMap->database;
        $position = (string) ($event->getEventInfo()->pos ?? '');
        $out = [];

        if ($event instanceof WriteRowsDTO) {
            foreach ($event->values as $row) {
                $out[] = new CdcEvent($table, CdcEvent::INSERT, $this->rowImage($row), $position, $schema);
            }
        } elseif ($event instanceof UpdateRowsDTO) {
            foreach ($event->values as $change) {
                $before = is_array($change['before'] ?? null) ? $change['before'] : null;
                $after = is_array($change['after'] ?? null) ? $change['after'] : null;
                if ($after === null) {
                    throw new \RuntimeException("UPDATE row image for {$schema}.{$table} carried no after-image");
                }
                $out[] = new CdcEvent($table, CdcEvent::UPDATE, $this->rowImage($after), $position, $schema,
                    is_array($before) ? $this->rowImage($before) : null);
            }
        } elseif ($event instanceof DeleteRowsDTO) {
            foreach ($event->values as $row) {
                // With binlog_row_image=FULL the delete carries the whole
                // old image; NULL columns can never match in a WHERE, so
                // the delete image keeps the non-null columns (the PK is
                // never null) — the matched row is identical (35.6).
                $image = array_filter($this->rowImage($row), fn ($v) => $v !== null);
                $out[] = new CdcEvent($table, CdcEvent::DELETE, $image, $position, $schema);
            }
        }

        return $out;
    }

    /** Row images arrive keyed by column name (table map metadata on). */
    protected function rowImage(array $row): array
    {
        $image = [];
        foreach ($row as $column => $value) {
            $image[(string) $column] = $value;
        }

        return $image;
    }

    protected function newFactory(?array $checkpoint, BinlogCollector $collector): MySQLReplicationFactory
    {
        $builder = (new ConfigBuilder)
            ->withUser($this->secret('username', (string) ($this->options['username'] ?? '')))
            ->withPassword($this->secret('password', (string) ($this->options['password'] ?? '')))
            ->withHost((string) ($this->options['host'] ?? ''))
            ->withPort((int) ($this->options['port'] ?? 3306))
            ->withSlaveId((int) ($this->options['server_id'] ?? 0))
            ->withHeartbeatPeriod((float) ($this->options['heartbeat_seconds'] ?? 1.0));

        // FILE+POSITION dump (COM_BINLOG_DUMP): the package's
        // COM_BINLOG_DUMP_GTID packet gets only heartbeats from MySQL 8.0
        // (verified live, 35.6) — file/pos streaming works and resumes
        // crash-safely. The executed GTID set is still recorded in
        // checkpoints as supporting evidence.
        $file = (string) ($checkpoint['file'] ?? '');
        $pos = (int) ($checkpoint['pos'] ?? 0);
        if ($file !== '' && $pos > 0) {
            $builder = $builder->withBinLogFileName($file)->withBinLogPosition($pos);
        }
        // else: first capture starts at SHOW MASTER STATUS (snapshot
        // boundary = now) — the snapshot itself is the caller's job.

        $factory = new MySQLReplicationFactory($builder->build());
        $factory->registerSubscriber($collector);

        return $factory;
    }

    protected function sourcePdo(): \PDO
    {
        $host = (string) ($this->options['host'] ?? '');
        \App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard::assertSafeHost(
            $host,
            (int) ($this->options['port'] ?? 3306),
            (bool) config('connectors.allow_private_networks', false),
        );
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) ($this->options['port'] ?? 3306));
        $pdo = new \PDO($dsn, $this->secret('username', (string) ($this->options['username'] ?? '')), $this->secret('password', (string) ($this->options['password'] ?? '')), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ]);

        return $pdo;
    }

    protected function secret(string $ref, string $fallback): string
    {
        $refs = (array) ($this->source->secret_refs ?? []);
        $name = $refs[$ref] ?? null;
        if (! is_string($name) || $name === '') {
            return $fallback;
        }
        $values = \App\Services\ControlPlane\SecretService::valuesFor($this->source->project, [$name]);

        return (string) ($values[$name] ?? $fallback);
    }
}

/**
 * Collects binlog DTOs dispatched by the factory into a pull queue and
 * tracks transaction boundaries (GTID → rows → Xid).
 */
class BinlogCollector extends EventSubscribers
{
    /** @var list<EventDTO> */
    protected array $queue = [];
    protected ?string $currentGtid = null;
    protected ?string $lastGtid = null;
    /** @var list<CdcEvent> */
    protected array $transaction = [];

    public function next(): ?EventDTO
    {
        return array_shift($this->queue);
    }

    /** @param list<CdcEvent> $events */
    public function buffer(array $events): void
    {
        array_push($this->transaction, ...$events);
    }

    public function beginTransaction(string $gtid): void
    {
        $this->currentGtid = $gtid;
        $this->transaction = [];
    }

    /** @return list<CdcEvent> the committed transaction's events (empty when none) */
    public function commitTransaction(): array
    {
        $batch = $this->transaction;
        $this->transaction = [];
        $this->lastGtid = $this->currentGtid;
        $this->currentGtid = null;

        return $batch;
    }

    public function rollbackTransaction(): void
    {
        $this->transaction = [];
        $this->currentGtid = null;
    }

    public function lastGtid(): ?string
    {
        return $this->lastGtid;
    }

    public function onGTID(GTIDLogDTO $event): void
    {
        $this->queue[] = $event;
    }

    public function onWrite(WriteRowsDTO $event): void
    {
        $this->queue[] = $event;
    }

    public function onUpdate(UpdateRowsDTO $event): void
    {
        $this->queue[] = $event;
    }

    public function onDelete(DeleteRowsDTO $event): void
    {
        $this->queue[] = $event;
    }

    public function onXID(XidDTO $event): void
    {
        $this->queue[] = $event;
    }

    public function onQuery(\MySQLReplication\Event\DTO\QueryDTO $event): void
    {
        $this->queue[] = $event;
    }
}
