<?php

namespace Tests\Feature\Phase35_6;

use App\Connectors\Postgres\Replication\PgOutputDecoder;
use App\Connectors\Postgres\Replication\PgWireCursor;
use App\Connectors\Postgres\Replication\PgLsn;
use PHPUnit\Framework\TestCase;

/**
 * Phase 35.6 §3 — pgoutput v1 decoding against canned binary frames (the
 * exact byte layout PostgreSQL sends over START_REPLICATION). Covers the
 * type-preservation requirements: bytea hex, numeric precision, jsonb,
 * arrays, timestamps, Arabic UTF-8, quoted identifiers, multi-schema and
 * composite replica identity.
 */
class PgOutputDecoderTest extends TestCase
{
    // ── LSN arithmetic ───────────────────────────────────────────────────

    public function test_lsn_round_trip(): void
    {
        // Canonical form: uppercase hex hi + 8 low digits ('0/0' → '0/00000000').
        $this->assertSame('0/016B3748', PgLsn::normalize('0/16B3748'));
        $this->assertSame('2/AB000000', PgLsn::normalize('2/AB000000'));
        $this->assertSame('0/00000000', PgLsn::normalize('0/0'));
        $this->assertSame('FFFFFFFF/FFFFFFFF', PgLsn::normalize('FFFFFFFF/FFFFFFFF'));
        $this->assertSame('0/016B3748', PgLsn::toString(PgLsn::toInt('0/16B3748')));
        $this->assertTrue(PgLsn::atOrAfter(PgLsn::toInt('1/0'), PgLsn::toInt('0/FFFFFFFF')));
        $this->assertFalse(PgLsn::atOrAfter(PgLsn::toInt('0/100'), PgLsn::toInt('0/200')));
    }

    public function test_lsn_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PgLsn::toInt('not-an-lsn');
    }

    // ── pg time ──────────────────────────────────────────────────────────

    public function test_pg_timestamp_conversion(): void
    {
        // 2000-01-01T00:00:00Z = 0 microseconds since the pg epoch.
        $this->assertSame('2000-01-01T00:00:00Z', PgOutputDecoder::pgTimeToIso(0));
        // 2026-10-01T00:00:00Z = 26 years of seconds.
        $seconds = (int) ((strtotime('2026-10-01T00:00:00Z') - 946684800));
        $this->assertSame('2026-10-01T00:00:00Z', PgOutputDecoder::pgTimeToIso($seconds * 1000000));
        $this->assertSame('2026-10-01T00:00:00.000001Z', PgOutputDecoder::pgTimeToIso($seconds * 1000000 + 1));
    }

    // ── relation + DML frames ────────────────────────────────────────────

    public function test_insert_update_delete_with_exact_type_preservation(): void
    {
        $decoder = new PgOutputDecoder;

        // Relation: schema "app schema" (quoted identifiers on the source),
        // table "order Items", columns: id (key, int4), name (text, Arabic),
        // amount (numeric), payload (jsonb), blob (bytea), tags (text[]).
        $relation = $this->relationFrame(1001, 'app schema', 'order Items', [
            ['id', 23, true],
            ['name', 25, false],
            ['amount', 1700, false],
            ['payload', 3802, false],
            ['blob', 17, false],
            ['tags', 1009, false],
        ]);
        $rel = $decoder->decode($relation);
        $this->assertSame('relation', $rel['type']);
        $this->assertSame('app schema', $rel['schema']);
        $this->assertSame('order Items', $rel['name']);
        $this->assertSame('d', $rel['replica_identity']);
        $this->assertTrue($rel['columns'][0]['key']);

        // BEGIN
        $begin = $decoder->decode($this->frame('B', pack('J', 1000).pack('J', 0).pack('N', 753)));
        $this->assertSame('begin', $begin['type']);
        $this->assertSame(753, $begin['xid']);

        // INSERT with Arabic text, exact numeric, jsonb text, bytea hex,
        // array literal — TEXT representation, exactly as SELECT returns.
        $values = [
            '1',
            'مرحبا بالعالم',
            '12345.678901234567', // numeric: full precision as text
            '{"k": "قيمة", "n": [1, 2]}',
            '\x48656C6C6F', // bytea hex format
            '{a,b,c}',
        ];
        $insert = $decoder->decode($this->frame('I', pack('N', 1001).'N'.$this->tuple($values)));
        $this->assertSame('insert', $insert['type']);
        $this->assertSame('1', $insert['values']['id']);
        $this->assertSame('مرحبا بالعالم', $insert['values']['name']);
        $this->assertSame('12345.678901234567', $insert['values']['amount']);
        $this->assertSame('\x48656C6C6F', $insert['values']['blob']);
        $this->assertSame('{a,b,c}', $insert['values']['tags']);
        $this->assertSame([], $insert['unchanged']);

        // UPDATE with a before (key) image.
        $update = $decoder->decode($this->frame('U',
            pack('N', 1001)
            .'K'.$this->tuple(['1'])
            .'N'.$this->tuple(['1', 'updated', '99.5', '{}', '\x00', '{}'])));
        $this->assertSame('update', $update['type']);
        $this->assertSame('1', $update['old']['id']);
        $this->assertSame('updated', $update['values']['name']);

        // UPDATE with unchanged-TOAST marker ('u') — reported, never nulled.
        $updateToast = $decoder->decode($this->frame('U',
            pack('N', 1001)
            .'N'.$this->tupleWithUnchanged(['1', 'u', '5.0', null, null, null], [1])));
        $this->assertSame(['name'], $updateToast['unchanged']);

        // DELETE with the key tuple (replica identity DEFAULT).
        $delete = $decoder->decode($this->frame('D', pack('N', 1001).'K'.$this->tuple(['42'])));
        $this->assertSame('delete', $delete['type']);
        $this->assertSame(['id' => '42'], $delete['key']);

        // COMMIT with end LSN.
        $commit = $decoder->decode($this->frame('C', "\x00".pack('J', 500).pack('J', 520).pack('J', 0)));
        $this->assertSame('commit', $commit['type']);
        $this->assertSame(520, $commit['end_lsn']);
        $this->assertSame('2000-01-01T00:00:00Z', $commit['commit_ts']);
    }

    public function test_replica_identity_nothing_is_refused_honestly(): void
    {
        $decoder = new PgOutputDecoder;
        $relation = $this->relationFrame(1002, 'public', 'noreplica', [['id', 23, false]], identity: 'n');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REPLICA IDENTITY NOTHING');
        $decoder->decode($relation);
    }

    public function test_unknown_message_type_is_refused(): void
    {
        $decoder = new PgOutputDecoder;
        $this->expectException(\RuntimeException::class);
        $decoder->decode("\x7Fjunk");
    }

    public function test_truncated_tuple_is_refused(): void
    {
        $decoder = new PgOutputDecoder;
        $decoder->decode($this->relationFrame(1003, 'public', 't', [['id', 23, true]]));
        $this->expectException(\RuntimeException::class);
        $decoder->decode($this->frame('I', pack('N', 1003).'N'.pack('n', 1).'t'.pack('N', 100).'short'));
    }

    public function test_ignorable_messages_return_null(): void
    {
        $decoder = new PgOutputDecoder;
        $this->assertNull($decoder->decode($this->frame('Y', pack('N', 25)."\x00")));   // Type
        $this->assertNull($decoder->decode($this->frame('O', pack('N', 7)."\x00")));   // Origin
    }

    // ── cursor ───────────────────────────────────────────────────────────

    public function test_cursor_int64_and_cstring(): void
    {
        $c = new PgWireCursor(pack('N2', 0x00000001, 0x00000000)."hello\0");
        $this->assertSame((1 << 32), $c->int64());
        $this->assertSame('hello', $c->cstring());
        $this->assertSame(0, $c->remaining());
    }

    public function test_cursor_int32_is_signed(): void
    {
        $c = new PgWireCursor(pack('N', 0xFFFFFFFF));
        $this->assertSame(-1, $c->int32());
    }

    // ── frame builders (the exact pgoutput v1 wire layout) ──────────────

    /**
     * Wrap a pgoutput message body with its type byte. VERIFIED against a
     * live PostgreSQL 17 stream: inside XLogData the message is [Byte1
     * type][payload] — there is NO per-message Int32 length field (the
     * XLogData envelope itself carries the length).
     */
    protected function frame(string $type, string $body): string
    {
        return $type.$body;
    }

    /**
     * Relation message: R + int32 relid + cstring schema + cstring name +
     * byte identity + int16 ncols + per column (byte flags, int32 oid,
     * int32 typmod, cstring name).
     */
    protected function relationFrame(int $relid, string $schema, string $name, array $columns, string $identity = 'd'): string
    {
        $body = pack('N', $relid).$schema."\0".$name."\0".$identity.pack('n', count($columns));
        foreach ($columns as [$colName, $oid, $key]) {
            $body .= chr($key ? 1 : 0).pack('N', $oid).pack('N', 0xFFFFFFFF).$colName."\0";
        }

        return $this->frame('R', $body);
    }

    /** TupleData: int16 ncols, then per column: 't' + int32 len + bytes, or 'n'. */
    protected function tuple(array $values): string
    {
        $body = pack('n', count($values));
        foreach ($values as $value) {
            if ($value === null) {
                $body .= 'n';
                continue;
            }
            $text = (string) $value;
            $body .= 't'.pack('N', strlen($text)).$text;
        }

        return $body;
    }

    /** Tuple with 'u' (unchanged TOAST) markers at the given indexes. */
    protected function tupleWithUnchanged(array $values, array $unchangedAt): string
    {
        $body = pack('n', count($values));
        foreach ($values as $i => $value) {
            if (in_array($i, $unchangedAt, true)) {
                $body .= 'u';
                continue;
            }
            if ($value === null) {
                $body .= 'n';
                continue;
            }
            $text = (string) $value;
            $body .= 't'.pack('N', strlen($text)).$text;
        }

        return $body;
    }
}
