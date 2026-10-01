<?php

namespace App\Connectors\Postgres\Replication;

/**
 * Phase 35.6 — pgoutput (protocol version 1) logical-decoding decoder.
 *
 * Decodes the logical replication stream format PostgreSQL sends after
 * START_REPLICATION ... LOGICAL pgoutput: Begin/Relation/Insert/Update/
 * Delete/Commit (+ Truncate). Tuple values arrive in TEXT representation —
 * the same literal form SELECT returns — so bytea stays hex '\x…', numeric
 * keeps full precision, jsonb stays JSON text, timestamps stay ISO-ish:
 * exact preservation with no binary re-interpretation.
 *
 * Unchanged-TOAST columns ('u' markers, large values not rewritten by an
 * UPDATE) are reported separately — the capture resolves them against the
 * source (they are NOT silently nulled).
 */
final class PgOutputDecoder
{
    /** Sentinels for unchanged-TOAST columns. */
    public const UNCHANGED = "\0__TEMM_TOAST_UNCHANGED__\0";

    /** @var array<int, array> relation cache: relid → relation metadata */
    protected array $relations = [];

    /** Decode ONE pgoutput message (the payload inside XLogData). */
    public function decode(string $msg): ?array
    {
        if ($msg === '') {
            throw new \RuntimeException('empty pgoutput message');
        }
        $c = new PgWireCursor($msg);
        $type = $c->byte();

        return match ($type) {
            'B' => $this->begin($c),
            'C' => $this->commit($c),
            'R' => $this->relation($c),
            'I' => $this->insert($c),
            'U' => $this->update($c),
            'D' => $this->delete($c),
            'T' => $this->truncate($c),
            'Y', 'O', 'M' => null, // Type / Origin / logical message: ignorable
            default => throw new \RuntimeException("unknown pgoutput message type '".($type === '' ? '?' : $type)."'"),
        };
    }

    public function relationFor(int $relid): ?array
    {
        return $this->relations[$relid] ?? null;
    }

    protected function begin(PgWireCursor $c): array
    {
        $finalLsn = $c->int64();
        $commitTs = $c->int64();

        return [
            'type' => 'begin',
            'final_lsn' => $finalLsn,
            'commit_ts' => self::pgTimeToIso($commitTs),
            'xid' => $c->int32(),
        ];
    }

    protected function commit(PgWireCursor $c): array
    {
        $c->byte(); // flags (unused in v1)
        $commitLsn = $c->int64();
        $endLsn = $c->int64();
        $commitTs = $c->int64();

        return [
            'type' => 'commit',
            'commit_lsn' => $commitLsn,
            'end_lsn' => $endLsn,
            'commit_ts' => self::pgTimeToIso($commitTs),
        ];
    }

    protected function relation(PgWireCursor $c): array
    {
        $relid = $c->int32();
        $schema = $c->cstring();
        $name = $c->cstring();
        $replicaIdentity = $c->byte();
        $ncols = $c->int16();
        $columns = [];
        for ($i = 0; $i < $ncols; $i++) {
            // Wire order VERIFIED against a live PostgreSQL 17 Relation
            // frame: flags byte, column NAME cstring, int32 type OID,
            // int32 typmod.
            $flags = ord($c->byte());
            $colName = $c->cstring();
            $type = $c->int32();
            $typmod = $c->int32();
            $columns[] = [
                'name' => $colName,
                'type' => $type,
                'typmod' => $typmod,
                'key' => ($flags & 1) !== 0, // part of the replica identity
            ];
        }
        if ($replicaIdentity === 'n') {
            // REPLICA IDENTITY NOTHING: UPDATE/DELETE cannot be identified —
            // an honest prerequisite failure, never a silent gap.
            throw new \RuntimeException("table {$schema}.{$name} has REPLICA IDENTITY NOTHING — set DEFAULT or FULL (logical UPDATE/DELETE need row identity).");
        }
        $this->relations[$relid] = [
            'relid' => $relid, 'schema' => $schema, 'name' => $name,
            'replica_identity' => $replicaIdentity, 'columns' => $columns,
        ];

        return $this->relations[$relid] + ['type' => 'relation'];
    }

    protected function insert(PgWireCursor $c): array
    {
        $relid = $c->int32();
        $marker = $c->byte();
        if ($marker !== 'N') {
            throw new \RuntimeException("malformed pgoutput INSERT tuple marker '{$marker}'");
        }
        ['values' => $values, 'unchanged' => $unchanged] = $this->tuple($c, $relid);

        return ['type' => 'insert', 'relid' => $relid, 'values' => $values, 'unchanged' => $unchanged];
    }

    protected function update(PgWireCursor $c): array
    {
        $relid = $c->int32();
        $old = null;
        $marker = $c->peekByte();
        if ($marker === 'K' || $marker === 'O') {
            $c->byte();
            ['values' => $old] = $this->tuple($c, $relid);
        }
        $newMarker = $c->byte();
        if ($newMarker !== 'N') {
            throw new \RuntimeException("malformed pgoutput UPDATE tuple marker '{$newMarker}'");
        }
        ['values' => $values, 'unchanged' => $unchanged] = $this->tuple($c, $relid);

        return ['type' => 'update', 'relid' => $relid, 'values' => $values, 'unchanged' => $unchanged, 'old' => $old];
    }

    protected function delete(PgWireCursor $c): array
    {
        $relid = $c->int32();
        $marker = $c->byte();
        if ($marker !== 'K' && $marker !== 'O') {
            throw new \RuntimeException("malformed pgoutput DELETE tuple marker '{$marker}'");
        }
        ['values' => $key] = $this->tuple($c, $relid);

        return ['type' => 'delete', 'relid' => $relid, 'key' => $key];
    }

    protected function truncate(PgWireCursor $c): array
    {
        $nrels = $c->int32();
        $c->byte(); // flags (CASCADE / RESTART IDENTITY)
        $rels = [];
        for ($i = 0; $i < $nrels; $i++) {
            $rels[] = $c->int32();
        }

        return ['type' => 'truncate', 'relids' => $rels];
    }

    /** @return array{values: array<string, ?string>, unchanged: list<string>} */
    protected function tuple(PgWireCursor $c, int $relid): array
    {
        $relation = $this->relations[$relid] ?? null;
        if ($relation === null) {
            throw new \RuntimeException("tuple data for unknown relation oid {$relid} (relation message missing)");
        }
        $names = array_column($relation['columns'], 'name');
        $ncols = $c->int16();
        $values = [];
        $unchanged = [];
        for ($i = 0; $i < $ncols; $i++) {
            $kind = $c->byte();
            $name = $names[$i] ?? "col_{$i}";
            if ($kind === 'n') {
                $values[$name] = null;
            } elseif ($kind === 'u') {
                $unchanged[] = $name; // resolved by the capture, never nulled
            } elseif ($kind === 't') {
                $values[$name] = $c->cstringLen($c->int32());
            } elseif ($kind === 'b') {
                throw new \RuntimeException('binary tuple format requires pgoutput protocol >= 3 — client requests v1');
            } else {
                throw new \RuntimeException("unknown tuple column kind '{$kind}'");
            }
        }

        return ['values' => $values, 'unchanged' => $unchanged];
    }

    /** PostgreSQL time (microseconds since 2000-01-01) → ISO-8601 UTC. */
    public static function pgTimeToIso(int $micros): string
    {
        $epoch = 946684800; // 2000-01-01T00:00:00Z
        $seconds = intdiv($micros, 1000000);
        $frac = $micros % 1000000;
        $dt = \DateTimeImmutable::createFromFormat('U', (string) ($epoch + $seconds), new \DateTimeZone('UTC'));
        if ($dt === false) {
            throw new \RuntimeException('invalid pg timestamp '.$micros);
        }

        return $frac === 0
            ? $dt->format('Y-m-d\TH:i:s\Z')
            : $dt->format('Y-m-d\TH:i:s').'.'.sprintf('%06d', $frac).'Z';
    }
}
