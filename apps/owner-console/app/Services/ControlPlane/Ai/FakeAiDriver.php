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

    /**
     * Static scripted behaviour, for tests that drive the copilot through the
     * gateway (which does not forward per-call options to the driver).
     *
     * `responses` is consumed one per call. `tool_calls` maps a 0-based call
     * index to a TOOL_CALL line to emit BEFORE that call's scripted text, which
     * lets a test exercise the full tool round-trip deterministically without a
     * network or a paid provider.
     *
     * @var array{responses?: list<string>, tool_calls?: array<int, string>}|null
     */
    public static ?array $script = null;

    /** Reset between tests. */
    public static function reset(): void
    {
        self::$script = null;
        self::$callCount = 0;
    }

    private static int $callCount = 0;

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        if (! empty($options['fake_error'])) {
            throw new \RuntimeException('Fake AI error: '.$options['fake_error']);
        }

        // A static script takes precedence so a test can drive the real gateway.
        if (self::$script !== null) {
            $index = self::$callCount++;
            $text = self::$script['responses'][$index] ?? 'Done.';

            if (isset(self::$script['tool_calls'][$index])) {
                $text = self::$script['tool_calls'][$index]."\n".$text;
            }

            return [
                'text' => $text,
                'usage' => [
                    'input_tokens' => $options['fake_input_tokens'] ?? 100,
                    'output_tokens' => $options['fake_output_tokens'] ?? 40,
                ],
            ];
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
