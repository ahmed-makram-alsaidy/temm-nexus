<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 20P project webhooks: signed delivery, retry with backoff, full log.
 * SSRF protection: only http(s), explicit host allowlist
 * (console.test, caddy, the project's own api_domain); everything else must
 * resolve to a public IP — localhost, private ranges, metadata endpoints and
 * raw IPs are refused. Timeouts + response caps enforced.
 */
class WebhookService
{
    public const EVENTS = [
        'user.created', 'user.updated', 'order.created', 'order.updated',
        'file.uploaded', 'function.invoked', 'task.completed', 'backup.completed',
        'custom.ping',
    ];

    public const BACKOFF_MINUTES = [1, 5, 15, 60, 180];

    public static function allowedHosts(Project $project): array
    {
        $hosts = ['console.test', 'caddy'];
        if ($project->api_domain) {
            $hosts[] = strtolower($project->api_domain);
        }

        return $hosts;
    }

    public static function validateUrl(Project $project, string $url): string
    {
        $parts = parse_url($url);
        abort_unless($parts && isset($parts['host']), 422, 'Invalid URL.');
        abort_unless(in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true), 422, 'Only http(s) URLs.');
        $host = strtolower($parts['host']);
        if (! in_array($host, self::allowedHosts($project), true)) {
            abort_if(preg_match('/^\d+\.\d+\.\d+\.\d+$|^[\da-f:]+$/i', $host) === 1, 403, 'Raw IP targets are not allowed.');
            $ip = gethostbyname($host);
            abort_if($ip === $host, 422, 'Host does not resolve.');
            abort_if(! self::isPublicIp($ip), 403, 'Private/internal targets are not allowed (SSRF guard).');
        }

        return $url;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return WebhookDelivery */
    public static function dispatch(ProjectWebhook $webhook, string $event, array $payload, ?string $requestId = null)
    {
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'request_id' => $requestId ?? SqlRunner::requestId(),
            'event' => $event,
            'status' => 'pending',
            'payload' => json_decode(mb_substr(json_encode($payload) ?: '{}', 0, 4000), true),
        ]);
        self::attempt($delivery->fresh());

        return $delivery->fresh();
    }

    public static function attempt(WebhookDelivery $delivery): void
    {
        $webhook = $delivery->webhook ?? ProjectWebhook::find($delivery->webhook_id);
        abort_unless($webhook, 404);
        $started = microtime(true);
        $attempts = $delivery->attempts + 1;
        $body = json_encode([
            'event' => $delivery->event,
            'request_id' => $delivery->request_id,
            'project' => $webhook->project->slug,
            'payload' => $delivery->payload,
        ]);
        $timestamp = (string) time();
        $secret = $webhook->signing_secret;
        // Local platform targets route through the proxy with a Host override
        // (Caddy only serves the console.test vhost); external project
        // domains go direct. Re-validated here so stored URLs can't drift.
        $target = self::validateUrl($webhook->project, $webhook->url);
        $parts = parse_url($target);
        $extraHeaders = [];
        if (in_array(strtolower($parts['host']), ['caddy', 'console.test'], true)) {
            $port = isset($parts['port']) ? ':'.(int) $parts['port'] : ':80';
            $target = 'http://caddy'.$port.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            $extraHeaders['Host'] = 'console.test';
        }
        $status = 'failed';
        $code = null;
        $responseText = '';
        try {
            $response = Http::timeout($webhook->timeout_s ?: 10)
                // SSRF hardening: never follow redirects (a public URL must not
                // bounce delivery into private ranges).
                ->withoutRedirecting()
                ->withHeaders(array_merge([
                    'Content-Type' => 'application/json',
                    'X-CP-Event' => $delivery->event,
                    'X-CP-Timestamp' => $timestamp,
                    'X-CP-Signature' => 'sha256='.self::sign($secret, $timestamp, $body),
                    'X-CP-Request-ID' => $delivery->request_id,
                ], $extraHeaders))
                ->withBody($body, 'application/json')
                ->post($target);
            $code = $response->status();
            $responseText = mb_substr($response->body(), 0, 2000);
            $status = ($code >= 200 && $code < 300) ? 'delivered' : 'failed';
        } catch (\Throwable $e) {
            $responseText = mb_substr($e->getMessage(), 0, 300);
        }

        $duration = (int) ((microtime(true) - $started) * 1000);
        $retryable = $status === 'failed' && ($code === null || $code >= 500);
        $maxAttempts = max(1, (int) ($webhook->max_attempts ?: 5));
        if ($status === 'delivered') {
            $delivery->forceFill([
                'status' => 'delivered', 'http_code' => $code, 'duration_ms' => $duration,
                'attempts' => $attempts, 'response' => $responseText, 'next_retry_at' => null,
            ])->save();
            AdminAudit::record('WEBHOOK_DELIVERED', $webhook->project, 'webhook', $webhook->id, [
                'event' => $delivery->event, 'attempts' => $attempts,
                'request_id' => $delivery->request_id,
            ]);
        } elseif ($retryable && $attempts < $maxAttempts) {
            $backoff = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
            $delivery->forceFill([
                'status' => 'failed', 'http_code' => $code, 'duration_ms' => $duration,
                'attempts' => $attempts, 'response' => $responseText,
                'next_retry_at' => now()->addMinutes($backoff),
            ])->save();
        } else {
            $delivery->forceFill([
                'status' => 'exhausted', 'http_code' => $code, 'duration_ms' => $duration,
                'attempts' => $attempts, 'response' => $responseText, 'next_retry_at' => null,
            ])->save();
        }
    }

    /** Retry sweep (console scheduler, every minute). */
    public static function processRetries(): int
    {
        $count = 0;
        foreach (
            WebhookDelivery::query()->where('status', 'failed')
                ->whereNotNull('next_retry_at')
                ->where('next_retry_at', '<=', now())
                ->limit(25)->get() as $delivery
        ) {
            try {
                self::attempt($delivery);
                $count++;
            } catch (\Throwable) {
            }
        }

        return $count;
    }

    public static function redactPayload(array $payload): array
    {
        array_walk_recursive($payload, function (&$v, $k) {
            if (is_string($v) && preg_match('/secret|token|password|key/i', (string) $k)) {
                $v = '***';
            }
        });

        return $payload;
    }
}
