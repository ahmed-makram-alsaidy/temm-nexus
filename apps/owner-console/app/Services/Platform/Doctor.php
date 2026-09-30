<?php

namespace App\Services\Platform;

use App\Models\BackupDestination;
use App\Models\BackupRecord;
use App\Models\PlatformDoctorRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Phase 26.1D — deployment doctor.
 *
 * Every check reports one of: PASS / WARNING / FAIL / NOT_CONFIGURED /
 * NOT_APPLICABLE. No fake PASS: optional features that are simply not set
 * up report NOT_CONFIGURED, and environment-invisible facts report
 * NOT_APPLICABLE with an operator hint.
 *
 * Output is secret-safe by construction: no APP_KEY, no passwords, no PATs,
 * no AI keys, no tokens — presence/absence only.
 */
class Doctor
{
    public const EXIT_HEALTHY = 0;

    public const EXIT_WARNINGS = 1;

    public const EXIT_FAILURES = 2;

    /** @return array{checks: array<int, array{key:string,label:string,status:string,detail:string}>, summary: array{pass:int,warning:int,fail:int,not_configured:int,not_applicable:int,exit_code:int}} */
    public static function run(bool $persist = true): array
    {
        $checks = [
            self::version(),
            self::environment(),
            self::debugFlag(),
            self::appKey(),
            self::database(),
            self::redis(),
            self::storage(),
            self::directories(),
            self::queueWorker(),
            self::scheduler(),
            self::reverb(),
            self::proxy(),
            self::publicUrl(),
            self::https(),
            self::backupConfiguration(),
            self::lastBackup(),
            self::lastRestoreDrill(),
            self::disk(),
            self::pendingMigrations(),
            self::initialization(),
            self::firstAdmin(),
            self::setupLock(),
            self::aiProviders(),
            self::mail(),
        ];

        $summary = [
            'pass' => count(array_filter($checks, fn ($c) => $c['status'] === 'PASS')),
            'warning' => count(array_filter($checks, fn ($c) => $c['status'] === 'WARNING')),
            'fail' => count(array_filter($checks, fn ($c) => $c['status'] === 'FAIL')),
            'not_configured' => count(array_filter($checks, fn ($c) => $c['status'] === 'NOT_CONFIGURED')),
            'not_applicable' => count(array_filter($checks, fn ($c) => $c['status'] === 'NOT_APPLICABLE')),
        ];
        $summary['exit_code'] = $summary['fail'] > 0
            ? self::EXIT_FAILURES
            : ($summary['warning'] > 0 ? self::EXIT_WARNINGS : self::EXIT_HEALTHY);

        if ($persist) {
            try {
                PlatformDoctorRun::create([
                    'exit_code' => $summary['exit_code'],
                    'pass' => $summary['pass'],
                    'warning' => $summary['warning'],
                    'fail' => $summary['fail'],
                    'not_configured' => $summary['not_configured'],
                    'not_applicable' => $summary['not_applicable'],
                    'platform_version' => (string) config('platform.version'),
                ]);
            } catch (Throwable) {
                // Diagnostics must never crash because history could not be written.
            }
        }

        return ['checks' => $checks, 'summary' => $summary];
    }

    // ── individual checks ────────────────────────────────────────────────

    protected static function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    protected static function version(): array
    {
        return self::check('version', 'Platform version', 'PASS', (string) config('platform.version'));
    }

    protected static function environment(): array
    {
        $env = (string) config('app.env');

        return self::check('environment', 'APP_ENV', $env === 'production' ? 'PASS' : 'WARNING', $env === 'production' ? 'production' : "{$env} (not production)");
    }

    protected static function debugFlag(): array
    {
        $debug = (bool) config('app.debug');

        return self::check('app_debug', 'APP_DEBUG', $debug ? (app()->environment('production') ? 'FAIL' : 'WARNING') : 'PASS', $debug ? 'debug enabled — must be false in production' : 'disabled');
    }

    protected static function appKey(): array
    {
        $present = (string) config('app.key') !== '';

        return self::check('app_key', 'APP_KEY', $present ? 'PASS' : 'FAIL', $present ? 'present (value never displayed)' : 'missing — run: php artisan key:generate');
    }

    protected static function database(): array
    {
        try {
            DB::select('select 1');
            $version = '';
            try {
                $v = DB::selectOne('select version() as v')->v ?? '';
                $version = ' ('.substr(explode(' ', $v)[1] ?? $v, 0, 30).')';
            } catch (Throwable) {
            }

            return self::check('database', 'PostgreSQL', 'PASS', 'connected'.$version);
        } catch (Throwable) {
            return self::check('database', 'PostgreSQL', 'FAIL', 'unreachable — check DB_* settings and the postgres service');
        }
    }

    protected static function redis(): array
    {
        try {
            $pong = Redis::connection('default')->ping();
            $ok = $pong === true || str_contains((string) $pong, 'PONG');
            $version = '';
            if ($ok) {
                try {
                    $info = Redis::connection('default')->info('server');
                    $version = ' '.($info['server']['redis_version'] ?? '');
                } catch (Throwable) {
                }
            }

            return self::check('redis', 'Redis', $ok ? 'PASS' : 'FAIL', $ok ? 'connected'.$version : 'unexpected response');
        } catch (Throwable) {
            return self::check('redis', 'Redis', 'FAIL', 'unreachable — check REDIS_* settings and the redis service');
        }
    }

    protected static function storage(): array
    {
        $paths = [storage_path('framework'), storage_path('app'), storage_path('app/private'), storage_path('logs')];
        $unwritable = array_values(array_filter($paths, fn ($p) => ! is_dir($p) || ! is_writable($p)));

        return self::check('storage', 'Storage writable', $unwritable === [] ? 'PASS' : 'FAIL', $unwritable === [] ? 'runtime paths writable' : 'not writable: '.implode(', ', $unwritable));
    }

    protected static function directories(): array
    {
        $required = ['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/app/private', 'bootstrap/cache'];
        $missing = array_values(array_filter($required, fn ($p) => ! is_dir(base_path($p))));

        return self::check('directories', 'Required directories', $missing === [] ? 'PASS' : 'FAIL', $missing === [] ? 'all present' : 'missing: '.implode(', ', $missing));
    }

    protected static function queueWorker(): array
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('horizon:status');
            $output = trim(\Illuminate\Support\Facades\Artisan::output());

            return self::check('queue_worker', 'Queue worker (Horizon)', str_contains(strtolower($output), 'running') ? 'PASS' : 'WARNING', $output !== '' ? $output : 'status unknown — verify the horizon service is running');
        } catch (Throwable) {
            return self::check('queue_worker', 'Queue worker (Horizon)', 'WARNING', 'cannot determine status — verify the horizon service is running');
        }
    }

    protected static function scheduler(): array
    {
        try {
            $heartbeat = Cache::get('platform.scheduler.heartbeat');
        } catch (Throwable) {
            // Cache store (often the database) unreachable — cannot measure.
            return self::check('scheduler', 'Scheduler heartbeat', 'FAIL', 'cannot read heartbeat — cache/queue backing store unreachable');
        }
        if (! $heartbeat) {
            return self::check('scheduler', 'Scheduler heartbeat', 'WARNING', 'no heartbeat yet — the scheduler container may be down or has not run a scheduled tick in the last 5 minutes');
        }
        // Cross-process cache stores (database/redis) refuse to hydrate stored
        // objects (serializable_classes => false), so an object-valued
        // heartbeat arrives as __PHP_Incomplete_Class. The doctor must report,
        // never fatal: anything that is not a datetime string or date is a
        // degraded signal.
        if (! is_string($heartbeat) && ! $heartbeat instanceof \DateTimeInterface) {
            return self::check('scheduler', 'Scheduler heartbeat', 'WARNING', 'heartbeat present but unreadable — the scheduler is storing an incompatible value (expected a datetime string)');
        }
        try {
            $age = now()->diffInMinutes($heartbeat);
        } catch (Throwable) {
            return self::check('scheduler', 'Scheduler heartbeat', 'WARNING', 'heartbeat present but unparseable — verify the scheduler heartbeat value format');
        }

        return self::check('scheduler', 'Scheduler heartbeat', $age <= 15 ? 'PASS' : 'WARNING', $age <= 15 ? "last tick {$age} min ago" : "stale ({$age} min ago) — scheduler may be down");
    }

    protected static function reverb(): array
    {
        // config/reverb.php nests credentials under apps.apps.0.
        $key = (string) data_get(config('reverb'), 'apps.apps.0.key');
        $host = (string) (config('reverb.servers.host') ?: '0.0.0.0');
        $port = (int) (config('reverb.servers.port') ?: 6001);
        $configured = $key !== '';
        if (! $configured) {
            return self::check('reverb', 'Realtime (Reverb)', 'NOT_CONFIGURED', 'REVERB_APP_KEY not set — realtime disabled until configured');
        }
        try {
            // The server binds 0.0.0.0 inside the reverb container; from the
            // app container the service is reachable at the compose hostname.
            $targets = $host === '0.0.0.0' || $host === '' ? ['reverb', '127.0.0.1'] : [$host];
            foreach ($targets as $probeHost) {
                $sock = @fsockopen($probeHost, $port, $errno, $errstr, 2);
                if ($sock) {
                    fclose($sock);

                    return self::check('reverb', 'Realtime (Reverb)', 'PASS', "listening on {$probeHost}:{$port}");
                }
            }

            return self::check('reverb', 'Realtime (Reverb)', 'FAIL', "configured but not reachable on ".implode('/', $targets).":{$port} — check the reverb service");
        } catch (Throwable) {
            return self::check('reverb', 'Realtime (Reverb)', 'FAIL', "configured but not reachable on {$host}:{$port} — check the reverb service");
        }
    }

    protected static function proxy(): array
    {
        $trusted = (string) config('trustedproxy.proxies', config('app.trusted_proxies', ''));

        return self::check('proxy', 'Reverse proxy (Caddy)', 'NOT_APPLICABLE', 'edge configuration lives in infrastructure/caddy/Caddyfile.selfhost — verify from the host: docker compose ps caddy');
    }

    protected static function publicUrl(): array
    {
        $url = (string) config('app.url');
        if ($url === '' || str_contains($url, 'localhost')) {
            return self::check('public_url', 'Public URL', 'WARNING', "{$url} — set APP_URL to the operator-facing address");
        }

        return self::check('public_url', 'Public URL', 'PASS', $url);
    }

    protected static function https(): array
    {
        $https = str_starts_with((string) config('app.url'), 'https://');
        if ($https) {
            return self::check('https', 'HTTPS expectation', 'PASS', 'APP_URL uses https');
        }

        return self::check('https', 'HTTPS expectation', app()->environment('production') ? 'WARNING' : 'NOT_CONFIGURED', 'APP_URL is not https — real TLS requires a domain (docs/open-source/DOMAIN_TLS.md)');
    }

    protected static function backupConfiguration(): array
    {
        try {
            $count = BackupDestination::count();
            if ($count === 0) {
                return self::check('backup_config', 'Backup destinations', 'NOT_CONFIGURED', 'no destinations defined — configure Backup Center in the admin UI');
            }

            return self::check('backup_config', 'Backup destinations', 'PASS', "{$count} destination(s) configured");
        } catch (Throwable) {
            return self::check('backup_config', 'Backup destinations', 'NOT_APPLICABLE', 'backup tables not available yet');
        }
    }

    protected static function lastBackup(): array
    {
        try {
            $last = BackupRecord::latest('finished_at')->first();
            if (! $last) {
                return self::check('last_backup', 'Last successful backup', 'NOT_CONFIGURED', 'no backup runs recorded yet');
            }

            return self::check('last_backup', 'Last successful backup', $last->status === 'ok' ? 'PASS' : 'WARNING', "{$last->status} · {$last->db_name} · ".($last->finished_at?->diffForHumans() ?? 'unknown time'));
        } catch (Throwable) {
            return self::check('last_backup', 'Last successful backup', 'NOT_APPLICABLE', 'backup tables not available yet');
        }
    }

    protected static function lastRestoreDrill(): array
    {
        try {
            $drilled = BackupRecord::where('restore_test_status', 'ok')->count();
            if ($drilled === 0) {
                return self::check('restore_drill', 'Last restore drill', 'NOT_CONFIGURED', 'no verified restore yet — run a drill (docs/open-source/BACKUP_RESTORE.md)');
            }

            return self::check('restore_drill', 'Last restore drill', 'PASS', "{$drilled} verified restore(s) recorded");
        } catch (Throwable) {
            return self::check('restore_drill', 'Last restore drill', 'NOT_APPLICABLE', 'backup tables not available yet');
        }
    }

    protected static function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return self::check('disk', 'Disk space', 'NOT_APPLICABLE', 'cannot measure on this filesystem');
        }
        $freeGb = round($free / 1024 / 1024 / 1024, 1);
        $pct = round(100 * (1 - $free / $total));
        if ($freeGb < 2) {
            return self::check('disk', 'Disk space', 'FAIL', "{$freeGb} GB free ({$pct}% used) — below 2 GB safety floor");
        }
        if ($freeGb < 5) {
            return self::check('disk', 'Disk space', 'WARNING', "{$freeGb} GB free ({$pct}% used) — consider freeing space");
        }

        return self::check('disk', 'Disk space', 'PASS', "{$freeGb} GB free ({$pct}% used)");
    }

    protected static function pendingMigrations(): array
    {
        try {
            $files = collect(scandir(database_path('migrations')) ?: [])
                ->filter(fn ($f) => str_ends_with($f, '.php'))->count();
            $ran = DB::table('migrations')->count();
            $pending = max(0, $files - $ran);

            return self::check('migrations', 'Pending migrations', $pending === 0 ? 'PASS' : 'FAIL', $pending === 0 ? 'schema up to date' : "{$pending} pending — run: php artisan migrate --force");
        } catch (Throwable) {
            return self::check('migrations', 'Pending migrations', 'FAIL', 'cannot read migration state');
        }
    }

    protected static function initialization(): array
    {
        $initialized = SetupState::initialized();

        return self::check('initialization', 'Platform initialization', $initialized ? 'PASS' : 'WARNING', $initialized ? 'initialized' : 'not initialized — complete /setup');
    }

    protected static function firstAdmin(): array
    {
        try {
            $admins = User::query()->where('is_admin', true)->count();

            return self::check('first_admin', 'First admin', $admins > 0 ? 'PASS' : 'FAIL', $admins > 0 ? "{$admins} platform admin(s)" : 'no platform admin — complete /setup step 6');
        } catch (Throwable) {
            return self::check('first_admin', 'First admin', 'FAIL', 'cannot read users table');
        }
    }

    protected static function setupLock(): array
    {
        $flag = SetupState::get(SetupState::KEY_COMPLETED);

        return self::check('setup_lock', 'Setup lock', $flag === '1' ? 'PASS' : 'NOT_CONFIGURED', $flag === '1' ? 'first-run setup locked' : 'setup not completed yet');
    }

    protected static function aiProviders(): array
    {
        try {
            $enabled = \App\Models\AiProviderConfig::where('enabled', true)->count();
            if ($enabled === 0) {
                return self::check('ai', 'AI providers (optional)', 'NOT_CONFIGURED', 'no provider enabled — the platform works fully without AI');
            }

            return self::check('ai', 'AI providers (optional)', 'PASS', "{$enabled} enabled (keys never displayed)");
        } catch (Throwable) {
            return self::check('ai', 'AI providers (optional)', 'NOT_APPLICABLE', 'ai tables not available yet');
        }
    }

    protected static function mail(): array
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array', ''], true)) {
            return self::check('mail', 'Mail (optional)', 'NOT_CONFIGURED', "mailer '{$mailer}' — mail features report NOT CONFIGURED until SMTP is set");
        }
        $host = (string) config('mail.mailers.smtp.host');

        return self::check('mail', 'Mail (optional)', $host !== '' ? 'PASS' : 'WARNING', $host !== '' ? "smtp via {$host}" : 'smtp selected but MAIL_HOST empty');
    }
}
