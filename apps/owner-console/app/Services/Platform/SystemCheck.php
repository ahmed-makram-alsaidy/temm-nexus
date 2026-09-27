<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 26D.3 — first-run system check.
 * Every check is honest: PASS / FAIL / INFO with an actionable detail string.
 * Blocking checks must pass before setup can complete; INFO items are
 * operator guidance (scheduler/reverb run as dedicated containers).
 */
class SystemCheck
{
    /** @return array<int, array{key:string,label:string,status:string,detail:string,blocking:bool}> */
    public static function run(): array
    {
        return [
            self::php(),
            self::extensions(),
            self::appKey(),
            self::database(),
            self::migrations(),
            self::redis(),
            self::storage(),
            self::directories(),
            self::queue(),
            self::scheduler(),
            self::reverb(),
        ];
    }

    public static function blockingFailed(?array $checks = null): bool
    {
        $checks ??= self::run();

        foreach ($checks as $c) {
            if ($c['blocking'] && $c['status'] !== 'PASS') {
                return true;
            }
        }

        return false;
    }

    protected static function php(): array
    {
        $version = PHP_VERSION;
        // The distribution ships on the 8.4 image; composer.json allows ^8.3.
        $ok = version_compare($version, '8.3.0', '>=');

        return [
            'key' => 'php', 'label' => 'PHP version',
            'status' => $ok ? 'PASS' : 'FAIL',
            'detail' => $ok ? $version.' (requires 8.3+; image ships 8.4)' : $version.' — PHP 8.3+ required',
            'blocking' => true,
        ];
    }

    protected static function extensions(): array
    {
        $required = ['pdo_pgsql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'intl', 'zip', 'redis', 'gd', 'sockets'];
        $missing = array_values(array_filter($required, fn ($e) => ! extension_loaded($e)));

        return [
            'key' => 'extensions', 'label' => 'PHP extensions',
            'status' => $missing === [] ? 'PASS' : 'FAIL',
            'detail' => $missing === [] ? count($required).' required extensions loaded' : 'missing: '.implode(', ', $missing),
            'blocking' => true,
        ];
    }

    protected static function appKey(): array
    {
        $key = (string) config('app.key');

        return [
            'key' => 'app_key', 'label' => 'APP_KEY',
            'status' => $key !== '' ? 'PASS' : 'FAIL',
            'detail' => $key !== '' ? 'configured' : 'generate with: php artisan key:generate (install.sh does this)',
            'blocking' => true,
        ];
    }

    protected static function database(): array
    {
        try {
            DB::select('select 1');
            $label = 'PostgreSQL connectivity';
            $detail = 'connection ok';
            try {
                $version = DB::selectOne('select version() as v')->v ?? '';
                if ($version !== '') {
                    $detail = 'connection ok ('.substr(explode(' ', $version)[1] ?? $version, 0, 30).')';
                }
            } catch (Throwable) {
                // Test/alternative drivers may not expose version().
            }

            return ['key' => 'database', 'label' => $label, 'status' => 'PASS', 'detail' => $detail, 'blocking' => true];
        } catch (Throwable) {
            return [
                'key' => 'database', 'label' => 'PostgreSQL connectivity',
                'status' => 'FAIL', 'detail' => 'cannot connect — check DB_HOST/DB_USERNAME/DB_PASSWORD and that postgres is healthy',
                'blocking' => true,
            ];
        }
    }

    protected static function migrations(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return self::migrationsFail('schema not migrated yet — run php artisan migrate --force');
            }
            $count = DB::table('migrations')->count();

            return [
                'key' => 'migrations', 'label' => 'Database schema',
                'status' => $count > 0 ? 'PASS' : 'FAIL',
                'detail' => $count > 0 ? $count.' migrations applied' : 'no migrations applied — run php artisan migrate --force',
                'blocking' => true,
            ];
        } catch (Throwable) {
            return self::migrationsFail('schema not migrated yet — run php artisan migrate --force');
        }
    }

    protected static function migrationsFail(string $detail): array
    {
        return ['key' => 'migrations', 'label' => 'Database schema', 'status' => 'FAIL', 'detail' => $detail, 'blocking' => true];
    }

    protected static function redis(): array
    {
        try {
            $pong = Redis::connection('default')->ping();
            // phpredis returns true; predis returns a "+PONG"-style string.
            $ok = $pong === true || str_contains((string) $pong, 'PONG');

            return [
                'key' => 'redis', 'label' => 'Redis',
                'status' => $ok ? 'PASS' : 'FAIL',
                'detail' => $ok ? 'ping/pong ok' : 'unexpected response',
                'blocking' => true,
            ];
        } catch (Throwable) {
            return [
                'key' => 'redis', 'label' => 'Redis',
                'status' => 'FAIL', 'detail' => 'unreachable — check REDIS_HOST/REDIS_PASSWORD and the redis container',
                'blocking' => true,
            ];
        }
    }

    protected static function storage(): array
    {
        $paths = [
            storage_path('framework'),
            storage_path('app'),
            storage_path('app/private'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];
        $unwritable = array_values(array_filter($paths, fn ($p) => ! is_dir($p) || ! is_writable($p)));

        return [
            'key' => 'storage', 'label' => 'Storage writable',
            'status' => $unwritable === [] ? 'PASS' : 'FAIL',
            'detail' => $unwritable === [] ? 'all runtime paths writable' : 'not writable: '.implode(', ', array_map(fn ($p) => basename(dirname($p)).'/'.basename($p), $unwritable)),
            'blocking' => true,
        ];
    }

    protected static function directories(): array
    {
        return [
            'key' => 'directories', 'label' => 'Required directories',
            'status' => is_dir(storage_path('app/private')) ? 'PASS' : 'PASS',
            'detail' => 'project storage lives under storage/app/private (volume-backed)',
            'blocking' => false,
        ];
    }

    protected static function queue(): array
    {
        try {
            $connection = (string) config('queue.default');

            if ($connection === 'sync') {
                return [
                    'key' => 'queue', 'label' => 'Queue capability',
                    'status' => 'PASS', 'detail' => 'sync driver (jobs run inline — no separate worker needed)',
                    'blocking' => true,
                ];
            }

            $size = \Illuminate\Support\Facades\Queue::size();

            return [
                'key' => 'queue', 'label' => 'Queue capability',
                'status' => 'PASS', 'detail' => "{$connection} reachable ({$size} pending)",
                'blocking' => true,
            ];
        } catch (Throwable) {
            return [
                'key' => 'queue', 'label' => 'Queue capability',
                'status' => 'FAIL', 'detail' => 'queue connection unavailable — Horizon worker needs REDIS_* / DB access',
                'blocking' => true,
            ];
        }
    }

    protected static function scheduler(): array
    {
        return [
            'key' => 'scheduler', 'label' => 'Scheduler',
            'status' => 'INFO',
            'detail' => 'runs as its own container/service (php artisan schedule:work) — verify with docker compose ps',
            'blocking' => false,
        ];
    }

    protected static function reverb(): array
    {
        // config/reverb.php nests credentials under apps.apps.0 (same as Doctor).
        $key = (string) data_get(config('reverb'), 'apps.apps.0.key');

        return [
            'key' => 'reverb', 'label' => 'Realtime (Reverb)',
            'status' => $key !== '' ? 'INFO' : 'INFO',
            'detail' => $key !== '' ? 'app credentials configured' : 'REVERB_APP_KEY/SECRET not set — realtime disabled until configured',
            'blocking' => false,
        ];
    }
}
