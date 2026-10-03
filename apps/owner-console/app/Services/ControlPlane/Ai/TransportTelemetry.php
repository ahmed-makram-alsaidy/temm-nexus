<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0-rc.7 — SAFE wire-level telemetry for AI provider calls.
 *
 * Answers "what did we actually put on the wire" without ever becoming a
 * leak channel. Every entry records:
 *
 *   - final URL, HTTP method
 *   - the effective request-header map (header NAMES always; header VALUES
 *     only for User-Agent / Content-Type / Accept — everything else, and the
 *     Authorization header specifically, is logged as [redacted]/[protected])
 *   - the effective User-Agent exactly as it left the Laravel layer
 *   - the request JSON FIELD NAMES and the `stream` value — never field values
 *
 * On the response side: status code, Content-Type and — for non-2xx only — a
 * short body preview with the configured secret redacted. Telemetry failures
 * are swallowed: logging can never break a provider call.
 *
 * Enable/disable and channel: config('nexus-ai.telemetry') (NEXUS_AI_TELEMETRY,
 * NEXUS_AI_TELEMETRY_CHANNEL).
 */
class TransportTelemetry
{
    /** Header values that are safe to log verbatim. All others are redacted. */
    protected const VISIBLE_HEADER_VALUES = ['user-agent', 'content-type', 'accept'];

    /**
     * @param  array<string, mixed>  $wireHeaders  the header map as Laravel
     *      will hand it to Guzzle (merge order preserved)
     * @param  array<string, mixed>  $payload  the exact JSON body about to be sent
     */
    public static function outgoing(AiProviderConfig $config, string $kind, string $url, string $method, array $wireHeaders, array $payload): void
    {
        static::log($config, 'ai_transport.request', [
            'kind' => $kind,
            'url' => $url,
            'method' => $method,
            'user_agent' => static::effectiveUserAgent($wireHeaders),
            'request_headers' => static::safeHeaderMap($wireHeaders),
            'request_json_fields' => array_values(array_map('strval', array_keys($payload))),
            'stream' => array_key_exists('stream', $payload) ? $payload['stream'] : '(not sent)',
            'model' => (string) $config->model,
        ]);
    }

    public static function incoming(AiProviderConfig $config, string $kind, int $status, ?string $contentType, ?string $diagnosticBody): void
    {
        $preview = null;
        if ($status >= 400 && $diagnosticBody !== null && $diagnosticBody !== '') {
            $preview = static::redact(
                $config,
                mb_substr($diagnosticBody, 0, (int) config('nexus-ai.telemetry.body_log_bytes', 300))
            );
        }

        static::log($config, 'ai_transport.response', [
            'kind' => $kind,
            'status' => $status,
            'content_type' => $contentType,
            'body_preview' => $preview,
        ]);
    }

    public static function connectionFailure(AiProviderConfig $config, string $kind, string $exceptionClass, string $message): void
    {
        static::log($config, 'ai_transport.connection_failure', [
            'kind' => $kind,
            'exception' => $exceptionClass,
            'message' => static::redact($config, mb_substr($message, 0, 300)),
        ]);
    }

    /**
     * The effective User-Agent from the final header map. "(guzzle default)"
     * means Laravel carried no explicit UA and Guzzle will stamp its own —
     * which is exactly the failure mode gateways like AgentRouter refuse.
     */
    public static function effectiveUserAgent(array $wireHeaders): string
    {
        foreach ($wireHeaders as $name => $value) {
            if (strtolower((string) $name) === 'user-agent') {
                return is_array($value) ? (string) (reset($value)) : (string) $value;
            }
        }

        return '(guzzle default)';
    }

    /**
     * Header map safe for logging: names always, values only for the
     * visibility whitelist. Authorization is always [protected]; every other
     * custom header value is [redacted].
     *
     * @return array<string, string>
     */
    public static function safeHeaderMap(array $wireHeaders): array
    {
        $safe = [];

        foreach ($wireHeaders as $name => $value) {
            $name = (string) $name;
            $lower = strtolower($name);

            if ($lower === 'authorization') {
                $safe[$name] = '[protected]';

                continue;
            }

            $safe[$name] = in_array($lower, self::VISIBLE_HEADER_VALUES, true)
                ? (is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value)
                : '[redacted]';
        }

        return $safe;
    }

    /** Remove the configured secret from any diagnostic text. */
    public static function redact(AiProviderConfig $config, string $text): string
    {
        $secret = (string) ($config->secret_encrypted ?? '');

        return $secret !== '' && str_contains($text, $secret)
            ? str_replace($secret, '[redacted]', $text)
            : $text;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected static function log(AiProviderConfig $config, string $event, array $context): void
    {
        if (! config('nexus-ai.telemetry.enabled', true)) {
            return;
        }

        try {
            $channelName = (string) config('nexus-ai.telemetry.channel', 'nexus-ai');
            $channel = config('logging.channels.'.$channelName) !== null
                ? $channelName
                : 'null';
            Log::channel($channel)->info($event, $context + ['provider_id' => $config->getKey()]);
        } catch (\Throwable) {
            // Telemetry must never break the wire call it observes.
        }
    }
}
