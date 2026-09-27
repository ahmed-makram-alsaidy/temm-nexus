<?php

namespace App\Services\ControlPlane;

use App\Models\FunctionInvocation;
use App\Models\FunctionVersion;
use App\Models\ProjectFunction;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 20H Server Functions runtime.
 *
 * Architecture decision (documented, honest): NO arbitrary code execution.
 * There is no isolated sandbox on this single VPS (no Docker socket, no extra
 * runtime, no system software per safety rules), so functions are versioned
 * modules executed by audited, bounded, in-process executors:
 *   static    — fixed status + JSON body with {{input.*}}/{{secrets.*}} slots
 *   db_lookup — parameterized read-only SELECT on an allowlisted table/columns
 *   transform — pure field pick/rename/map over the input JSON
 * Every invocation is logged (redacted, capped). Adding a real sandbox later
 * does not change the version/test/deploy/log contract.
 */
class FunctionRunner
{
    public const LOG_CAP = 4000;

    public static function types(): array
    {
        return ['static' => 'Static response', 'db_lookup' => 'Database lookup', 'transform' => 'JSON transform'];
    }

    /**
     * @param array{method:string,query:array,headers:array,body:mixed} $input
     * @return array{status:int,body:mixed,duration_ms:int,version:int,request_id:string,error:?string}
     */
    public static function invoke(
        ProjectFunction $function,
        array $input,
        string $actor = 'anonymous',
        ?string $requestId = null
    ): array {
        $requestId ??= SqlRunner::requestId();
        $started = microtime(true);
        $project = $function->project;

        $fail = function (int $status, string $error, array $extra = []) use ($function, $input, $actor, $requestId, $started, $project) {
            $duration = (int) ((microtime(true) - $started) * 1000);
            self::log($function, 0, $requestId, $actor, $status, $duration, $input, null, $error, $project);

            return array_merge([
                'status' => $status, 'body' => ['error' => $error],
                'duration_ms' => $duration, 'version' => 0,
                'request_id' => $requestId, 'error' => $error,
            ], $extra);
        };

        if (! $function->enabled) {
            return $fail(503, 'Function is disabled.');
        }
        $methods = array_map('strtoupper', $function->methods ?? ['GET']);
        if (! in_array(strtoupper($input['method'] ?? 'GET'), $methods, true)) {
            return $fail(405, 'Method not allowed for this function.');
        }
        if (! self::rateOk($function)) {
            return $fail(429, 'Rate limit exceeded.');
        }
        $version = FunctionVersion::query()
            ->where('function_id', $function->id)
            ->where('id', $function->current_version_id)
            ->first();
        if (! $version) {
            return $fail(503, 'Function has no deployed version.');
        }

        try {
            $config = $version->config ?? [];
            $secrets = SecretService::valuesFor($project, self::referencedSecrets($config));
            $result = match ($function->type) {
                'static' => self::runStatic($config, $input, $secrets),
                'db_lookup' => self::runDbLookup($project, $config, $input),
                'transform' => self::runTransform($config, $input),
                default => ['status' => 500, 'body' => ['error' => 'Unknown executor.']],
            };
        } catch (\Throwable $e) {
            return $fail(500, 'Executor error.');
        }

        $status = (int) ($result['status'] ?? 200);
        $duration = (int) ((microtime(true) - $started) * 1000);
        self::log($function, $version->version, $requestId, $actor, $status, $duration, $input, $result['body'] ?? null, null, $project);

        return [
            'status' => $status, 'body' => $result['body'] ?? null,
            'duration_ms' => $duration, 'version' => $version->version,
            'request_id' => $requestId, 'error' => null,
        ];
    }

    protected static function rateOk(ProjectFunction $function): bool
    {
        $limit = max(1, (int) ($function->rate_limit_per_min ?: 60));
        $key = 'fn_rate:'.$function->id.':'.now()->format('YmdHi');
        $hits = (int) cache()->increment($key);
        if ($hits === 1) {
            cache()->put($key, 1, 70);
        }

        return $hits <= $limit;
    }

    /** @return list<string> */
    protected static function referencedSecrets(array $config): array
    {
        preg_match_all('/\{\{\s*secrets\.([A-Za-z0-9_]+)\s*\}\}/', json_encode($config), $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /** @param array<string,string> $secrets */
    protected static function interpolate(mixed $value, array $input, array $secrets): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::interpolate($v, $input, $secrets);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\{\{\s*(input|secrets)\.([A-Za-z0-9_.]+)\s*\}\}/', function ($m) use ($input, $secrets) {
            if ($m[1] === 'secrets') {
                return (string) ($secrets[$m[2]] ?? '');
            }
            $parts = explode('.', $m[2]);
            $cur = array_merge(['method' => $input['method'] ?? 'GET'], $input['query'] ?? [], is_array($input['body'] ?? null) ? $input['body'] : []);
            foreach ($parts as $part) {
                if (! is_array($cur) || ! array_key_exists($part, $cur)) {
                    return '';
                }
                $cur = $cur[$part];
            }

            return is_scalar($cur) ? (string) $cur : json_encode($cur);
        }, $value);
    }

    protected static function runStatic(array $config, array $input, array $secrets): array
    {
        $status = (int) ($config['status'] ?? 200);
        abort_if($status < 200 || $status > 599, 500);
        $body = $config['body'] ?? ['ok' => true];
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : ['text' => $body];
        }

        return ['status' => $status, 'body' => self::interpolate($body, $input, $secrets)];
    }

    protected static function runDbLookup($project, array $config, array $input): array
    {
        $explorer = ProjectDatabaseExplorer::for($project);
        $table = $explorer->assertTable((string) ($config['table'] ?? ''));
        $available = array_column($explorer->columns($table), 'name');
        $columns = array_values(array_intersect((array) ($config['columns'] ?? []), $available));
        abort_if($columns === [], 422, 'No valid columns configured.');
        $keyColumn = (string) ($config['key_column'] ?? '');
        abort_unless(in_array($keyColumn, $available, true), 422, 'Invalid key column.');
        $keyValue = ltrim((string) ($config['key_from'] ?? 'query.key'), '.');
        // interpolate() flattens query params to the input top level, so the
        // documented 'query.key' path resolves to '' — fall back to the
        // flattened key (generic fix: applies to every project's db_lookup).
        $value = self::interpolate('{{input.'.$keyValue.'}}', $input, []);
        if ($value === '' && str_starts_with($keyValue, 'query.')) {
            $value = self::interpolate('{{input.'.substr($keyValue, 6).'}}', $input, []);
        }
        $limit = min(100, max(1, (int) ($config['limit'] ?? 10)));

        $rows = $explorer->query($table)->select($columns)->where($keyColumn, $value)->limit($limit)->get();

        return ['status' => 200, 'body' => ['data' => SqlRunner::rowsToArrays($rows->all())]];
    }

    protected static function runTransform(array $config, array $input): array
    {
        $body = $input['body'] ?? [];
        if (! is_array($body)) {
            $decoded = is_string($body) ? json_decode($body, true) : null;
            $body = is_array($decoded) ? $decoded : ['value' => $body];
        }
        $out = [];
        foreach ((array) ($config['pick'] ?? []) as $field) {
            if (is_string($field) && array_key_exists($field, $body)) {
                $out[$field] = $body[$field];
            }
        }
        foreach ((array) ($config['rename'] ?? []) as $from => $to) {
            if (is_string($to) && array_key_exists($from, $body)) {
                $out[$to] = $body[$from];
            }
        }
        if (($config['passthrough'] ?? false) && $out === []) {
            $out = $body;
        }

        return ['status' => 200, 'body' => $out];
    }

    protected static function log(
        ProjectFunction $function, int $version, string $requestId, string $actor,
        int $status, int $duration, array $input, mixed $response, ?string $error, $project
    ): void {
        $safeHeaders = $input['headers'] ?? [];
        unset($safeHeaders['authorization'], $safeHeaders['cookie'], $safeHeaders['x-api-key']);
        $request = ['method' => $input['method'] ?? 'GET', 'query' => $input['query'] ?? [], 'body' => $input['body'] ?? null];
        $requestJson = mb_substr(json_encode($request) ?: '{}', 0, self::LOG_CAP);
        $responseJson = mb_substr(json_encode($response) ?: 'null', 0, self::LOG_CAP);
        // Never persist secret values: redact after render.
        $requestJson = (string) SecretService::redact($project, $requestJson);
        $responseJson = (string) SecretService::redact($project, $responseJson);

        FunctionInvocation::create([
            'function_id' => $function->id, 'version' => $version, 'request_id' => $requestId,
            'actor' => mb_substr($actor, 0, 160), 'status' => $status, 'duration_ms' => $duration,
            'request' => json_decode($requestJson, true) ?? ['raw' => $requestJson],
            'response' => json_decode($responseJson, true) ?? ['raw' => $responseJson],
            'error' => $error ? mb_substr($error, 0, 500) : null,
        ]);
    }

    public static function deploy(ProjectFunction $function, array $config, string $type): FunctionVersion
    {
        $version = (int) (FunctionVersion::query()->where('function_id', $function->id)->max('version') ?? 0) + 1;
        $record = FunctionVersion::create([
            'function_id' => $function->id, 'version' => $version,
            'config' => $config, 'deployed_by' => Auth::id(),
        ]);
        $function->forceFill(['current_version_id' => $record->id, 'type' => $type])->save();
        AdminAudit::record('FUNCTION_DEPLOYED', $function->project, 'function', $function->id, [
            'slug' => $function->slug, 'version' => $version, 'type' => $type,
        ]);

        return $record;
    }

    public static function rollback(ProjectFunction $function, int $version): void
    {
        $record = FunctionVersion::query()
            ->where('function_id', $function->id)->where('version', $version)->firstOrFail();
        $function->forceFill(['current_version_id' => $record->id])->save();
        AdminAudit::record('FUNCTION_ROLLED_BACK', $function->project, 'function', $function->id, [
            'slug' => $function->slug, 'version' => $version,
        ]);
    }
}
