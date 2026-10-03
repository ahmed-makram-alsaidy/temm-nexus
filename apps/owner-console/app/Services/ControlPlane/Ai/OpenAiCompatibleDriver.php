<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat-completions format driver. Serves openai, openrouter and
 * openai_compatible (base_url decides the endpoint) — the three share one
 * stable wire format.
 *
 * 0.4.0-rc.7 — AgentRouter / gateway compatibility:
 *   - Custom HTTP headers (`ai_provider_configs.custom_headers`, encrypted at
 *     rest) are sent on EVERY request. `complete()` and `test()` build the
 *     client through ONE method, so Test Connection exercises the exact same
 *     HTTP configuration as real chat — it can never pass with one setup and
 *     chat with another.
 *   - The header map is strictly validated (name token, no CR/LF, value
 *     length) and Authorization / Host / Content-Length / Cookie are refused
 *     here at the transport boundary regardless of stored state — the token
 *     set via withToken() is always the only credential carrier.
 *   - Failures throw AiProviderException with a SAFE classified message.
 *     A non-2xx, a 200-with-error-envelope, or an empty completion can never
 *     produce a blank assistant bubble or a fake successful reply.
 *   - Reasoning content (`reasoning_content`, DeepSeek-style) is never used
 *     as the final answer; only choices[0].message.content is.
 *
 * 0.4.0-rc.7 — transport parity hardening + safe telemetry:
 *   - Header merge order is explicit: framework defaults first, then custom
 *     provider headers LAST, then the protected credential re-asserted. A
 *     gateway that identifies clients by User-Agent (AgentRouter) receives
 *     the configured header verbatim — verified at the raw-socket level by
 *     tests/Feature/Phase42/TransportTelemetryTest.php.
 *   - Every wire call carries `"stream": false` explicitly, so the request
 *     shape is identical across providers regardless of their defaults.
 *   - Every wire call is observed by TransportTelemetry (URL, method, status,
 *     Content-Type, effective User-Agent, request JSON field names, stream
 *     value) with Authorization and the API key structurally excluded.
 *   - The Test Connection probe can no longer misclassify a starved
 *     reasoning model: `max_tokens` is a small sane budget (not 1) and a
 *     200 that ends in `finish_reason: "length"` still proves auth, model
 *     and a healthy completion pipeline → CONNECTED.
 */
class OpenAiCompatibleDriver implements AiDriver
{
    public const RESULT_CONNECTED = 'CONNECTED';

    public const RESULT_AUTH_FAILED = 'AUTH_FAILED';

    public const RESULT_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';

    public const RESULT_RATE_LIMITED = 'RATE_LIMITED';

    public const RESULT_TIMEOUT = 'TIMEOUT';

    public const RESULT_PROVIDER_ERROR = 'PROVIDER_ERROR';

    /** Headers user configuration may never override (case-insensitive). */
    public const FORBIDDEN_CUSTOM_HEADERS = ['authorization', 'host', 'content-length', 'cookie'];

    public function id(): string
    {
        return 'openai';
    }

    protected function endpoint(AiProviderConfig $config): string
    {
        $base = rtrim($config->base_url ?: $this->defaultBase(), '/');
        if (! str_ends_with($base, '/v1') && str_contains($base, 'openai.com')) {
            $base .= '/v1';
        }

        return $base.'/chat/completions';
    }

    protected function defaultBase(): string
    {
        return 'https://api.openai.com/v1';
    }

    /**
     * ONE client builder for every wire call — complete() and test() both go
     * through this, which is what guarantees Test Connection parity.
     *
     * Merge order (the order is the contract, verified by regression):
     *   1. framework defaults (timeout, Accept: application/json)
     *   2. validated custom provider headers — LAST, so a provider that
     *      identifies clients by User-Agent gets the configured value and
     *      custom headers always win over framework defaults
     *   3. protected headers re-asserted — the vault credential is applied
     *      after the custom merge and can never be displaced by stored state
     */
    protected function client(AiProviderConfig $config, ?int $timeout = null): PendingRequest
    {
        $pending = Http::timeout($timeout ?? $config->timeout_seconds)
            ->accept('application/json')
            ->withHeaders($this->safeCustomHeaders($config));

        return $pending->withToken((string) ($config->secret_encrypted ?? ''));
    }

    /**
     * Validated custom headers. Stored values are trusted only after this
     * filter: forbidden names, malformed names and CR/LF injection never
     * reach the wire.
     *
     * @return array<string, string>
     */
    public function safeCustomHeaders(AiProviderConfig $config): array
    {
        $headers = [];

        foreach ((array) ($config->custom_headers ?? []) as $name => $value) {
            $name = trim((string) $name);
            $value = (string) $value;

            if ($name === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $name)) {
                continue;
            }
            if (in_array(strtolower($name), self::FORBIDDEN_CUSTOM_HEADERS, true)) {
                continue;
            }
            if ($value === '' || strlen($value) > 512 || preg_match('/[\r\n]/', $value)) {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        $payload = [
            'model' => $config->model,
            'messages' => $messages,
            'max_tokens' => $options['max_output_tokens'] ?? $config->max_output_tokens,
            'stream' => false,
        ];
        $url = $this->endpoint($config);
        $pending = $this->client($config);
        TransportTelemetry::outgoing($config, 'chat', $url, 'POST', $pending->getOptions()['headers'] ?? [], $payload);

        try {
            $response = $pending->post($url, $payload);
        } catch (ConnectionException $e) {
            TransportTelemetry::connectionFailure($config, 'chat', get_class($e), $e->getMessage());

            throw $e;
        }

        TransportTelemetry::incoming(
            $config,
            'chat',
            $response->status(),
            $this->responseContentType($response),
            $response->status() >= 400 ? (string) $response->body() : null
        );

        if ($response->status() !== 200) {
            throw new AiProviderException(
                $this->failureMessage($response->status(), (string) $response->body()),
                $response->status()
            );
        }

        $body = $response->json();

        // Some gateways answer 200 with an error envelope — never success.
        if (! is_array($body) || isset($body['error'])) {
            throw new AiProviderException(
                $this->failureMessage(200, is_array($body) ? json_encode($body) : ''),
                200
            );
        }

        // Standard extraction only: choices[0].message.content. Reasoning
        // content and other metadata are never promoted to the answer.
        $content = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new AiProviderException(
                'The AI provider returned an empty response. Verify the model in Settings → Nexus AI and try again.',
                200
            );
        }

        return [
            'text' => $content,
            'usage' => [
                'input_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    public function test(AiProviderConfig $config): string
    {
        // A 1-token probe starves reasoning-style models: they burn the whole
        // budget on reasoning and answer 200 with EMPTY content, which used to
        // be misclassified as a provider error. A small sane budget plus the
        // finish_reason check below keeps the probe honest without that trap.
        $payload = [
            'model' => $config->model,
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => max(1, min((int) ($config->max_output_tokens ?: 32), 32)),
            'stream' => false,
        ];
        $url = $this->endpoint($config);
        $pending = $this->client($config, min($config->timeout_seconds, 20));
        TransportTelemetry::outgoing($config, 'test_connection', $url, 'POST', $pending->getOptions()['headers'] ?? [], $payload);

        try {
            $response = $pending->post($url, $payload);
        } catch (ConnectionException $e) {
            TransportTelemetry::connectionFailure($config, 'test_connection', get_class($e), $e->getMessage());

            return str_contains(strtolower($e->getMessage()), 'timed out') ? self::RESULT_TIMEOUT : self::RESULT_PROVIDER_ERROR;
        }

        TransportTelemetry::incoming(
            $config,
            'test_connection',
            $response->status(),
            $this->responseContentType($response),
            (string) $response->body()
        );

        if ($response->status() === 200) {
            $body = $response->json();

            if (is_array($body) && ! isset($body['error']) && is_array($body['choices'][0] ?? null)) {
                $choice = $body['choices'][0];
                $content = trim((string) ($choice['message']['content'] ?? ''));
                $finish = strtolower((string) ($choice['finish_reason'] ?? ''));

                // Empty content with finish_reason "length" means the model
                // RAN and the probe budget cut it off — auth, model and the
                // completion pipeline are all proven. That is CONNECTED.
                if ($content !== '' || $finish === 'length') {
                    return self::RESULT_CONNECTED;
                }
            }

            // 200 with an error envelope or empty content — classify the body.
            return str_contains(strtolower((string) $response->body()), 'unauthorized')
                ? self::RESULT_AUTH_FAILED
                : self::RESULT_PROVIDER_ERROR;
        }

        return match ($response->status()) {
            401, 403 => self::RESULT_AUTH_FAILED,
            404 => self::RESULT_MODEL_NOT_FOUND,
            429 => self::RESULT_RATE_LIMITED,
            default => self::RESULT_PROVIDER_ERROR,
        };
    }

    /** Response Content-Type (first value) for telemetry — never an error path. */
    protected function responseContentType($response): ?string
    {
        $type = $response->headers()['Content-Type'] ?? null;

        return is_array($type) ? ($type[0] ?? null) : $type;
    }

    /**
     * SAFE failure classification. The provider body is inspected only to
     * pick a bucket — it is never included in the message, and neither are
     * headers, URLs with credentials, or key material.
     */
    protected function failureMessage(int $status, string $body): string
    {
        $lower = strtolower($body);

        if ($status === 401
            || str_contains($lower, 'unauthorized_client_error')
            || str_contains($lower, 'unauthorized client')) {
            return 'Provider authentication/client identification failed. '
                .'Check the API key and any required client headers (for example User-Agent) in Settings → Nexus AI.';
        }

        if ($status === 403) {
            // Gateways refuse 403 both for bad credentials and for tokens
            // that lack the configured model — name both operator actions.
            return 'The provider refused access (HTTP 403). Check the API key, '
                .'any required client headers, and that the token is allowed to use the configured model.';
        }

        return match (true) {
            $status === 404 => 'The configured model was not found on this provider. Check the model identifier in Settings → Nexus AI.',
            $status === 429 => 'The AI provider is rate limiting requests. Try again shortly.',
            $status === 200 => 'The AI provider returned an error response instead of a completion. Try again or check the provider configuration.',
            default => 'The AI provider rejected the request (HTTP '.$status.'). Try again or check the provider configuration.',
        };
    }
}
