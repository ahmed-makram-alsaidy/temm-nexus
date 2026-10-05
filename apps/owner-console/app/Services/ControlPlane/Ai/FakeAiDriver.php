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
     * Phase F: `throw` scripts a provider-side FAILURE for the next call —
     * `code` drives the same HTTP-status classification real drivers produce,
     * `type` picks the exception shape (`provider` = AiProviderException,
     * `connection` = ConnectionException, `generic` = RuntimeException). This
     * is how the error-state journeys are exercised without a network.
     *
     * @var array{responses?: list<string>, tool_calls?: array<int, string>, throw?: array{type?: string, code?: int, message?: string}|list<array{type?: string, code?: int, message?: string}>}|null
     */
    public static ?array $script = null;

    /**
     * The most recent `complete()` payload, captured for assertions about WHAT
     * was actually sent to a provider (system prompt content, no secrets).
     *
     * @var array<string, mixed>|null
     */
    public static ?array $lastComplete = null;

    /** Reset between tests. */
    public static function reset(): void
    {
        self::$script = null;
        self::$lastComplete = null;
        self::$callCount = 0;
        self::$completedCalls = 0;
    }

    private static int $callCount = 0;

    /** Total complete() invocations — the runaway-budget assertions read this. */
    public static int $completedCalls = 0;

    public function complete(AiProviderConfig $config, array $messages, array $options = []): array
    {
        self::$lastComplete = ['config' => $config, 'messages' => $messages, 'options' => $options];
        self::$completedCalls++;

        // Local QA affordance: a script file lets a REAL browser session walk
        // the fake provider through a multi-round conversation without any
        // network. Only meaningful when the operator deliberately configures
        // the 'fake' provider; hermetic tests set self::$script directly.
        if (self::$script === null && env('FAKE_AI_SCRIPT_FILE')) {
            try {
                $raw = file_get_contents((string) env('FAKE_AI_SCRIPT_FILE'));
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($decoded)) {
                    self::$script = $decoded;
                }
            } catch (\Throwable) {
                // An unreadable script file falls through to the default reply.
            }
        }

        if (! empty($options['fake_error'])) {
            throw new \RuntimeException('Fake AI error: '.$options['fake_error']);
        }

        // A static script takes precedence so a test can drive the real gateway.
        if (self::$script !== null) {
            $index = self::$callCount++;
            $throwSpecs = self::$script['throw'] ?? null;
            // A single spec applies to every call; a list is consumed per call.
            $throw = is_array($throwSpecs) && array_is_list($throwSpecs)
                ? ($throwSpecs[$index] ?? null)
                : $throwSpecs;

            if (is_array($throw)) {
                $message = (string) ($throw['message'] ?? 'Scripted provider failure');
                $code = (int) ($throw['code'] ?? 0);

                match ((string) ($throw['type'] ?? 'provider')) {
                    'connection' => throw new \Illuminate\Http\Client\ConnectionException($message),
                    'generic' => throw new \RuntimeException($message),
                    default => throw new AiProviderException($message, $code),
                };
            }

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
