<?php

namespace App\Services\Agent\Contract;

/**
 * What a runtime can do, in normalized form. The UI and orchestrator branch on
 * these names — never on `driver === 'opencode'`.
 */
final class AgentRuntimeCapabilities
{
    public const SESSION_PROMPT = 'session_prompt';

    public const MODEL_DISCOVERY = 'model_discovery';

    public const EVENT_STREAM = 'event_stream';

    public const SESSION_DIFF = 'session_diff';

    public const PERMISSION_FLOW = 'permission_flow';

    public const SESSION_ABORT = 'session_abort';

    public const USAGE_METADATA = 'usage_metadata';

    /**
     * @param  list<string>  $supported  subset of the constants above
     */
    public function __construct(
        public readonly array $supported,
        public readonly ?string $version = null,
    ) {
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supported, true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SESSION_PROMPT, self::MODEL_DISCOVERY, self::EVENT_STREAM,
            self::SESSION_DIFF, self::PERMISSION_FLOW, self::SESSION_ABORT,
            self::USAGE_METADATA,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['supported' => $this->supported, 'version' => $this->version];
    }
}
