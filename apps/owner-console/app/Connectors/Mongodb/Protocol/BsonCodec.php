<?php

namespace App\Connectors\Mongodb\Protocol;

/**
 * Phase 28K — pure-PHP BSON codec for the MongoDB wire protocol.
 *
 * The connector runs WITHOUT ext-mongodb (no system-wide PHP extension
 * installation is permitted), so this codec implements the BSON subset the
 * read-only connector operations need. Every BSON type maps to a tagged
 * PHP value that PRESERVES the source type — conversion to PostgreSQL
 * representations happens only in TypeMapper (28K), never here:
 *
 *   double      → ['t' => 'double', 'v' => float]
 *   string      → ['t' => 'string', 'v' => string]
 *   document    → ['t' => 'document', 'v' => array<string, mixed>]  (ordered)
 *   array       → ['t' => 'array', 'v' => list<mixed>]
 *   binary      → ['t' => 'binary', 'v' => bytes, 'subtype' => int]
 *   objectId    → ['t' => 'objectId', 'v' => 24-char lowercase hex]
 *   boolean     → ['t' => 'boolean', 'v' => bool]
 *   datetime    → ['t' => 'date', 'v' => int milliseconds since epoch UTC]
 *   null        → ['t' => 'null', 'v' => null]
 *   regex       → ['t' => 'regex', 'v' => string, 'options' => string]
 *   code        → ['t' => 'code', 'v' => string] / code_w_scope (+'scope')
 *   dbpointer   → ['t' => 'dbPointer', 'v' => ns, 'id' => hex]
 *   timestamp   → ['t' => 'timestamp', 'v' => increment, 'seconds' => int]
 *   int32       → ['t' => 'int32', 'v' => int]
 *   int64       → ['t' => 'int64', 'v' => int]
 *   decimal128  → ['t' => 'decimal128', 'v' => exact decimal string]
 *   minKey/maxKey, undefined → tagged singletons
 *
 * Decimal128 uses the BID encoding: 1 sign bit + 14-bit biased exponent
 * (bias 6176) + 113-bit binary integer coefficient. Values round-trip
 * EXACTLY through BCMath — never through float (28K.1).
 */
final class BsonCodec
{
    public const MAX_DOCUMENT_BYTES = 16777216; // BSON hard limit

    public static function isTagged(mixed $value): bool
    {
        return is_array($value) && isset($value['t']) && is_string($value['t']) && array_key_exists('v', $value);
    }

    /** Tag a raw PHP value with an explicit BSON type. */
    public static function tag(mixed $value, string $type, array $extra = []): array
    {
        return array_merge(['t' => $type, 'v' => $value], $extra);
    }

    /** The raw PHP payload of a tagged value (shallow). */
    public static function untag(mixed $value): mixed
    {
        return self::isTagged($value) ? $value['v'] : $value;
    }

    // ── Encoding (used by the connector's commands and the test server) ──

    public static function encodeDocument(array $doc): string
    {
        $body = '';
        foreach ($doc as $key => $value) {
            $body .= self::encodeElement((string) $key, $value);
        }
        $length = 4 + strlen($body) + 1;
        if ($length > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('BSON document exceeds 16 MiB limit');
        }

        return self::i32($length).$body."\x00";
    }

    private static function encodeElement(string $key, mixed $value): string
    {
        $name = $key."\x00";
        if (! self::isTagged($value)) {
            // Plain PHP values get natural BSON types.
            if (is_int($value)) {
                return "\x10".$name.self::i32($value);
            }
            if (is_bool($value)) {
                return "\x08".$name.($value ? "\x01" : "\x00");
            }
            if (is_float($value)) {
                return "\x01".$name.strrev(pack('E', $value)); // BSON doubles are LE
            }
            if (is_string($value)) {
                return "\x02".$name.self::bsonString($value);
            }
            if (is_null($value)) {
                return "\x0A".$name;
            }
            if (is_array($value)) {
                $isList = array_is_list($value);

                return ($isList ? "\x04" : "\x03").$name.self::encodeDocument($value);
            }
            throw new \InvalidArgumentException('Cannot encode value of type '.gettype($value).' as BSON');
        }

        return match ($value['t']) {
            'double' => "\x01".$name.strrev(pack('E', (float) $value['v'])), // BSON doubles are LE
            'string' => "\x02".$name.self::bsonString((string) $value['v']),
            'document' => "\x03".$name.self::encodeDocument((array) $value['v']),
            'array' => "\x04".$name.self::encodeDocument(self::listToBsonKeys((array) $value['v'])),
            'binary' => "\x05".$name.self::bsonBinary((string) $value['v'], (int) ($value['subtype'] ?? 0)),
            'objectId' => "\x07".$name.pack('H*', (string) $value['v']),
            'boolean' => "\x08".$name.($value['v'] ? "\x01" : "\x00"),
            'date' => "\x09".$name.pack('P', (int) $value['v']),
            'null' => "\x0A".$name,
            'regex' => "\x0B".$name.$value['v']."\x00".($value['options'] ?? '')."\x00",
            'int32' => "\x10".$name.self::i32((int) $value['v']),
            'timestamp' => "\x11".$name.pack('VV', (int) ($value['v'] ?? 0), (int) ($value['seconds'] ?? 0)),
            'int64' => "\x12".$name.pack('P', (int) $value['v']),
            'decimal128' => "\x13".$name.self::encodeDecimal128((string) $value['v']),
            'minKey' => "\xFF".$name,
            'maxKey' => "\x7F".$name,
            'code' => "\x0D".$name.self::bsonString((string) $value['v']),
            default => throw new \InvalidArgumentException("Cannot encode BSON type '{$value['t']}'"),
        };
    }

    private static function listToBsonKeys(array $list): array
    {
        $out = [];
        foreach (array_values($list) as $i => $item) {
            $out[(string) $i] = $item;
        }

        return $out;
    }

    private static function bsonString(string $value): string
    {
        return self::i32(strlen($value) + 1).$value."\x00";
    }

    private static function bsonBinary(string $bytes, int $subtype): string
    {
        if ($subtype === 2) {
            return self::i32(strlen($bytes) + 4)."\x02".self::i32(strlen($bytes)).$bytes;
        }

        return self::i32(strlen($bytes)).chr($subtype & 0xFF).$bytes;
    }

    private static function i32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }

    // ── Decoding ─────────────────────────────────────────────────────────

    /** Decode one BSON document from binary. @param int<0,max> $offset */
    public static function decodeDocument(string $bytes, int $offset = 0, ?int &$nextOffset = null): array
    {
        if (strlen($bytes) - $offset < 5) {
            throw new \RuntimeException('BSON document truncated');
        }
        $length = unpack('V', substr($bytes, $offset, 4))[1];
        if ($length < 5 || $length > self::MAX_DOCUMENT_BYTES || $offset + $length > strlen($bytes)) {
            throw new \RuntimeException('BSON document length invalid');
        }
        $end = $offset + $length - 1;
        $cursor = $offset + 4;
        $doc = [];
        while ($cursor < $end) {
            $type = ord($bytes[$cursor++]);
            $nullAt = strpos($bytes, "\x00", $cursor);
            if ($nullAt === false || $nullAt > $end) {
                throw new \RuntimeException('BSON element name unterminated');
            }
            $key = substr($bytes, $cursor, $nullAt - $cursor);
            $cursor = $nullAt + 1;
            [$value, $cursor] = self::decodeValue($type, $bytes, $cursor);
            $doc[$key] = $value;
        }
        if (ord($bytes[$cursor]) !== 0x00) {
            throw new \RuntimeException('BSON document not null-terminated');
        }
        $nextOffset = $cursor + 1;

        return $doc;
    }

    /** @return array{0: mixed, 1: int} */
    private static function decodeValue(int $type, string $bytes, int $cursor): array
    {
        $need = function (int $n) use ($bytes, &$cursor): string {
            if ($cursor + $n > strlen($bytes)) {
                throw new \RuntimeException('BSON value truncated');
            }
            $slice = substr($bytes, $cursor, $n);
            $cursor += $n;

            return $slice;
        };

        switch ($type) {
            case 0x01:
                return [['t' => 'double', 'v' => unpack('E', strrev($need(8)))[1]], $cursor];
            case 0x02:
                $len = unpack('V', $need(4))[1];
                return [['t' => 'string', 'v' => substr($need($len), 0, $len - 1)], $cursor];
            case 0x03:
                $doc = self::decodeDocument($bytes, $cursor, $next);
                return [['t' => 'document', 'v' => $doc], $next];
            case 0x04:
                $raw = self::decodeDocument($bytes, $cursor, $next);
                return [['t' => 'array', 'v' => array_values($raw)], $next];
            case 0x05:
                $len = unpack('V', $need(4))[1];
                $subtype = ord($need(1));
                if ($subtype === 2) {
                    $innerLen = unpack('V', $need(4))[1];
                    return [['t' => 'binary', 'v' => $need($innerLen), 'subtype' => 2], $cursor];
                }

                return [['t' => 'binary', 'v' => $need($len), 'subtype' => $subtype], $cursor];
            case 0x06:
                return [['t' => 'undefined', 'v' => null], $cursor];
            case 0x07:
                return [['t' => 'objectId', 'v' => bin2hex($need(12))], $cursor];
            case 0x08:
                return [['t' => 'boolean', 'v' => ord($need(1)) === 1], $cursor];
            case 0x09:
                return [['t' => 'date', 'v' => unpack('P', $need(8))[1]], $cursor];
            case 0x0A:
                return [['t' => 'null', 'v' => null], $cursor];
            case 0x0B:
                $patternEnd = strpos($bytes, "\x00", $cursor);
                $pattern = substr($bytes, $cursor, $patternEnd - $cursor);
                $cursor = $patternEnd + 1;
                $optionsEnd = strpos($bytes, "\x00", $cursor);
                $options = substr($bytes, $cursor, $optionsEnd - $cursor);
                $cursor = $optionsEnd + 1;
                return [['t' => 'regex', 'v' => $pattern, 'options' => $options], $cursor];
            case 0x0C:
                $len = unpack('V', $need(4))[1];
                $ns = substr($need($len), 0, $len - 1);
                return [['t' => 'dbPointer', 'v' => $ns, 'id' => bin2hex($need(12))], $cursor];
            case 0x0D:
                $len = unpack('V', $need(4))[1];
                return [['t' => 'code', 'v' => substr($need($len), 0, $len - 1)], $cursor];
            case 0x0E:
                $len = unpack('V', $need(4))[1];
                return [['t' => 'symbol', 'v' => substr($need($len), 0, $len - 1)], $cursor];
            case 0x0F:
                $len = unpack('V', $need(4))[1];
                $codeLen = unpack('V', $need(4))[1];
                $code = substr($need($codeLen), 0, $codeLen - 1);
                $scope = self::decodeDocument($bytes, $cursor, $next);
                return [['t' => 'code', 'v' => $code, 'scope' => $scope], $next];
            case 0x10:
                return [['t' => 'int32', 'v' => unpack('l', $need(4))[1]], $cursor];
            case 0x11:
                $pair = unpack('V2', $need(8));
                return [['t' => 'timestamp', 'v' => $pair[1], 'seconds' => $pair[2]], $cursor];
            case 0x12:
                return [['t' => 'int64', 'v' => unpack('P', $need(8))[1]], $cursor];
            case 0x13:
                return [['t' => 'decimal128', 'v' => self::decodeDecimal128($need(16))], $cursor];
            case 0x7F:
                return [['t' => 'maxKey', 'v' => null], $cursor];
            case 0xFF:
                return [['t' => 'minKey', 'v' => null], $cursor];
            default:
                throw new \RuntimeException(sprintf('Unknown BSON type 0x%02X at offset %d', $type, $cursor - 1));
        }
    }

    // ── Decimal128 (BID encoding, exact via BCMath) ──────────────────────

    private const DECIMAL128_EXPONENT_BIAS = 6176;
    private const DECIMAL128_MAX_COEFFICIENT = '9999999999999999999999999999999999'; // 34 digits (113-bit)
    private const UINT64 = '18446744073709551616';

    /** Decode 16 wire bytes (little-endian) into an exact decimal string. */
    public static function decodeDecimal128(string $bytes): string
    {
        $low = unpack('P', substr($bytes, 0, 8))[1];
        $high = unpack('P', substr($bytes, 8, 8))[1];

        // Specials: combination bits 126..122 = 11110 (Infinity) / 11111 (NaN).
        $comb5 = ($high >> 58) & 0x1F;
        if ($comb5 >= 0b11110) {
            if ($comb5 === 0b11111) {
                return 'NaN';
            }

            return (($high >> 63) & 1) === 1 ? '-Infinity' : 'Infinity';
        }
        // Finite (BID): 1 sign bit + 14-bit biased exponent (bias 6176) at
        // bits 126..113 + 113-bit binary integer coefficient (bits 112..0).
        $sign = ($high >> 63) & 1;
        $coefficientHigh = $high & ((1 << 49) - 1);

        // BCMath only — never routed through float (28K.1). unpack('P')
        // yields SIGNED 64-bit ints; convert to unsigned decimal.
        $lowUnsigned = $low < 0 ? bcadd((string) $low, self::UINT64) : (string) $low;
        $coefficient = bcadd(bcmul((string) $coefficientHigh, self::UINT64), $lowUnsigned);
        $exponent = (($high >> 49) & 0x3FFF) - self::DECIMAL128_EXPONENT_BIAS;

        $digits = $coefficient === '0' ? '0' : ltrim($coefficient, '0');
        $negative = $sign === 1 && $digits !== '0';
        $formatted = self::placeDecimalPoint($digits === '0' ? '0' : $digits, $exponent);

        return ($negative ? '-' : '').$formatted;
    }

    /** Encode an exact decimal string into 16 wire bytes (little-endian). */
    public static function encodeDecimal128(string $value): string
    {
        if ($value === 'Infinity' || $value === '-Infinity') {
            $high = ($value === '-Infinity' ? 1 : 0) << 63;
            $high |= 0b11110 << 58;

            return pack('P', 0).pack('P', $high);
        }
        if ($value === 'NaN') {
            return pack('P', 0).pack('P', (1 << 63) | (0b11111 << 58));
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        if (! preg_match('/^(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $value, $m)) {
            throw new \InvalidArgumentException("Invalid decimal value '{$value}'");
        }
        $intPart = $m[1];
        $fracPart = $m[2] ?? '';
        $explicitExponent = isset($m[3]) ? (int) $m[3] : 0;

        $digits = $intPart.$fracPart;
        if ($digits === '') {
            $digits = '0';
        }
        $exponent = -strlen($fracPart) + $explicitExponent;

        // Normalize trailing zeros up (exactness is preserved either way —
        // coefficient × 10^exponent stays identical).
        while ($exponent < 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            $exponent++;
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
            $exponent = 0;
        }
        if (strlen($digits) > 34 || bccomp($digits, self::DECIMAL128_MAX_COEFFICIENT) === 1) {
            throw new \InvalidArgumentException('Decimal128 coefficient out of range for value');
        }
        $biased = $exponent + self::DECIMAL128_EXPONENT_BIAS;
        if ($biased < 0 || $biased > 0x3FFE) {
            throw new \InvalidArgumentException('Decimal128 exponent out of range');
        }

        // Split the (up to 113-bit) coefficient into low/high words via BCMath.
        $low64 = bcmod($digits, self::UINT64);
        $highPart = bcdiv($digits, self::UINT64);
        if (bccomp($highPart, '562949953421311') === 1) { // > 2^49 - 1
            throw new \InvalidArgumentException('Decimal128 coefficient out of range');
        }
        $high = ((int) $highPart & ((1 << 49) - 1)) | ($biased << 49) | ($negative ? 1 << 63 : 0);

        // low64 is an unsigned 64-bit decimal string; pack its bit pattern.
        $lowInt = bccomp($low64, '9223372036854775807') === 1
            ? (int) bcadd($low64, '-'.self::UINT64)   // wraps to the negative two's-complement pattern
            : (int) $low64;
        $lowBytes = pack('P', $lowInt);
        $highBytes = pack('P', $high);

        return $lowBytes.$highBytes;
    }

    private static function placeDecimalPoint(string $digits, int $exponent): string
    {
        if ($exponent === 0) {
            return $digits;
        }
        if ($exponent > 0) {
            return $digits.str_repeat('0', $exponent);
        }
        $point = strlen($digits) + $exponent;
        if ($point <= 0) {
            return '0.'.str_repeat('0', -$point).$digits;
        }

        return substr($digits, 0, $point).'.'.substr($digits, $point);
    }
}
