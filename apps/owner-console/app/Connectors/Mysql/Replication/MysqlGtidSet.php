<?php

namespace App\Connectors\Mysql\Replication;

/**
 * Phase 35.6 — minimal MySQL GTID set arithmetic for position comparison.
 *
 * A GTID set is "uuid:1-5,uuid2:1-3". Only the operations the CDC layer
 * needs are implemented: parse, union (checkpoint advance) and containment
 * (has the applier reached a final source position?).
 */
final class MysqlGtidSet
{
    /** @var array<string, list<array{int, int}>> uuid → list of [start, end] intervals (sorted, merged) */
    protected array $intervals = [];

    public static function parse(string $set): self
    {
        $parsed = new self;
        $set = trim($set);
        if ($set === '') {
            return $parsed;
        }
        foreach (explode(',', $set) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, ':')) {
                continue;
            }
            [$uuid, $ranges] = explode(':', $part, 2);
            $uuid = strtoupper(trim($uuid));
            foreach (explode(':', $ranges) as $range) {
                if (! str_contains($range, '-')) {
                    $parsed->addInterval($uuid, (int) $range, (int) $range);
                    continue;
                }
                [$start, $end] = array_map(intval(...), explode('-', $range, 2));
                $parsed->addInterval($uuid, $start, $end);
            }
        }

        return $parsed;
    }

    public function add(string $uuid, int $transaction): self
    {
        $clone = clone $this;
        $clone->addInterval(strtoupper($uuid), $transaction, $transaction);

        return $clone;
    }

    /** Union with another set (returns a NEW set). */
    public function union(self $other): self
    {
        $clone = clone $this;
        foreach ($other->intervals as $uuid => $list) {
            foreach ($list as [$start, $end]) {
                $clone->addInterval($uuid, $start, $end);
            }
        }

        return $clone;
    }

    /** Does this set CONTAIN every transaction of $other? */
    public function contains(self $other): bool
    {
        foreach ($other->intervals as $uuid => $list) {
            $mine = $this->intervals[$uuid] ?? [];
            if ($mine === []) {
                return false;
            }
            foreach ($list as [$start, $end]) {
                if (! $this->covered($mine, $start, $end)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->intervals === [];
    }

    public function toString(): string
    {
        $parts = [];
        foreach ($this->intervals as $uuid => $list) {
            $ranges = [];
            foreach ($list as [$start, $end]) {
                $ranges[] = $start === $end ? (string) $start : "{$start}-{$end}";
            }
            $parts[] = $uuid.':'.implode(':', $ranges);
        }
        ksort($parts);

        return implode(',', $parts);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['gtid_set' => $this->toString()];
    }

    protected function addInterval(string $uuid, int $start, int $end): void
    {
        $list = $this->intervals[$uuid] ?? [];
        $list[] = [$start, $end];
        usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
        // merge overlapping/adjacent
        $merged = [];
        foreach ($list as [$s, $e]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $s <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }
        $this->intervals[$uuid] = $merged;
    }

    /** @param list<array{int, int}> $list */
    protected function covered(array $list, int $start, int $end): bool
    {
        foreach ($list as [$s, $e]) {
            if ($s <= $start && $end <= $e) {
                return true;
            }
        }

        return false;
    }
}
