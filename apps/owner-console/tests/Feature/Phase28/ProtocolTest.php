<?php

namespace Tests\Feature\Phase28;

use App\Connectors\Mongodb\Protocol\BsonCodec;
use App\Connectors\Mongodb\Protocol\MongoWireClient;
use Illuminate\Foundation\Testing\TestCase;
use Tests\Feature\Phase28\Concerns\RunsFakeMongoServer;

/**
 * Phase 28 protocol layer — BSON codec (28K), URI handling (28B), the
 * read-only command allowlist and the wire client against the scripted
 * fake server.
 */
class ProtocolTest extends TestCase
{
    use RunsFakeMongoServer;

    protected function tearDown(): void
    {
        $this->stopFakeMongo();
        parent::tearDown();
    }

    // ── 28K BSON codec ──────────────────────────────────────────────────

    public function test_bson_roundtrip_preserves_every_type(): void
    {
        $doc = [
            's' => BsonCodec::tag('hello world', 'string'),
            'b' => BsonCodec::tag(true, 'boolean'),
            'i32' => BsonCodec::tag(42, 'int32'),
            'i64' => BsonCodec::tag(9223372036854775000, 'int64'),
            'd' => BsonCodec::tag(3.14, 'double'),
            'date' => BsonCodec::tag(1735689600123, 'date'),
            'oid' => BsonCodec::tag('64b1f0c0a1b2c3d4e5f60718', 'objectId'),
            'bin' => BsonCodec::tag("\x00\x01\x02\xFF", 'binary', ['subtype' => 0]),
            'n' => BsonCodec::tag(null, 'null'),
            'min' => BsonCodec::tag(null, 'minKey'),
            'max' => BsonCodec::tag(null, 'maxKey'),
            'doc' => BsonCodec::tag(['nested' => BsonCodec::tag('value', 'string')], 'document'),
            'arr' => BsonCodec::tag([BsonCodec::tag(1, 'int32'), BsonCodec::tag(2, 'int32')], 'array'),
            'dec' => BsonCodec::tag('123.456', 'decimal128'),
        ];
        $bytes = BsonCodec::encodeDocument($doc);
        $decoded = BsonCodec::decodeDocument($bytes);

        $this->assertSame('hello world', $decoded['s']['v']);
        $this->assertTrue($decoded['b']['v']);
        $this->assertSame(42, $decoded['i32']['v']);
        $this->assertSame(9223372036854775000, $decoded['i64']['v']);
        $this->assertSame(3.14, $decoded['d']['v']);
        $this->assertSame(1735689600123, $decoded['date']['v']);
        $this->assertSame('64b1f0c0a1b2c3d4e5f60718', $decoded['oid']['v']);
        $this->assertSame("\x00\x01\x02\xFF", $decoded['bin']['v'], 'binary survives untouched (28K.3)');
        $this->assertNull($decoded['n']['v']);
        $this->assertSame('document', $decoded['doc']['t']);
        $this->assertSame('array', $decoded['arr']['t']);
        $this->assertSame('123.456', $decoded['dec']['v']);
    }

    /** 28K.1 — Decimal128 must be exact: financial values, big integers, high precision. */
    public function test_decimal128_is_exact_never_float(): void
    {
        $cases = [
            '0', '1', '-1', '0.000001', '19.99', '1234.5678',
            '9999999999999999999999999999999999',   // max 34-digit coefficient
            '-9999999999999999999999999999999999',
            '0.000000000000000000000000000000001',  // 1e-33
            '123456789012345678901234567890.5',
            '-0.0000000000000000000000000000001',
        ];
        foreach ($cases as $value) {
            $bytes = BsonCodec::encodeDecimal128($value);
            $this->assertSame(
                $value,
                BsonCodec::decodeDecimal128($bytes),
                "Decimal128 roundtrip failed for {$value} — value drifted (28K.1 FAIL)"
            );
        }
        // Known BSON vectors (wire bytes, little-endian):
        // 1 → coefficient 1, biased exponent 6176 (0x1820 at bits 126..113).
        $this->assertSame('1', BsonCodec::decodeDecimal128(hex2bin('01000000000000000000000000004030')));
        // Specials: combination bits 126..122 = 11110 → Infinity, 11111 → NaN.
        $this->assertSame('Infinity', BsonCodec::decodeDecimal128(hex2bin('00000000000000000000000000000078')));
        $this->assertSame('NaN', BsonCodec::decodeDecimal128(hex2bin('0000000000000000000000000000007c')));
    }

    public function test_deep_documents_decode_within_bounds(): void
    {
        // 28L.2/28T deep-nesting defense happens at inference; the codec
        // itself must survive decoding a deep document without stack death.
        $node = BsonCodec::tag('bottom', 'string');
        for ($i = 0; $i < 60; $i++) {
            $node = BsonCodec::tag(['l'.$i => $node], 'document');
        }
        $decoded = BsonCodec::decodeDocument(BsonCodec::encodeDocument(['root' => $node]));
        $this->assertSame('document', $decoded['root']['t']);
    }

    // ── 28B URI handling ────────────────────────────────────────────────

    public function test_uri_parsing_standard_srv_tls_and_redaction(): void
    {
        $parsed = MongoWireClient::parseUri('mongodb://shopuser:s3cret@host1:27017,host2:27018/shop?tls=true&authSource=admin');
        $this->assertFalse($parsed['srv']);
        $this->assertSame(['host1:27017', 'host2:27018'], $parsed['hosts']);
        $this->assertSame('shop', $parsed['database']);
        $this->assertSame('shopuser', $parsed['username']);
        $this->assertSame('s3cret', $parsed['password']);
        $this->assertTrue($parsed['tls']);
        $this->assertSame('admin', $parsed['authSource']);

        // Redaction never leaks the password (28B.1/28T).
        $redacted = MongoWireClient::redactUri('mongodb://shopuser:s3cret@host1:27017/shop');
        $this->assertStringNotContainsString('s3cret', $redacted);
        $this->assertStringContainsString('shopuser:****@host1:27017/shop', $redacted);

        $srv = MongoWireClient::parseUri('mongodb+srv://cluster0.abc123.mongodb.net/shop');
        $this->assertTrue($srv['srv']);
        $this->assertTrue($srv['tls'], 'SRV implies TLS');
        $this->assertSame(['cluster0.abc123.mongodb.net'], $srv['hosts']);
    }

    // ── Read-only allowlist (28 source immutability) ────────────────────

    public function test_write_commands_are_structurally_impossible(): void
    {
        $client = MongoWireClient::fromUri('mongodb://127.0.0.1:1/none');
        foreach (['insert', 'update', 'delete', 'drop', 'dropDatabase', 'findAndModify', 'createIndexes', 'collMod'] as $command) {
            try {
                $client->run($command, [], 'shop');
                $this->fail("write command '{$command}' was sent");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('read-only allowlist', $e->getMessage());
            }
        }
    }

    // ── Wire client vs the scripted server ──────────────────────────────

    public function test_wire_client_handshake_find_and_cursor_streaming(): void
    {
        config(['connectors.allow_private_networks' => true]);
        $uri = $this->startFakeMongo();
        $client = MongoWireClient::fromUri($uri);

        $hello = $client->connect();
        $this->assertSame('7.0.0-fake', (string) ($hello['version']['v'] ?? ''));

        $documents = [];
        $batches = 0;
        $gen = $client->streamFind('shop', 'users', [], ['sort' => ['_id' => 1], 'batchSize' => 3], function (int $n) use (&$batches) {
            $batches++;
        });
        foreach ($gen as $document) {
            $documents[] = $document;
        }
        $this->assertCount(8, $documents, 'all documents streamed through getMore');
        $this->assertGreaterThanOrEqual(3, $batches, 'streaming used multiple batches (28H)');
        $first = $documents[0]; // streamFind yields untagged field maps
        $this->assertSame('user1@shop.test', (string) (BsonCodec::untag($first['email'] ?? null)));
        $this->assertSame('القاهرة', (string) (BsonCodec::untag($first['address']['v']['city']['v'] ?? null)), 'Arabic text survives the wire (28V.7)');

        $client->close();
    }

    public function test_scram_authentication_against_scripted_server(): void
    {
        config(['connectors.allow_private_networks' => true]);
        $uri = $this->startFakeMongo('shopuser:shopsecret');
        // URI without credentials must fail with the auth error…
        $client = MongoWireClient::fromUri($uri);
        try {
            $client->run('listDatabases', [], 'admin');
            $this->fail('unauthenticated command accepted');
        } catch (\App\Connectors\Mongodb\Protocol\MongoCommandException $e) {
            $this->assertSame(13, $e->serverCode());
        }
        $client->close();

        // …and credentials authenticate via the full SCRAM-SHA-256 exchange.
        $client = MongoWireClient::fromUri($uri, 10000, 'primary', ['username' => 'shopuser', 'password' => 'shopsecret']);
        $names = array_map(fn ($d) => (string) (BsonCodec::untag($d['name'] ?? null)), $client->listDatabases());
        $this->assertContains('shop', $names);
        $client->close();
    }

    public function test_wrong_password_is_refused_without_leaking_material(): void
    {
        config(['connectors.allow_private_networks' => true]);
        $uri = $this->startFakeMongo('shopuser:shopsecret');
        $client = MongoWireClient::fromUri($uri, 10000, 'primary', ['username' => 'shopuser', 'password' => 'wrong']);
        try {
            $client->run('listDatabases', [], 'admin');
            $this->fail('wrong password accepted');
        } catch (\App\Connectors\Mongodb\Protocol\MongoCommandException $e) {
            $this->assertSame(18, $e->serverCode());
        }
        $client->close();
    }
}
