<?php

namespace App\Connectors\Postgres\Replication;

/**
 * Phase 35.6 §4 — PostgreSQL replication slot lifecycle.
 *
 * SLOTS ARE SOURCE STATE: an abandoned slot retains WAL indefinitely and
 * can fill a disk. Rules enforced here:
 *
 *  - naming is project+run scoped (no silent reuse across projects/runs —
 *    the caller passes the scoped name; an existing slot whose plugin or
 *    database differs is REFUSED, never silently reused);
 *  - creation is a source mutation — only performed when the operator
 *    explicitly allowed source setup (disposable sources) ;
 *  - inspection reports WAL retention health (wal_status / safe_wal_size);
 *  - cleanup drops the slot (normal completed runs leave NO abandoned slots).
 */
class PgReplicationSlotManager
{
    public function __construct(
        protected PgReplicationClient $client,
        protected string $database,
    ) {
    }

    /**
     * Ensure the slot exists for THIS run. Reuses an existing slot only
     * when plugin + database match (a resume); anything else refuses.
     *
     * @return array{created: bool, consistent_point: ?string, slot: string}
     */
    public function ensure(string $name, bool $allowSetup, string $plugin = 'pgoutput'): array
    {
        $info = $this->client->slotInfo($name);
        if ($info !== null) {
            if ((string) $info['plugin'] !== $plugin) {
                throw new \RuntimeException("replication slot '{$name}' exists with plugin '{$info['plugin']}' — refusing to reuse (expected {$plugin})");
            }
            if ((string) $info['database'] !== $this->database) {
                throw new \RuntimeException("replication slot '{$name}' belongs to database '{$info['database']}' — refusing to reuse (expected {$this->database})");
            }

            return ['created' => false, 'consistent_point' => null, 'slot' => $name];
        }
        if (! $allowSetup) {
            throw new \RuntimeException(
                "replication slot '{$name}' does not exist and source setup is not allowed. ".
                'Create it manually (SELECT pg_create_logical_replication_slot('.
                PgReplicationClient::quoteLiteral($name).", 'pgoutput')) or run on a disposable source with setup allowed."
            );
        }
        $created = $this->client->createSlot($name, $plugin);

        return ['created' => true, 'consistent_point' => $created['consistent_point'], 'slot' => $name];
    }

    /** WAL retention health of one slot. */
    public function retentionHealth(string $name): array
    {
        $info = $this->client->slotInfo($name);
        if ($info === null) {
            return ['status' => 'missing', 'warnings' => ["slot '{$name}' does not exist"]];
        }
        $warnings = [];
        $walStatus = (string) ($info['wal_status'] ?? 'reserved');
        $safeWalSize = $info['safe_wal_size'] === null ? null : (int) $info['safe_wal_size'];
        if ($walStatus === 'extended' || $walStatus === 'unreserved') {
            $warnings[] = "slot '{$name}' wal_status={$walStatus} — WAL the slot pins exceeds max_slot_wal_keep_size; check disk headroom";
        }
        if ($walStatus === 'lost') {
            $warnings[] = "slot '{$name}' wal_status=lost — required WAL was removed; the slot can no longer resume. Drop and re-snapshot";
        }
        if ($safeWalSize !== null && $safeWalSize >= 0 && $safeWalSize < 512 * 1024 * 1024) {
            $warnings[] = "slot '{$name}' safe_wal_size=".number_format($safeWalSize / 1024 / 1024, 1).'MiB — nearing WAL retention limits';
        }

        return [
            'status' => $walStatus,
            'active' => (bool) $info['active'],
            'active_pid' => $info['active_pid'] !== null ? (int) $info['active_pid'] : null,
            'restart_lsn' => $info['restart_lsn'],
            'confirmed_flush_lsn' => $info['confirmed_flush_lsn'],
            'safe_wal_size' => $safeWalSize,
            'warnings' => $warnings,
        ];
    }

    /** Drop the slot if it exists — normal runs must not leak slots. */
    public function drop(string $name): bool
    {
        if ($this->client->slotInfo($name) === null) {
            return false;
        }
        $this->client->dropSlot($name);

        return true;
    }
}
