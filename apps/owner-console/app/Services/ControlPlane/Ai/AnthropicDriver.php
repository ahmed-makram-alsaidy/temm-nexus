<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;
use Illuminate\Support\Facades\Http;

/** Anthropic Messages API driver (https://api.anthropic.com). */
class AnthropicDriver implements AiDriver
{
    public const RESULT_CONNECTED = 'CONNECTED';
    public const RESULT_AUTH_FAILED = 'AUTH_FAILED';
    public const RESULT_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';
    public const RESULT_RATE_LIMITED = 'RATE_LIMITED';
    public const RESULT_TIMEOUT = 'TIMEOUT';
    public const RESULT_PROVIDER_ERROR = 'PROVIDER_ERROR';

    public function id(): string
    {
        return 'anthropic';
    }

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        [$system, $chat] = self::splitSystem($messages);
        $payload = [
            'model' => $config->model,
            'max_tokens' => $options['max_output_tokens'] ?? $config->max_output_tokens,
            'messages' => array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']], $chat),
        ];
        if ($system !== null) {
            $payload['system'] = $system;
        }
        $response = Http::timeout($config->timeout_seconds)
            ->withHeaders(['x-api-key' => (string) ($config->secret_encrypted ?? ''), 'anthropic-version' => '2023-06-01'])
            ->accept('application/json')
            ->post(rtrim($config->base_url ?: 'https://api.anthropic.com', '/').'/v1/messages', $payload);
        if ($response->status() !== 200) {
            abort(502, 'AI provider error (HTTP '.$response->status().')');
        }
        $body = $response->json();
        $text = '';
        foreach ($body['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $text .= $part['text'];
            }
        }

        return [
            'text' => $text,
            'usage' => [
                'input_tokens' => (int) ($body['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int) ($body['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    public function test(AiProviderConfig $config): string
    {
        try {
            $response = Http::timeout(min($config->timeout_seconds, 20))
                ->withHeaders(['x-api-key' => (string) ($config->secret_encrypted ?? ''), 'anthropic-version' => '2023-06-01'])
                ->accept('application/json')
                ->post(rtrim($config->base_url ?: 'https://api.anthropic.com', '/').'/v1/messages', [
                    'model' => $config->model,
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
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

    /** Anthropic takes system as a top-level field. */
    public static function splitSystem(array $messages): array
    {
        $system = null;
        $chat = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system = trim((($system ?? '')."\n".$m['content']));
            } else {
                $chat[] = $m;
            }
        }

        return [$system, $chat];
    }
}
