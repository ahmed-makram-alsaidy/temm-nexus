<?php

namespace App\Connectors\Postgres\Replication;

/**
 * Byte cursor over one wire message (shared by client + decoder).
 */
final class PgWireCursor
{
    protected int $pos = 0;

    public function __construct(protected string $buf)
    {
    }

    public function byte(): string
    {
        if ($this->pos >= strlen($this->buf)) {
            throw new \RuntimeException('pgoutput message truncated (expected byte)');
        }

        return $this->buf[$this->pos++];
    }

    public function peekByte(): string
    {
        if ($this->pos >= strlen($this->buf)) {
            return '';
        }

        return $this->buf[$this->pos];
    }

    public function int16(): int
    {
        return unpack('n', $this->take(2))[1];
    }

    public function int32(): int
    {
        $raw = unpack('N', $this->take(4))[1];

        return $raw >= 0x80000000 ? $raw - 0x100000000 : $raw; // signed
    }

    public function int64(): int
    {
        // PHP has no 'N!' pack for big-endian 64-bit signed; split halves.
        $hi = unpack('N', $this->take(4))[1];
        $lo = unpack('N', $this->take(4))[1];

        return ($hi << 32) | $lo;
    }

    /** Null-terminated string. */
    public function cstring(): string
    {
        $end = strpos($this->buf, "\0", $this->pos);
        if ($end === false) {
            throw new \RuntimeException('pgoutput message truncated (unterminated cstring)');
        }
        $value = substr($this->buf, $this->pos, $end - $this->pos);
        $this->pos = $end + 1;

        return $value;
    }

    public function cstringLen(int $len): string
    {
        return $this->take($len);
    }

    protected function take(int $n): string
    {
        if ($this->pos + $n > strlen($this->buf)) {
            throw new \RuntimeException('pgoutput message truncated (needed '.$n.' bytes at '.$this->pos.')');
        }
        $chunk = substr($this->buf, $this->pos, $n);
        $this->pos += $n;

        return $chunk;
    }

    public function remaining(): int
    {
        return strlen($this->buf) - $this->pos;
    }
}
