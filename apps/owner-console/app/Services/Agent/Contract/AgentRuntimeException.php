<?php

namespace App\Services\Agent\Contract;

/**
 * Structured runtime failure. Everything an OpenCode (or future runtime)
 * failure can mean is one of these categories — a task must NEVER end up as
 * a "successful" empty result because an API returned HTTP 200 with garbage,
 * and raw wire errors must never reach the UI.
 */
class AgentRuntimeException extends \RuntimeException
{
    public const RUNTIME_UNAVAILABLE = 'RUNTIME_UNAVAILABLE';

    public const RUNTIME_AUTH_FAILED = 'RUNTIME_AUTH_FAILED';

    public const MODEL_UNAVAILABLE = 'MODEL_UNAVAILABLE';

    public const SESSION_FAILED = 'SESSION_FAILED';

    public const WORKSPACE_FAILED = 'WORKSPACE_FAILED';

    public const COMMAND_FAILED = 'COMMAND_FAILED';

    public const TASK_CANCELLED = 'TASK_CANCELLED';

    public const INVALID_RUNTIME_RESPONSE = 'INVALID_RUNTIME_RESPONSE';

    public const TIMEOUT = 'TIMEOUT';

    public const CATEGORIES = [
        self::RUNTIME_UNAVAILABLE, self::RUNTIME_AUTH_FAILED, self::MODEL_UNAVAILABLE,
        self::SESSION_FAILED, self::WORKSPACE_FAILED, self::COMMAND_FAILED,
        self::TASK_CANCELLED, self::INVALID_RUNTIME_RESPONSE, self::TIMEOUT,
    ];

    public function __construct(
        public readonly string $category,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function make(string $category, string $message, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self($category, $message, $code, $previous);
    }
}
