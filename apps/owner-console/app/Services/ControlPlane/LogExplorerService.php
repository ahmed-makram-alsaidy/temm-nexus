<?php

namespace App\Services\ControlPlane;

use App\Models\AdminAuditEntry;
use App\Models\BackupRecord;
use App\Models\FunctionInvocation;
use App\Models\Project;
use App\Models\RealtimeEvent;
use App\Models\SqlQueryHistory;
use App\Models\TaskRun;
use App\Models\WebhookDelivery;

/**
 * Phase 20Q unified Logs Explorer backend. Normalizes every project log
 * source into {time, source, severity, summary, request_id, ref} rows:
 * laravel files, audit trail, function invocations, webhook deliveries,
 * task runs, realtime events, SQL history, backups. Display text is
 * sanitized; secrets never surface (sources store redacted forms already).
 */
class LogExplorerService
{
    public const SOURCES = [
        'laravel', 'auth', 'audit', 'functions', 'webhooks', 'scheduler',
        'realtime', 'sql', 'backups', 'queue',
    ];

    /**
     * @param array{source:?string,severity:?string,q:?string,request_id:?string,since:?string,limit:int} $filters
     * @return array{rows:list<array{time:string,source:string,severity:string,summary:string,request_id:?string,ref:string}>,truncated:bool}
     */
    public static function query(Project $project, array $filters): array
    {
        $source = $filters['source'] ?? null;
        $severity = strtolower((string) ($filters['severity'] ?? ''));
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $requestId = trim((string) ($filters['request_id'] ?? ''));
        $since = trim((string) ($filters['since'] ?? ''));
        $limit = min(200, max(10, (int) ($filters['limit'] ?? 100)));
        $cutoff = match ($since) {
            '24h' => time() - 86400,
            '7d' => time() - 7 * 86400,
            '30d' => time() - 30 * 86400,
            default => 0,
        };

        $rows = [];
        $want = fn (?string $s) => ! $source || $source === 'all' || $source === $s;

        if ($want('laravel')) {
            foreach (self::laravelRows($project, $severity, $q) as $row) {
                $rows[] = $row;
            }
        }
        if ($want('auth') || $want('audit')) {
            foreach (self::auditRows($project, $want('auth') && ! $want('audit') ? 'auth' : null) as $row) {
                $rows[] = $row;
            }
        }
        if ($want('functions')) {
            foreach (FunctionInvocation::query()
                ->whereHas('function', fn ($fq) => $fq->where('project_id', $project->id))
                ->orderByDesc('id')->limit(100)->get() as $inv) {
                $rows[] = [
                    'time' => (string) $inv->created_at,
                    'source' => 'functions',
                    'severity' => $inv->status < 400 ? 'info' : ($inv->status === 429 ? 'warning' : 'error'),
                    'summary' => "fn#{$inv->function_id} v{$inv->version} → HTTP {$inv->status} in {$inv->duration_ms}ms ({$inv->actor})",
                    'request_id' => $inv->request_id,
                    'ref' => 'function:'.$inv->function_id.':'.$inv->id,
                ];
            }
        }
        if ($want('webhooks')) {
            foreach (WebhookDelivery::query()
                ->whereHas('webhook', fn ($wq) => $wq->where('project_id', $project->id))
                ->orderByDesc('id')->limit(100)->get() as $d) {
                $rows[] = [
                    'time' => (string) $d->created_at,
                    'source' => 'webhooks',
                    'severity' => $d->status === 'delivered' ? 'info' : ($d->status === 'exhausted' ? 'error' : 'warning'),
                    'summary' => "{$d->event} → {$d->status} (HTTP ".($d->http_code ?? '—').", {$d->attempts} attempt(s))",
                    'request_id' => $d->request_id,
                    'ref' => 'webhook:'.$d->webhook_id.':'.$d->id,
                ];
            }
        }
        if ($want('scheduler')) {
            foreach (TaskRun::query()
                ->whereHas('task', fn ($tq) => $tq->where('project_id', $project->id))
                ->orderByDesc('id')->limit(100)->get() as $run) {
                $rows[] = [
                    'time' => (string) $run->created_at,
                    'source' => 'scheduler',
                    'severity' => $run->status === 'ok' ? 'info' : 'error',
                    'summary' => "task#{$run->task_id} {$run->status} in {$run->duration_ms}ms",
                    'request_id' => $run->request_id,
                    'ref' => 'task:'.$run->task_id.':'.$run->id,
                ];
            }
        }
        if ($want('realtime')) {
            foreach (RealtimeEvent::query()->where('project_id', $project->id)
                ->orderByDesc('id')->limit(100)->get() as $e) {
                $rows[] = [
                    'time' => (string) $e->created_at,
                    'source' => 'realtime',
                    'severity' => 'info',
                    'summary' => "{$e->channel} · {$e->event}".($e->verified ? ' (verified)' : ''),
                    'request_id' => $e->request_id,
                    'ref' => 'realtime:'.$e->id,
                ];
            }
        }
        if ($want('sql')) {
            foreach (SqlQueryHistory::query()->where('project_id', $project->id)
                ->orderByDesc('id')->limit(100)->get() as $h) {
                $rows[] = [
                    'time' => (string) $h->created_at,
                    'source' => 'sql',
                    'severity' => $h->status === 'ok' ? 'info' : ($h->status === 'blocked' ? 'warning' : 'error'),
                    'summary' => "[{$h->category}] {$h->redacted_sql} ({$h->duration_ms}ms)",
                    'request_id' => null,
                    'ref' => 'sql:'.$h->id,
                ];
            }
        }
        if ($want('backups')) {
            foreach (BackupRecord::query()->where('db_name', $project->db_name)
                ->orderByDesc('id')->limit(20)->get() as $b) {
                $rows[] = [
                    'time' => (string) ($b->finished_at ?? $b->created_at),
                    'source' => 'backups',
                    'severity' => $b->status === 'ok' || $b->status === 'verified' ? 'info' : 'warning',
                    'summary' => "backup {$b->status} ({$b->type}, ".($b->size_bytes ? ProjectOverviewData::bytes((int) $b->size_bytes) : '—').')',
                    'request_id' => null,
                    'ref' => 'backup:'.$b->id,
                ];
            }
        }
        if ($want('queue')) {
            foreach (self::failedJobRows($project) as $row) {
                $rows[] = $row;
            }
        }

        // Cross-cutting filters. History is never deleted here — `since` only narrows the view.
        if ($cutoff > 0) {
            $rows = array_values(array_filter($rows, function ($r) use ($cutoff) {
                $ts = strtotime((string) ($r['time'] ?? ''));
                // Keep rows with unparseable timestamps rather than hiding history.
                return $ts === false || $ts >= $cutoff;
            }));
        }
        if ($requestId !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => ($r['request_id'] ?? '') === $requestId));
        }
        if ($q !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['summary'].' '.$r['source']), $q)));
        }
        usort($rows, fn ($a, $b) => strcmp($b['time'], $a['time']));

        return ['rows' => array_slice($rows, 0, $limit), 'truncated' => count($rows) > $limit];
    }

    /** @return list<array> */
    protected static function laravelRows(Project $project, string $severity, string $q): array
    {
        $rows = [];
        foreach (['owner-console', $project->slug] as $idx => $slug) {
            $reader = $idx === 0
                ? ProjectLogReader::for('owner-console')
                : ProjectLogReader::for($project);
            $entries = $reader->entries(null, $severity !== '' && $severity !== 'all' ? $severity : null, $q !== '' ? $q : null, 1, 100);
            foreach ($entries['entries'] as $e) {
                $rows[] = [
                    'time' => (string) ($e['datetime'] ?? ''),
                    'source' => 'laravel',
                    'severity' => strtolower($e['severity']),
                    'summary' => '['.$slug.'] '.mb_substr($e['message'], 0, 220),
                    'request_id' => self::extractRequestId($e['message']),
                    'ref' => 'laravel:'.$slug,
                ];
            }
        }

        return $rows;
    }

    /** @return list<array> */
    protected static function auditRows(Project $project, ?string $only = null): array
    {
        $rows = [];
        $query = AdminAuditEntry::query()->where('project_id', $project->id)->orderByDesc('id')->limit(100)->get();
        foreach ($query as $e) {
            $isAuth = str_starts_with($e->action, 'USER_') || str_starts_with($e->action, 'TOKEN_')
                || str_starts_with($e->action, 'SESSION_') || str_starts_with($e->action, 'ROLE_')
                || str_starts_with($e->action, 'PERMISSION_');
            $source = $isAuth ? 'auth' : 'audit';
            if ($only && $source !== $only) {
                continue;
            }
            $meta = $e->metadata ?? [];
            $rows[] = [
                'time' => (string) $e->created_at,
                'source' => $source,
                'severity' => str_contains($e->action, 'DELETED') || str_contains($e->action, 'REVOKED') || str_contains($e->action, 'DISABLED') ? 'warning' : 'info',
                'summary' => $e->action.($e->target_type ? " · {$e->target_type}".($e->target_id ? " #{$e->target_id}" : '') : ''),
                'request_id' => $meta['request_id'] ?? null,
                'ref' => 'audit:'.$e->id,
            ];
        }

        return $rows;
    }

    /** @return list<array> */
    protected static function failedJobRows(Project $project): array
    {
        try {
            $explorer = ProjectDatabaseExplorer::for($project);
            $names = array_column($explorer->tables(), 'name');
            if (! in_array('failed_jobs', $names, true)) {
                return [];
            }
            $rows = [];
            foreach (
                $explorer->query('failed_jobs')->orderByDesc('failed_at')->limit(50)->get() as $job
            ) {
                $rows[] = [
                    'time' => (string) ($job->failed_at ?? ''),
                    'source' => 'queue',
                    'severity' => 'error',
                    'summary' => 'failed job: '.mb_substr((string) ($job->queue ?? $job->uuid ?? ''), 0, 120),
                    'request_id' => null,
                    'ref' => 'queue:'.($job->uuid ?? $job->id),
                ];
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function extractRequestId(string $message): ?string
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $message, $m)) {
            return strtolower($m[0]);
        }

        return null;
    }

    /** Full detail for one ref (drawer content). Redacted by construction. */
    public static function detail(Project $project, string $ref): ?array
    {
        [$kind, $a, $b] = array_pad(explode(':', $ref, 3), 3, null);
        try {
            return match ($kind) {
                'function' => self::modelDetail(
                    \App\Models\FunctionInvocation::query()->findOrFail((int) $b),
                    ['function_id', 'version', 'request_id', 'actor', 'status', 'duration_ms', 'request', 'response', 'error']
                ),
                'webhook' => self::modelDetail(
                    WebhookDelivery::query()->findOrFail((int) $b),
                    ['webhook_id', 'request_id', 'event', 'status', 'http_code', 'duration_ms', 'attempts', 'payload', 'response', 'next_retry_at']
                ),
                'task' => self::modelDetail(
                    \App\Models\TaskRun::query()->findOrFail((int) $b),
                    ['task_id', 'request_id', 'status', 'duration_ms', 'output', 'error']
                ),
                'sql' => self::modelDetail(
                    SqlQueryHistory::query()->where('project_id', $project->id)->findOrFail((int) $a),
                    ['category', 'status', 'duration_ms', 'rows', 'redacted_sql', 'error']
                ),
                'audit' => self::modelDetail(
                    AdminAuditEntry::query()->where('project_id', $project->id)->findOrFail((int) $a),
                    ['action', 'target_type', 'target_id', 'ip', 'metadata', 'created_at']
                ),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function modelDetail($model, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $model->getAttribute($k);
        }

        return $out;
    }
}
