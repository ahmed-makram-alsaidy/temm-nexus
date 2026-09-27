<?php

namespace App\Connectors\Mongodb\Protocol;

/**
 * Phase 28B — SCRAM-SHA-1 / SCRAM-SHA-256 authentication (RFC 5802) for the
 * pure-PHP wire client. Required for Atlas-style and authenticated
 * self-hosted MongoDB. Password values exist in memory only and are never
 * logged, echoed or persisted by this class (28T).
 */
class ScramAuth
{
    public function __construct(protected MongoWireClient $client)
    {
    }

    public function authenticate(string $username, string $password, string $authSource = 'admin'): void
    {
        // Prefer SCRAM-SHA-256; servers that reject it fall back to SHA-1.
        try {
            $this->mechanism('SCRAM-SHA-256', $username, $password, $authSource, true);

            return;
        } catch (MongoCommandException $e) {
            if (! in_array($e->serverCode(), [59, 66, 72, 313], true)) { // mechanism unavailable etc.
                throw $e;
            }
        }
        $this->mechanism('SCRAM-SHA-1', $username, $password, $authSource, false);
    }

    protected function mechanism(string $mechanism, string $username, string $password, string $authSource, bool $sha256): void
    {
        $nonce = rtrim(base64_encode(random_bytes(18)), '=');
        // MongoDB saslStart payload: n,,n=<user>,r=<nonce> (gs2 header "n,,").
        $bare = 'n='.self::saslPrep($username).',r='.$nonce;
        $clientFirstBare = 'n,,'.$bare;
        $clientFirstMessage = ['saslStart' => 1, 'mechanism' => ScramAuth::str($mechanism), 'payload' => ScramAuth::bin($clientFirstBare), 'options' => ['skipEmptyExchange' => ScramAuth::bool(true)]];

        $start = $this->client->run('saslStart', $clientFirstMessage, $authSource);
        MongoWireClient::assertOk($start, 'saslStart');
        $serverFirst = (string) ($start['payload']['v'] ?? '');
        $first = self::parseAttrs($serverFirst);
        $serverNonce = (string) ($first['r'] ?? '');
        $salt = base64_decode((string) ($first['s'] ?? ''), true);
        $iterations = (int) ($first['i'] ?? 0);
        if (! str_starts_with($serverNonce, $nonce) || $salt === false || $iterations < 1) {
            throw new \RuntimeException('SCRAM server-first message invalid');
        }

        // MongoDB's SCRAM-SHA-1 derives the salted password from
        // MD5(username:mongo:password); SCRAM-SHA-256 uses the SASLprep'd
        // literal password. Both stay in memory only (28T).
        $passwordMaterial = $sha256
            ? self::saslPrep($password)
            : md5($username.':mongo:'.$password);
        $saltedPassword = hash_pbkdf2($sha256 ? 'sha256' : 'sha1', $passwordMaterial, $salt, $iterations, $sha256 ? 32 : 20, true);
        $clientKey = hash_hmac($sha256 ? 'sha256' : 'sha1', 'Client Key', $saltedPassword, true);
        $storedKey = hash($sha256 ? 'sha256' : 'sha1', $clientKey, true);
        $withoutProof = 'c=biws,r='.$serverNonce;
        $authMessage = $bare.','.$serverFirst.','.$withoutProof;
        $clientSignature = hash_hmac($sha256 ? 'sha256' : 'sha1', $authMessage, $storedKey, true);
        $clientProof = $clientKey ^ $clientSignature;

        $final = ['saslContinue' => 1, 'conversationId' => $start['conversationId'] ?? ScramAuth::int32(0), 'payload' => ScramAuth::bin($withoutProof.',p='.base64_encode($clientProof))];
        $continue = $this->client->run('saslContinue', $final, $authSource);
        MongoWireClient::assertOk($continue, 'saslContinue');

        // Verify the server signature when the exchange is done; some
        // topologies (sharded) require one more empty round trip.
        $done = (bool) ($continue['done']['v'] ?? false);
        $serverSecond = (string) ($continue['payload']['v'] ?? '');
        if (! $done) {
            $empty = $this->client->run('saslContinue', ['saslContinue' => 1, 'conversationId' => $continue['conversationId'] ?? ScramAuth::int32(0), 'payload' => ScramAuth::bin('')], $authSource);
            MongoWireClient::assertOk($empty, 'saslContinue');
            $serverSecond = (string) ($empty['payload']['v'] ?? '');
        }
        $second = self::parseAttrs($serverSecond);
        if (isset($second['v'])) {
            $serverKey = hash_hmac($sha256 ? 'sha256' : 'sha1', 'Server Key', $saltedPassword, true);
            $serverSignature = hash_hmac($sha256 ? 'sha256' : 'sha1', $authMessage, $serverKey, true);
            if (! hash_equals($serverSignature, (string) base64_decode((string) $second['v'], true))) {
                throw new \RuntimeException('SCRAM server signature mismatch');
            }
        }
    }

    /** Parse SCRAM attribute lists without URL-decoding (base64 '+' is literal). */
    public static function parseAttrs(string $payload): array
    {
        $attrs = [];
        foreach (explode(',', $payload) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) { continue; }
            $attrs[substr($part, 0, $eq)] = substr($part, $eq + 1);
        }

        return $attrs;
    }

    /** Minimal SASLprep: strip soft hyphens/zero-width; reject control chars. */
    public static function saslPrep(string $value): string
    {
        $clean = str_replace(["\u{00AD}", "\u{200B}", "\u{FEFF}"], '', $value);
        if (preg_match('/[\x00-\x1F\x7F]/', $clean) === 1) {
            throw new \InvalidArgumentException('Username/password contain control characters');
        }

        return $clean;
    }

    // Tagged BSON helpers (client-independent).
    public static function str(string $v): array
    {
        return ['t' => 'string', 'v' => $v];
    }

    public static function bin(string $v): array
    {
        return ['t' => 'binary', 'v' => $v, 'subtype' => 0];
    }

    public static function bool(bool $v): array
    {
        return ['t' => 'boolean', 'v' => $v];
    }

    public static function int32(int $v): array
    {
        return ['t' => 'int32', 'v' => $v];
    }
}
