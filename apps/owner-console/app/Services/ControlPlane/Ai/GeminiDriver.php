<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;
use Illuminate\Support\Facades\Http;

/** Google Gemini generateContent driver (generativelanguage.googleapis.com). */
class GeminiDriver implements AiDriver
{
    public const RESULT_CONNECTED = 'CONNECTED';
    public const RESULT_AUTH_FAILED = 'AUTH_FAILED';
    public const RESULT_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';
    public const RESULT_RATE_LIMITED = 'RATE_LIMITED';
    public const RESULT_TIMEOUT = 'TIMEOUT';
    public const RESULT_PROVIDER_ERROR = 'PROVIDER_ERROR';

    public function id(): string
    {
        return 'gemini';
    }

    protected function endpoint(AiProviderConfig $config, string $method = 'generateContent'): string
    {
        $base = rtrim($config->base_url ?: 'https://generativelanguage.googleapis.com', '/');
        $model = $config->model ?: 'gemini-1.5-flash';

        return $base.'/v1beta/models/'.$model.':'.$method.'?key='.urlencode((string) ($config->secret_encrypted ?? ''));
    }

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        [$system, $chat] = AnthropicDriver::splitSystem($messages);
        $contents = array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $chat);
        if ($system !== null) {
            array_unshift($contents, ['role' => 'user', 'parts' => [['text' => $system]]]);
        }
        $response = Http::timeout($config->timeout_seconds)
            ->accept('application/json')
            ->post($this->endpoint($config), [
                'contents' => $contents,
                'generationConfig' => ['maxOutputTokens' => $options['max_output_tokens'] ?? $config->max_output_tokens],
            ]);
        if ($response->status() !== 200) {
            abort(502, 'AI provider error (HTTP '.$response->status().')');
        }
        $body = $response->json();
        $text = '';
        foreach ($body['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        return [
            'text' => $text,
            'usage' => [
                'input_tokens' => (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
        ];
    }

    public function test(AiProviderConfig $config): string
    {
        try {
            $response = Http::timeout(min($config->timeout_seconds, 20))
                ->accept('application/json')
                ->post($this->endpoint($config), ['contents' => [['role' => 'user', 'parts' => [['text' => 'ping']]]]]);
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
