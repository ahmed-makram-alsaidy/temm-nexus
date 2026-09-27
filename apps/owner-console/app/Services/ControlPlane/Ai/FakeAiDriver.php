<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;

/**
 * FakeAiProvider (25 — AI PROVIDER TESTING): scripted driver used by the
 * automated suite so regression never requires a paid provider API.
 * Scripted responses/errors are supplied via options by the caller.
 */
class FakeAiDriver implements AiDriver
{
    public const RESULT_CONNECTED = 'CONNECTED';
    public const RESULT_AUTH_FAILED = 'AUTH_FAILED';
    public const RESULT_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';
    public const RESULT_RATE_LIMITED = 'RATE_LIMITED';
    public const RESULT_TIMEOUT = 'TIMEOUT';
    public const RESULT_PROVIDER_ERROR = 'PROVIDER_ERROR';

    public function id(): string
    {
        return 'fake';
    }

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        if (! empty($options['fake_error'])) {
            throw new \RuntimeException('Fake AI error: '.$options['fake_error']);
        }
        $queue = $options['fake_responses'] ?? ['{"ok":true}'];

        return [
            'text' => array_shift($queue) ?? '{"ok":true}',
            'usage' => [
                'input_tokens' => $options['fake_input_tokens'] ?? 100,
                'output_tokens' => $options['fake_output_tokens'] ?? 40,
            ],
        ];
    }

    public function test(AiProviderConfig $config): string
    {
        return self::RESULT_CONNECTED;
    }
}
