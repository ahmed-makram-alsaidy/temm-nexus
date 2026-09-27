<?php

namespace App\Console\Commands;

use App\Services\Platform\Doctor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use ZipArchive;

/**
 * Phase 26.1E — safe diagnostic support bundle.
 *
 * php artisan platform:support-bundle [--output=<zip-path>]
 *
 * Produces a local ZIP with sanitized diagnostics ONLY. It never reads the
 * .env file, never includes secret VALUES (configuration key NAMES only),
 * never dumps customer data, password hashes, tokens or private files.
 * Exit 0 on success, 2 when the archive could not be written.
 */
class PlatformSupportBundleCommand extends Command
{
    protected $signature = 'platform:support-bundle {--output= : Target zip path (defaults to storage/app/private/support-bundles/)}';

    protected $description = 'Create a sanitized diagnostic support bundle (zip) — secret-safe by construction';

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('php-zip extension is required to build the support bundle.');

            return 2;
        }

        $dir = storage_path('app/private/support-bundles');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create bundle directory: {$dir}");

            return 2;
        }

        $name = 'support-bundle-'.now()->format('Ymd-His').'-'.substr((string) config('platform.version'), 0, 20).'.zip';
        $path = $this->option('output') ?: $dir.'/'.$name;

        $manifest = $this->manifest();
        $doctor = Artisan::call('platform:doctor', ['--json' => true]);
        $doctorJson = Artisan::output();

        $files = [
            'manifest.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'doctor.json' => $doctorJson,
            'migrations.json' => json_encode($this->migrationStatus(), JSON_PRETTY_PRINT),
            'runtime.json' => json_encode($this->runtimeInfo(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'queue.json' => json_encode($this->queueInfo(), JSON_PRETTY_PRINT),
            'backups.json' => json_encode($this->backupInfo(), JSON_PRETTY_PRINT),
            // 28.1O — safe connector inventory: keys, versions, trust, health.
            // Never connection strings, credentials or vault contents.
            'connectors.json' => json_encode($this->connectorInfo(), JSON_PRETTY_PRINT),
            'environment-keys.txt' => $this->environmentKeys(),
            'recent-errors.txt' => $this->recentErrors(),
        ];

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Cannot open {$path} for writing.");

            return 2;
        }
        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, (string) $content);
        }
        $zip->close();

        $this->info("Support bundle written: {$path}");
        $this->line('Contents: sanitized diagnostics only — no secrets, no .env values, no customer data.');

        return 0;
    }

    protected function manifest(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'platform_version' => (string) config('platform.version'),
            'environment' => (string) config('app.env'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'schema_note' => 'diagnostics only — no secret values are included in this bundle',
        ];
    }

    protected function migrationStatus(): array
    {
        try {
            $ran = \Illuminate\Support\Facades\DB::table('migrations')->orderBy('batch')->orderBy('id')->pluck('batch', 'migration');
            $files = collect(scandir(database_path('migrations')) ?: [])->filter(fn ($f) => str_ends_with($f, '.php'))->values();

            return [
                'ran_count' => $ran->count(),
                'pending' => $files->diffKeys($ran)->values()->all(),
                'batches' => $ran->unique()->values()->all(),
            ];
        } catch (Throwable $e) {
            return ['error' => 'unavailable'];
        }
    }

    protected function runtimeInfo(): array
    {
        return [
            'php_extensions_loaded' => get_loaded_extensions(),
            'storage_paths_present' => [
                'framework_cache' => is_dir(storage_path('framework/cache')),
                'framework_sessions' => is_dir(storage_path('framework/sessions')),
                'framework_views' => is_dir(storage_path('framework/views')),
                'app_private' => is_dir(storage_path('app/private')),
            ],
            'disk_free_gb' => round((float) @disk_free_space(storage_path()) / 1024 / 1024 / 1024, 2),
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'cli',
            'hostname' => gethostname() ?: 'unknown',
        ];
    }

    /** 28.1O — safe connector inventory (no connection strings or credentials). */
    protected function connectorInfo(): array
    {
        try {
            $registry = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance();

            return [
                'registered' => collect($registry->all())->map(fn ($d) => [
                    'key' => $d['key'],
                    'version' => $d['version'],
                    'trust' => $d['trust'],
                    'capabilities' => $d['capabilities'],
                    'enabled' => $d['enabled'],
                ])->values()->all(),
                'note' => 'metadata only — connection strings, credentials and vault contents are never exported',
            ];
        } catch (Throwable $e) {
            return ['error' => 'unavailable'];
        }
    }

    protected function queueInfo(): array
    {
        $out = ['connection' => (string) config('queue.default')];
        try {
            $out['pending_jobs'] = \Illuminate\Support\Facades\Queue::size();
        } catch (Throwable) {
            $out['pending_jobs'] = 'unavailable';
        }
        try {
            $out['failed_jobs'] = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        } catch (Throwable) {
            $out['failed_jobs'] = 'unavailable';
        }

        return $out;
    }

    protected function backupInfo(): array
    {
        try {
            return [
                'destinations' => \App\Models\BackupDestination::count(),
                'last_backup_status' => optional(\App\Models\BackupRecord::latest('finished_at')->first())->only(['db_name', 'status', 'finished_at']),
                'verified_restores' => \App\Models\BackupRecord::where('restore_test_status', 'ok')->count(),
            ];
        } catch (Throwable) {
            return ['unavailable' => true];
        }
    }

    /** Configuration key NAMES present in the environment — never values. */
    protected function environmentKeys(): string
    {
        $interesting = [
            'APP_ENV' => 'app.env', 'APP_DEBUG' => 'app.debug', 'APP_URL' => 'app.url',
            'DB_CONNECTION' => 'database.default', 'DB_HOST' => 'database.connections.pgsql.host',
            'DB_PORT' => 'database.connections.pgsql.port', 'DB_DATABASE' => 'database.connections.pgsql.database',
            'REDIS_HOST' => 'database.redis.default.host', 'REDIS_PORT' => 'database.redis.default.port',
            'CACHE_STORE' => 'cache.default', 'QUEUE_CONNECTION' => 'queue.default',
            'SESSION_DRIVER' => 'session.driver', 'MAIL_MAILER' => 'mail.default',
            'BROADCAST_CONNECTION' => 'broadcasting.default', 'PLATFORM_NAME' => 'platform.name',
        ];
        $lines = ['# Configuration keys present (names only — values are NEVER exported)'];
        foreach ($interesting as $key => $configPath) {
            $lines[] = $key.': '.(app('config')->has($configPath) ? 'set' : 'unset');
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Recent log ERROR summaries, line-level sanitized: message bodies are
     * truncated to 200 chars and anything secret-shaped is redacted.
     */
    protected function recentErrors(): string
    {
        $pattern = storage_path('logs/laravel*.log');
        $out = ["# Recent error summaries (sanitized: truncated, secret-shaped strings redacted)"];
        $files = glob($pattern) ?: [];
        rsort($files);
        $collected = 0;
        foreach ($files as $file) {
            if ($collected >= 40) {
                break;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                if ($collected >= 40) {
                    break;
                }
                if (! str_contains($line, '.ERROR:')) {
                    continue;
                }
                // Sanitize: token-shaped strings and KEY=value pairs are redacted
                // before any line enters the bundle.
                $line = preg_replace('/\b(sbp_|sk-ant-|sk-proj-|sk-|ghp_|github_pat_|AIza)[A-Za-z0-9_-]{8,}/', '[REDACTED-TOKEN]', $line) ?? $line;
                $line = preg_replace('/(password|secret|token|authorization|PAT)[=:]{1,2}\S+/i', '$1=[REDACTED]', $line) ?? $line;
                $out[] = substr($line, 0, 200);
                $collected++;
            }
        }
        if ($collected === 0) {
            $out[] = 'no recent error entries found';
        }

        return implode(PHP_EOL, $out).PHP_EOL;
    }
}
