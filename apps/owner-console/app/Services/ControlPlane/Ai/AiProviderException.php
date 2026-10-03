<?php

namespace App\Services\ControlPlane\Ai;

/**
 * 0.4.0-rc.7 — a provider-side failure that carries a SAFE, human-readable
 * message. The raw provider body, headers and any key material NEVER reach
 * this message: drivers classify (auth / model / rate limit / timeout /
 * generic) and throw, and the conversation layer persists or shows only
 * `safeMessage`. This is what prevents a 401/403/unauthorized-client
 * response from becoming an empty assistant bubble or a fake success.
 */
class AiProviderException extends \RuntimeException
{
    /** Safe message shown to operators — no provider body, no secrets. */
    public readonly string $safeMessage;

    public function __construct(string $safeMessage, int $code = 0)
    {
        parent::__construct($safeMessage, $code);

        $this->safeMessage = $safeMessage;
    }
}
