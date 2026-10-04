<?php

namespace App\Services\Agent\Contract;

/**
 * One model as the RUNTIME reports it. TEMM renders ids verbatim and does not
 * infer pricing or availability beyond what upstream metadata states.
 */
final class AgentRuntimeModel
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?int $contextLimit = null,
        /** @var array<string, mixed>|null upstream-reported capability flags, verbatim */
        public readonly ?array $capabilities = null,
    ) {
    }

    /** Canonical TEMM identifier: `provider/model` — exactly what tasks store. */
    public function canonicalId(): string
    {
        return $this->provider.'/'.$this->id;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'canonical' => $this->canonicalId(),
            'name' => $this->name,
            'context_limit' => $this->contextLimit,
            'capabilities' => $this->capabilities,
        ];
    }
}
