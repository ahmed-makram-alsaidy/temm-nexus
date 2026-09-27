<?php

namespace App\Services\ControlPlane;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Phase 20O dependency-free cron handling: validation, human description,
 * and next-run computation (standard 5-field cron, DOM/DOW OR semantics).
 */
class CronService
{
    public const PRESETS = [
        '* * * * *' => 'Every minute',
        '*/5 * * * *' => 'Every 5 minutes',
        '*/15 * * * *' => 'Every 15 minutes',
        '0 * * * *' => 'Hourly (minute 0)',
        '0 2 * * *' => 'Daily at 02:00',
        '0 9 * * 1' => 'Weekly on Monday at 09:00',
        '0 0 1 * *' => 'Monthly on the 1st at 00:00',
    ];

    public static function valid(string $expr): bool
    {
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) {
            return false;
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        foreach ($parts as $i => $part) {
            if (! self::fieldValid($part, $ranges[$i][0], $ranges[$i][1])) {
                return false;
            }
        }

        return true;
    }

    protected static function fieldValid(string $field, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }
        foreach (explode(',', $field) as $chunk) {
            $step = null;
            if (str_contains($chunk, '/')) {
                [$chunk, $step] = explode('/', $chunk, 2);
                if (! ctype_digit($step) || (int) $step < 1) {
                    return false;
                }
            }
            if ($chunk === '*') {
                continue;
            }
            if (str_contains($chunk, '-')) {
                [$a, $b] = explode('-', $chunk, 2);
                if (! self::numIn($a, $min, $max) || ! self::numIn($b, $min, $max) || (int) $a > (int) $b) {
                    return false;
                }
                continue;
            }
            if (! self::numIn($chunk, $min, $max)) {
                return false;
            }
        }

        return $field !== '';
    }

    protected static function numIn(string $v, int $min, int $max): bool
    {
        return ctype_digit($v) && (int) $v >= $min && (int) $v <= $max;
    }

    public static function describe(string $expr): string
    {
        $expr = trim(preg_replace('/\s+/', ' ', $expr));
        if (isset(self::PRESETS[$expr])) {
            return self::PRESETS[$expr];
        }
        [$m, $h, $dom, $mon, $dow] = preg_split('/\s+/', $expr) + [null, null, null, null, null];
        $bits = [];
        $bits[] = $m === '*' ? 'every minute' : "at minute {$m}";
        $bits[] = $h === '*' ? 'every hour' : "hour {$h}";
        if ($dom !== '*') {
            $bits[] = "day-of-month {$dom}";
        }
        if ($mon !== '*') {
            $bits[] = "month {$mon}";
        }
        if ($dow !== '*' && $dow !== null) {
            $bits[] = 'day-of-week '.$dow.' ('.self::dowName($dow).')';
        }

        return 'Runs '.implode(', ', $bits).'.';
    }

    protected static function dowName(string $dow): string
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        return implode(',', array_unique(array_map(
            fn ($v) => $names[((int) $v) % 8] ?? $v,
            explode(',', preg_replace('#[^0-9,]#', '', $dow))
        )));
    }

    /** @return list<DateTimeImmutable> */
    public static function nextRuns(string $expr, int $count = 3, ?DateTimeImmutable $from = null): array
    {
        abort_unless(self::valid($expr), 422, 'Invalid cron expression.');
        $from ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        [$min, $hour, $dom, $mon, $dow] = preg_split('/\s+/', trim(preg_replace('/\s+/', ' ', $expr)));
        $out = [];
        // Start at the next whole minute.
        $t = $from->setTime((int) $from->format('H'), (int) $from->format('i'), 0)->modify('+1 minute');
        $guard = 0;
        while (count($out) < $count && $guard++ < 525600) {
            if (self::matches((int) $t->format('i'), $min)
                && self::matches((int) $t->format('H'), $hour)
                && self::matches((int) $t->format('j'), $dom, 1, 31)
                && self::matches((int) $t->format('n'), $mon, 1, 12)
                && self::dayMatches($t, $dom, $dow)
            ) {
                $out[] = $t;
            }
            $t = $t->modify('+1 minute');
        }

        return $out;
    }

    protected static function matches(int $value, string $field, int $min = 0, int $max = 59): bool
    {
        if ($field === '*') {
            return true;
        }
        foreach (explode(',', $field) as $chunk) {
            $step = 1;
            if (str_contains($chunk, '/')) {
                [$chunk, $step] = explode('/', $chunk, 2);
                $step = max(1, (int) $step);
            }
            if ($chunk === '*') {
                // Field minimum for */n is the range minimum.
                if (($value - $min) % $step === 0) {
                    return true;
                }
                continue;
            }
            if (str_contains($chunk, '-')) {
                [$a, $b] = explode('-', $chunk, 2);
                if ($value >= (int) $a && $value <= (int) $b && ($value - (int) $a) % $step === 0) {
                    return true;
                }
                continue;
            }
            if ($value === (int) $chunk) {
                return true;
            }
            if ($step > 1 && $value >= (int) $chunk && ($value - (int) $chunk) % $step === 0) {
                return true;
            }
        }

        return false;
    }

    protected static function dayMatches(DateTimeImmutable $t, string $dom, string $dow): bool
    {
        $domStar = $dom === '*';
        $dowStar = $dow === '*';
        if ($domStar && $dowStar) {
            return true;
        }
        $day = (int) $t->format('j');
        // PHP w: 0 (Sun) .. 6 (Sat); cron allows 7 = Sun.
        $w = (int) $t->format('w');
        $domHit = ! $domStar && self::matches($day, $dom, 1, 31);
        $dowHit = ! $dowStar && (self::matches($w, $dow, 0, 7) || ($w === 0 && self::matches(7, $dow, 0, 7)));

        return $domStar ? $dowHit : ($dowStar ? $domHit : ($domHit || $dowHit));
    }
}
