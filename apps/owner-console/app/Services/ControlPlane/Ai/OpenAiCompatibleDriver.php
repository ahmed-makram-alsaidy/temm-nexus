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
     */
    protected function client(AiProviderConfig $config, ?int $timeout = null): PendingRequest
    {
        return Http::timeout($timeout ?? $config->timeout_seconds)
            ->withToken((string) ($config->secret_encrypted ?? ''))
            ->accept('application/json')
            ->withHeaders($this->safeCustomHeaders($config));
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
        $response = $this->client($config)->post($this->endpoint($config), [
            'model' => $config->model,
            'messages' => $messages,
            'max_tokens' => $options['max_output_tokens'] ?? $config->max_output_tokens,
        ]);

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
        try {
            $response = $this->client($config, min($config->timeout_seconds, 20))
                ->post($this->endpoint($config), [
                    'model' => $config->model,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 1,
                ]);
            if ($response->status() === 200 && is_array($response->json())
                && ! isset($response->json()['error'])
                && trim((string) ($response->json()['choices'][0]['message']['content'] ?? '')) !== '') {
                return self::RESULT_CONNECTED;
            }
            if ($response->status() === 200) {
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
        } catch (ConnectionException $e) {
            return str_contains(strtolower($e->getMessage()), 'timed out') ? self::RESULT_TIMEOUT : self::RESULT_PROVIDER_ERROR;
        }
    }

    /**
     * SAFE failure classification. The provider body is inspected only to
     * pick a bucket — it is never included in the message, and neither are
     * headers, URLs with credentials, or key material.
     */
    protected function failureMessage(int $status, string $body): string
    {
        $lower = strtolower($body);

        if ($status === 401 || $status === 403
            || str_contains($lower, 'unauthorized_client_error')
            || str_contains($lower, 'unauthorized client')) {
            return 'Provider authentication/client identification failed. '
                .'Check the API key and any required client headers (for example User-Agent) in Settings → Nexus AI.';
        }

        return match (true) {
            $status === 404 => 'The configured model was not found on this provider. Check the model identifier in Settings → Nexus AI.',
            $status === 429 => 'The AI provider is rate limiting requests. Try again shortly.',
            $status === 200 => 'The AI provider returned an error response instead of a completion. Try again or check the provider configuration.',
            default => 'The AI provider rejected the request (HTTP '.$status.'). Try again or check the provider configuration.',
        };
    }
}
