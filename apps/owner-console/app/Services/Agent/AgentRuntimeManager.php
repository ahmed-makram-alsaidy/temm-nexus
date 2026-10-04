<?php

namespace App\Services\Agent;

use App\Models\AgentRuntime;
use App\Services\Agent\Contract\AgentRuntimeContract;
use App\Services\Agent\Contract\AgentRuntimeException;
use App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime;
use Closure;

/**
 * Registry/driver manager for agent runtimes — the same shape as TEMM's AI
 * gateway registry. Product code asks for `AgentRuntimeManager::driver($id)`
 * or resolves the driver for an AgentRuntime row; NOTHING outside an adapter
 * may branch on a runtime name (no `if ($runtime === 'opencode')` scattered
 * through the product).
 */
class AgentRuntimeManager
{
    /** @var array<string, AgentRuntimeContract> */
    protected array $customDrivers = [];

    public function registerDefaults(): void
    {
        $this->extend(OpenCodeRuntime::DRIVER_ID, fn () => new OpenCodeRuntime);
    }

    /**
     * Register or override a driver (also the extension point for future
     * runtimes and tests).
     *
     * @param  Closure(): AgentRuntimeContract  $factory
     */
    public function extend(string $id, Closure $factory): void
    {
        $this->customDrivers[$id] = $factory();
    }

    /** @return list<string> */
    public function knownDrivers(): array
    {
        $this->registerDefaults();

        return array_values(array_unique(array_merge(
            array_keys($this->customDrivers),
            [OpenCodeRuntime::DRIVER_ID],
        )));
    }

    public function has(string $id): bool
    {
        return in_array($id, $this->knownDrivers(), true);
    }

    /** Resolve the driver implementation for a registry key. */
    public function driver(string $id): AgentRuntimeContract
    {
        $this->registerDefaults();

        $driver = $this->customDrivers[$id] ?? null;

        if ($driver === null) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                "Unknown agent runtime driver [{$id}]."
            );
        }

        return $driver;
    }

    /** Resolve the driver backing a persisted runtime row. */
    public function forRuntime(AgentRuntime $runtime): AgentRuntimeContract
    {
        return $this->driver($runtime->driver);
    }

    /** All drivers with their labels/ids (UI + CLI listings). */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->knownDrivers() as $id) {
            $driver = $this->driver($id);
            $out[] = ['id' => $id, 'label' => $driver->label()];
        }

        return $out;
    }
}
