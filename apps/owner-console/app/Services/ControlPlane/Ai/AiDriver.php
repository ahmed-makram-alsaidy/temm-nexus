<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;

/**
 * Phase 25F — AI provider driver contract. Provider-agnostic: the gateway
 * never binds the platform to one vendor. Drivers implement stable generic
 * HTTP interfaces.
 */
interface AiDriver
{
    /**
     * Complete a chat-style interaction.
     * $messages: [['role' => 'system'|'user'|'assistant', 'content' => string], ...]
     * $options:  ['max_output_tokens' => int, 'fake_responses' => string[] (fake driver), 'fake_error' => string]
     * Returns:   ['text' => string, 'usage' => ['input_tokens' => int, 'output_tokens' => int]]
     */
    public function complete(AiProviderConfig $config, array $messages, array $options = []): array;

    /** 25F.2 — safe minimal probe. Returns a RESULT_* constant. */
    public function test(AiProviderConfig $config): string;

    public function id(): string;
}
