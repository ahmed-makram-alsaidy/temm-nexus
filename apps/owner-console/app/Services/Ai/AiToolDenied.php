<?php

namespace App\Services\Ai;

/**
 * Thrown when a tool call is refused.
 *
 * Carries a machine reason and a user-safe message. It deliberately does NOT
 * echo the tool's arguments back, so a denial can never be used to reflect
 * attacker-controlled content into a prompt or a log.
 */
class AiToolDenied extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $tool = '',
    ) {
        parent::__construct($message);
    }

    public static function unknownTool(string $tool): self
    {
        return new self('unknown_tool', "No such tool: {$tool}", $tool);
    }

    public static function wrongScope(string $tool, Scope $context, Scope $toolScope): self
    {
        return new self(
            'wrong_scope',
            "Tool '{$tool}' is not available in {$context->label()} context.",
            $tool,
        );
    }

    public static function missingCapability(string $tool, string $capability): self
    {
        return new self(
            'missing_capability',
            "You do not have permission to use '{$tool}' here.",
            $tool,
        );
    }

    public static function missingContext(string $tool, Scope $scope): self
    {
        return new self(
            'missing_context',
            "Tool '{$tool}' needs a {$scope->label()} context, which is not available.",
            $tool,
        );
    }

    public static function badArgument(string $tool, string $parameter): self
    {
        return new self('bad_argument', "Tool '{$tool}' received an invalid '{$parameter}' argument.", $tool);
    }

    public static function unsafeResult(string $tool, string $key): self
    {
        return new self(
            'unsafe_result',
            "Tool '{$tool}' produced a value that may not be sent to a model ({$key}).",
            $tool,
        );
    }
}
