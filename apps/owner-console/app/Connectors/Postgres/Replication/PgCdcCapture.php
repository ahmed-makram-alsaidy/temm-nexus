<?php

namespace App\Connectors\Postgres\Replication;

use App\Connectors\Postgres\Protocol\PdoPgExecutor;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Migration\Cdc\CdcEvent;
use App\Services\ControlPlane\Migration\Cdc\CdcPositionSource;
use App\Services\ControlPlane\Migration\Cdc\CdcStreamTelemetry;
use App\Services\ControlPlane\Migration\Cdc\ChangeCaptureConnector;

/**
 * Phase 35.6 — REAL PostgreSQL log-based CDC capture (§3).
 *
 * Streams the WAL through a logical-decoding slot (pgoutput v1) over the
 * pure-PHP replication client, normalizes INSERT/UPDATE/DELETE (deletes
 * included — replica identity provides the key tuple) into generic CdcEvents
 * and commits transactions as WHOLE batches (§12: events are delivered
 * per COMMIT, never partially). The acknowledged LSN only ever advances to
 * the end_lsn of transactions already handed to the applier — a crash
 * replays from the last signed checkpoint with idempotent convergence.
 *
 * Source mutations this class may perform (publication + slot creation)
 * are gated behind explicit setup allowance; prerequisites are reported
 * honestly otherwise (§3: the platform never reconfigures a source).
 */
class PgCdcCapture implements ChangeCaptureConnector, CdcPositionSource
{
    protected PgOutputDecoder $decoder;
    protected ?\Closure $clientFactory = null;
    protected ?PdoPgExecutor $toastExecutor = null;

    public function __construct(
        protected MigrationSource $source,
        /** slot/publication/app_name/tables/allow_setup/connect_retries */
        protected array $options = [],
    ) {
        $this->decoder = new PgOutputDecoder;
    }

    public function checkpointKind(): string
    {
        return 'lsn';
    }

    /** Test seam: override the replication client construction. */
    public function setClientFactory(\Closure $factory): void
    {
        $this->clientFactory = $factory;
        $this->decoder = new PgOutputDecoder;
    }

    public function setDecoder(PgOutputDecoder $decoder): void
    {
        $this->decoder = $decoder;
    }

    /**
     * Capture up to $maxEvents (whole transactions) since $checkpoint.
     * The returned position covers ONLY events already delivered (and thus
     * synchronously applied) — never ahead of the applier (§14).
     */
    public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array
    {
        $slot = (string) ($this->options['slot'] ?? '');
        $publication = (string) ($this->options['publication'] ?? '');
        if ($slot === '' || $publication === '') {
            throw new \InvalidArgumentException('PgCdcCapture requires scoped slot + publication names');
        }

        $connectRetries = max(1, (int) ($this->options['connect_retries'] ?? 5));
        $idleTimeout = (float) ($this->options['idle_read_timeout'] ?? 1.0);
        $maxEvents = max(1, $maxEvents);

        $attempt = 0;
        while (true) {
            try {
                return $this->captureOnce($checkpoint, $onBatch, $maxEvents, $slot, $publication, $idleTimeout);
            } catch (\RuntimeException $e) {
                $attempt++;
                if ($attempt >= $connectRetries) {
                    throw $e; // checkpoint not advanced — operator/loop retries later
                }
                sleep(min(5, $attempt));
            }
        }
    }

    /** One connection attempt of the capture loop. */
    protected function captureOnce(?array $checkpoint, callable $onBatch, int $maxEvents, string $slot, string $publication, float $idleTimeout): array
    {
        $client = $this->newClient();
        $client->connect();
        try {
            $allowSetup = (bool) ($this->options['allow_setup'] ?? false);
            $manager = new PgReplicationSlotManager($client, (string) ($this->source->connection['database'] ?? ''));
            $ensured = $manager->ensure($slot, $allowSetup);
            $this->ensurePublication($client, $publication, $allowSetup);

            // First run starts at the slot's consistent point (snapshot
            // boundary). Resumes start at the LAST APPLIED lsn. A REUSED
            // slot (created by an earlier attempt of this run) resumes from
            // its own confirmed_flush_lsn — NEVER from the current WAL end
            // (that would silently skip changes).
            $slotInfo = $client->slotInfo($slot);
            $startLsn = (string) ($checkpoint['lsn']
                ?? $ensured['consistent_point']
                ?? $slotInfo['confirmed_flush_lsn']
                ?? $slotInfo['restart_lsn']
                ?? $client->identifySystem()['xlogpos']
                ?? '0/0');
            $sourceWalEndAtStart = $client->identifySystem()['xlogpos'] ?? null;
            $client->startReplication($slot, $startLsn, [
                'proto_version' => "'1'",
                'publication_names' => "'".addcslashes($publication, "'")."'",
            ]);

            $appliedLsn = PgLsn::toInt($startLsn);
            $lastSourceWalEnd = PgLsn::toInt($sourceWalEndAtStart ?? $startLsn);
            $lastCommitTs = $checkpoint['last_commit_ts'] ?? null;
            $delivered = 0;
            $txOpen = false;
            $txXid = null;
            $batch = []; // events of the CURRENT transaction

            while (true) {
                $msg = $client->readCopyMessage($idleTimeout);
                if ($msg === null) {
                    if (! $txOpen) {
                        break; // drained + no open transaction → cycle complete
                    }
                    continue; // open transaction: keep waiting for its commit
                }
                if ($msg['type'] === 'keepalive') {
                    if ($msg['reply_requested']) {
                        $client->sendFeedback(PgLsn::toString($appliedLsn));
                    }
                    continue;
                }
                if ($msg['type'] !== 'xlog') {
                    continue;
                }
                $decoded = $this->decoder->decode($msg['data']);
                if ($decoded === null) {
                    continue;
                }
                switch ($decoded['type']) {
                    case 'begin':
                        $txOpen = true;
                        $txXid = $decoded['xid'];
                        $batch = [];
                        break;

                    case 'relation':
                        break; // decoder caches relations

                    case 'insert':
                    case 'update':
                    case 'delete':
                        $batch = array_merge($batch, $this->normalize($decoded, $msg['start']));
                        break;

                    case 'truncate':
                        $relations = array_map(fn ($relid) => $this->decoder->relationFor($relid) ?? ['name' => (string) $relid], $decoded['relids']);
                        throw new \RuntimeException('TRUNCATE on replicated table(s) '.implode(', ', array_map(fn ($r) => ($r['schema'] ?? '').'.'.($r['name'] ?? ''), $relations)).' cannot be applied incrementally — re-run the table migration or re-snapshot.');

                    case 'commit':
                        $txOpen = false;
                        if ($batch !== []) {
                            $onBatch($batch); // synchronous apply — delivery == applied
                            $delivered += count($batch);
                        }
                        // Advance ONLY to a committed end_lsn (§14): never
                        // past unapplied changes, never inside a transaction.
                        $appliedLsn = $decoded['end_lsn'];
                        $lastCommitTs = $decoded['commit_ts'];
                        $client->sendFeedback(PgLsn::toString($appliedLsn));
                        $batch = [];
                        if ($delivered >= $maxEvents) {
                            break 2;
                        }
                        break;
                }
            }

            return [
                'lsn' => PgLsn::toString($appliedLsn),
                'source_wal_end' => PgLsn::toString(max($lastSourceWalEnd, $client->serverWalEnd())),
                'slot' => $slot,
                'publication' => $publication,
                'last_commit_ts' => $lastCommitTs,
                'events' => $delivered,
                'captured_at' => now()->toIso8601String(),
            ];
        } finally {
            $client->close();
        }
    }

    /** Map one decoded change to generic events (TOAST resolution included). */
    protected function normalize(array $decoded, int $walStart): array
    {
        $relation = $this->decoder->relationFor($decoded['relid']);
        if ($relation === null) {
            throw new \RuntimeException("change for unknown relation oid {$decoded['relid']}");
        }
        $position = PgLsn::toString($walStart);
        $table = $relation['name'];
        $schema = $relation['schema'];

        if ($decoded['type'] === 'delete') {
            // The key tuple carries EVERY relation column (non-key columns
            // as NULL). Deleting with `column = NULL` predicates can never
            // match, so the delete image keeps ONLY the replica-identity
            // columns (the PK under DEFAULT identity); under FULL identity
            // it keeps the non-null old-image columns (35.6 live finding).
            $keyFlags = array_filter($relation['columns'], fn ($c) => $c['key']);
            if ($keyFlags !== []) {
                $keyNames = array_column($keyFlags, 'name');
                $key = array_intersect_key($decoded['key'], array_flip($keyNames));
            } else {
                $key = array_filter($decoded['key'], fn ($v) => $v !== null);
            }
            if ($key === []) {
                throw new \RuntimeException("DELETE for {$schema}.{$table} carried no usable key tuple — set REPLICA IDENTITY DEFAULT/FULL");
            }

            return [new CdcEvent($table, CdcEvent::DELETE, $key, $position, $schema, null, null, null)];
        }

        $values = $decoded['values'];
        if ($decoded['unchanged'] !== []) {
            $values = $this->resolveUnchangedToast($relation, $values, $decoded['unchanged']);
        }
        $op = $decoded['type'] === 'insert' ? CdcEvent::INSERT : CdcEvent::UPDATE;
        $before = $decoded['old'] ?? null;

        return [new CdcEvent($table, $op, $values, $position, $schema, $before, null, null)];
    }

    /**
     * Resolve unchanged-TOAST columns from the source by key (§3 bytea and
     * large-value support). The values are NEVER silently nulled.
     */
    protected function resolveUnchangedToast(array $relation, array $values, array $unchanged): array
    {
        $keyCols = array_values(array_filter($relation['columns'], fn ($c) => $c['key']));
        if ($keyCols === []) {
            throw new \RuntimeException("unchanged-TOAST columns on {$relation['schema']}.{$relation['name']} but no replica identity — set REPLICA IDENTITY DEFAULT/FULL");
        }
        $executor = $this->sourceExecutor();
        $where = [];
        $select = implode(', ', array_map(fn ($c) => $this->ident($c['name']), $unchanged));
        foreach ($keyCols as $col) {
            $name = $col['name'];
            if (! array_key_exists($name, $values)) {
                throw new \RuntimeException("replica identity column '{$name}' missing from UPDATE image for {$relation['schema']}.{$relation['name']}");
            }
            $where[] = $this->ident($name).' = '.PgReplicationClient::quoteLiteral((string) $values[$name]);
        }
        $sql = 'SELECT '.$select.' FROM '.$this->ident($relation['schema']).'.'.$this->ident($relation['name']).' WHERE '.implode(' AND ', $where).' LIMIT 1';
        $rows = $executor->rows($sql);
        if ($rows === []) {
            throw new \RuntimeException("could not resolve unchanged-TOAST columns for {$relation['schema']}.{$relation['name']} — source row vanished mid-apply; retry the cycle");
        }
        foreach ($unchanged as $name) {
            $values[$name] = $rows[0][$name];
        }

        return $values;
    }

    // ── CdcPositionSource (35.6 §16/§19) ────────────────────────────────

    public function currentSourcePosition(): array
    {
        $client = $this->newClient();
        $client->connect();
        try {
            $rows = $client->query('SELECT pg_current_wal_lsn()::text AS lsn');
            $lsn = (string) ($rows[0]['lsn'] ?? '0/0');

            return [
                'position' => ['lsn' => PgLsn::normalize($lsn)],
                'label' => 'WAL LSN '.PgLsn::normalize($lsn),
                'captured_at' => now()->toIso8601String(),
            ];
        } finally {
            $client->close();
        }
    }

    public function hasAppliedThrough(?array $checkpoint, array $sourcePosition): bool
    {
        if ($checkpoint === null || ! isset($checkpoint['lsn']) || ! isset($sourcePosition['lsn'])) {
            return false;
        }

        return PgLsn::atOrAfter(PgLsn::toInt((string) $checkpoint['lsn']), PgLsn::toInt((string) $sourcePosition['lsn']));
    }

    public function lagSnapshot(?array $checkpoint): array
    {
        if ($checkpoint === null || ! isset($checkpoint['lsn'])) {
            return [
                'status' => CdcStreamTelemetry::IDLE,
                'source_position_label' => null,
                'captured_position_label' => null,
                'applied_position_label' => null,
                'lag_seconds' => null,
                'lag_events' => null,
                'last_event_at' => null,
                'mechanism' => 'logical_replication (pgoutput)',
                'detail' => ['note' => 'no checkpoint yet'],
            ];
        }

        $captured = PgLsn::toInt((string) $checkpoint['lsn']);
        $sourceWalEnd = PgLsn::toInt((string) ($checkpoint['source_wal_end'] ?? $checkpoint['lsn']));
        $byteLag = max(0, $sourceWalEnd - $captured);
        $lastTs = $checkpoint['last_commit_ts'] ?? null;
        $lagSeconds = null;
        if (is_string($lastTs) && $lastTs !== '') {
            try {
                $lagSeconds = max(0.0, now()->diffInSeconds(new \DateTimeImmutable($lastTs)));
            } catch (\Exception) {
                $lagSeconds = null;
            }
        }

        return [
            'status' => $byteLag === 0 ? CdcStreamTelemetry::CAUGHT_UP : CdcStreamTelemetry::ACTIVE,
            'source_position_label' => 'WAL LSN '.PgLsn::toString($sourceWalEnd),
            'captured_position_label' => 'WAL LSN '.PgLsn::toString($captured),
            'applied_position_label' => 'WAL LSN '.PgLsn::toString($captured),
            'lag_seconds' => $lagSeconds,
            'lag_events' => null, // PG exposes byte distance, not event counts — honest null
            'last_event_at' => $lastTs,
            'mechanism' => 'logical_replication (pgoutput)',
            'detail' => [
                'byte_lag' => $byteLag,
                'slot' => $checkpoint['slot'] ?? null,
                'publication' => $checkpoint['publication'] ?? null,
            ],
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────

    protected function newClient(): PgReplicationClient
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)();
        }
        $config = $this->source->connection ?? [];

        return new PgReplicationClient(
            (string) ($config['host'] ?? ''),
            (int) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? ''),
            $this->secret('username', (string) ($config['username'] ?? '')),
            $this->secret('password', ''),
            (string) ($config['ssl_mode'] ?? 'disable'),
            (string) ($this->options['app_name'] ?? config('cdc.capture.replication_app_name', 'temm-nexus-cdc')),
        );
    }

    protected function ensurePublication(PgReplicationClient $client, string $publication, bool $allowSetup): void
    {
        $exists = $client->query('SELECT 1 FROM pg_publication WHERE pubname = '.PgReplicationClient::quoteLiteral($publication));
        if ($exists !== []) {
            return;
        }
        if (! $allowSetup) {
            throw new \RuntimeException(
                "publication '{$publication}' does not exist and source setup is not allowed. ".
                'Create it manually (CREATE PUBLICATION ... FOR TABLE ...) — the platform never alters a source without explicit allowance.'
            );
        }
        $tables = array_values(array_filter((array) ($this->options['tables'] ?? [])));
        if ($tables === []) {
            $client->query('CREATE PUBLICATION '.PgReplicationClient::quoteIdent($publication).' FOR ALL TABLES');
        } else {
            $list = implode(', ', array_map(function ($qualified) {
                [$schema, $table] = array_pad(explode('.', $qualified, 2), 2, '');

                return $this->ident($schema).'.'.$this->ident($table);
            }, $tables));
            $client->query('CREATE PUBLICATION '.PgReplicationClient::quoteIdent($publication).' FOR TABLE '.$list);
        }
    }

    /** Read-only executor for TOAST resolution (pinned read-only session). */
    protected function sourceExecutor(): PdoPgExecutor
    {
        if ($this->toastExecutor === null) {
            $config = $this->source->connection ?? [];
            $this->toastExecutor = PdoPgExecutor::forSource(
                $config,
                $this->secret('username', (string) ($config['username'] ?? '')),
                $this->secret('password', ''),
                (bool) config('connectors.allow_private_networks', false),
            );
        }

        return $this->toastExecutor;
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

    protected function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
