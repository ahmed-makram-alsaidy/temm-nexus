<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat-completions format driver. Serves openai, openrouter and
 * openai_compatible (base_url decides the endpoint) — the three share one
 * stable wire format.
 */
class OpenAiCompatibleDriver implements AiDriver
{
    public const RESULT_CONNECTED = 'CONNECTED';
    public const RESULT_AUTH_FAILED = 'AUTH_FAILED';
    public const RESULT_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';
    public const RESULT_RATE_LIMITED = 'RATE_LIMITED';
    public const RESULT_TIMEOUT = 'TIMEOUT';
    public const RESULT_PROVIDER_ERROR = 'PROVIDER_ERROR';

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

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        $response = Http::timeout($config->timeout_seconds)
            ->withToken((string) ($config->secret_encrypted ?? ''))
            ->accept('application/json')
            ->post($this->endpoint($config), [
                'model' => $config->model,
                'messages' => $messages,
                'max_tokens' => $options['max_output_tokens'] ?? $config->max_output_tokens,
            ]);

        if ($response->status() !== 200) {
            abort(502, 'AI provider error (HTTP '.$response->status().')');
        }
        $body = $response->json();

        return [
            'text' => (string) ($body['choices'][0]['message']['content'] ?? ''),
            'usage' => [
                'input_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    public function test(AiProviderConfig $config): string
    {
        try {
            $response = Http::timeout(min($config->timeout_seconds, 20))
                ->withToken((string) ($config->secret_encrypted ?? ''))
                ->accept('application/json')
                ->post($this->endpoint($config), [
                    'model' => $config->model,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 1,
                ]);
            if ($response->status() === 200) {
                return self::RESULT_CONNECTED;
            }

            return match ($response->status()) {
                401, 403 => self::RESULT_AUTH_FAILED,
                404 => self::RESULT_MODEL_NOT_FOUND,
                429 => self::RESULT_RATE_LIMITED,
                default => self::RESULT_PROVIDER_ERROR,
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return str_contains(strtolower($e->getMessage()), 'timed out') ? self::RESULT_TIMEOUT : self::RESULT_PROVIDER_ERROR;
        }
    }
}
