<?php

namespace App\Services\ControlPlane\Connectors\Support;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\SecretService;
use Illuminate\Support\Str;

/**
 * Phase 27S/27S.1 — structured, secret-redacting connector logging.
 *
 * Every connector operation logs through this service so records carry the
 * full context (project, environment, connector key/version, operation, run
 * id) and NEVER carry secret material: known vault values are redacted via
 * the project vault, sensitive-looking context keys are masked, and control
 * characters are stripped (log injection, 27T).
 */
class ConnectorLogger
{
    /** Log a connector operation. */
    public static function operation(MigrationSource $source, string $operation, array $context = []): void
    {
        $connector = null;
        try {
            $connector = ConnectorRegistry::instance()->connectorForSource($source);
        } catch (\Throwable) {
            $connector = null;
        }

        $base = [
            'project_id' => $source->project_id,
            'environment_id' => $source->environment_id,
            'migration_source_id' => $source->id,
            'connector_key' => $connector?->manifest()->key() ?? $source->effectiveConnectorKey(),
            'connector_version' => $connector?->manifest()->version(),
            'operation' => self::sanitize((string) $operation),
            'run_id' => self::sanitize((string) ($context['run_id'] ?? '')) ?: null,
        ];
        unset($context['run_id']);

        self::write('info', 'connector operation: '.self::sanitize($operation), $base + self::sanitizeContext($source, $context));
    }

    /** Log a connector-level failure (classification without stack traces to operators). */
    public static function failure(MigrationSource $source, string $operation, \Throwable $e): void
    {
        self::operation($source, $operation.'.failed', [
            'error_class' => class_basename($e),
            'error' => Str::limit($e->getMessage(), 300),
        ]);
    }

    protected static function write(string $level, string $message, array $context): void
    {
        $channel = config('connectors.logging_channel');
        $logger = $channel && config("logging.channels.{$channel}") !== null
            ? logger()->channel($channel)
            : logger();
        $logger->{$level}($message, $context);
    }

    /** Strip control characters/newlines so records cannot forge log lines. */
    public static function sanitize(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $value));
    }

    /** Redact vault values + mask sensitive-looking keys. */
    protected static function sanitizeContext(MigrationSource $source, array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $value = self::sanitize($value);
                if (preg_match('/pass|secret|token|pat\b|key|credential/i', (string) $key)) {
                    $value = '***';
                }
            }
            $out[$key] = $value;
        }
        $redacted = SecretService::redact($source->project, json_encode($out, JSON_UNESCAPED_UNICODE));

        return (array) json_decode((string) $redacted, true);
    }
}
