<?php

namespace App\Services\Agent\Runtimes\OpenCode;

use App\Models\AgentRuntime;
use App\Services\Agent\AgentExecutionPolicy;
use App\Services\Agent\AgentNetworkGuard;
use App\Services\Agent\Contract\AgentRuntimeCapabilities;
use App\Services\Agent\Contract\AgentRuntimeConnection;
use App\Services\Agent\Contract\AgentRuntimeContract;
use App\Services\Agent\Contract\AgentRuntimeEvent;
use App\Services\Agent\Contract\AgentRuntimeModel;
use App\Services\Agent\Contract\AgentRuntimeException;

/**
 * First runtime adapter: the OpenCode coding agent, over its official HTTP
 * server API (opencode serve). See docs/agent-runtime/OPENCODE_INTEGRATION.md
 * for the verified interface and the reasoning behind this integration mode.
 */
class OpenCodeRuntime implements AgentRuntimeContract
{
    public const DRIVER_ID = 'opencode';

    public function id(): string
    {
        return self::DRIVER_ID;
    }

    public function label(): string
    {
        return 'OpenCode';
    }

    public function capabilities(AgentRuntime $runtime): AgentRuntimeCapabilities
    {
        return new AgentRuntimeCapabilities(
            AgentRuntimeCapabilities::all(),
            $runtime->version,
        );
    }

    public function testConnection(AgentRuntime $runtime): AgentRuntimeConnection
    {
        try {
            $client = $this->client($runtime);
            $health = $client->health();

            if (! $health['healthy']) {
                return AgentRuntimeConnection::failed(
                    AgentRuntimeException::RUNTIME_UNAVAILABLE,
                    'Runtime reports unhealthy status.'
                );
            }

            if (! self::isCompatibleVersion($health['version'])) {
                return AgentRuntimeConnection::failed(
                    AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                    'Incompatible runtime version: '.$health['version'].'.'
                );
            }

            return AgentRuntimeConnection::connected($health['version']);
        } catch (AgentRuntimeException $e) {
            return AgentRuntimeConnection::failed($e->category, $e->getMessage());
        }
    }

    public function models(AgentRuntime $runtime): array
    {
        $models = [];
        foreach ($this->client($runtime)->providers() as $provider) {
            $providerId = is_string($provider['id'] ?? null) ? $provider['id'] : null;
            if ($providerId === null || ! is_array($provider['models'] ?? null)) {
                continue;
            }

            foreach ($provider['models'] as $id => $model) {
                if (! is_string($id) || ! is_array($model)) {
                    continue;
                }

                $limit = $model['limit']['context'] ?? null;

                $models[] = new AgentRuntimeModel(
                    provider: $providerId,
                    id: $id,
                    name: is_string($model['name'] ?? null) ? $model['name'] : null,
                    contextLimit: is_numeric($limit) ? (int) $limit : null,
                    capabilities: is_array($model['capabilities'] ?? null) ? $model['capabilities'] : null,
                );
            }
        }

        return $models;
    }

    public function startSession(AgentRuntime $runtime, string $directory, ?string $model, array $permission, ?string $instructions = null): string
    {
        $session = $this->client($runtime)->createSession($directory, $model, $permission, $instructions);

        return (string) $session['id'];
    }

    public function sendPrompt(AgentRuntime $runtime, string $sessionId, string $prompt, ?string $model = null): void
    {
        $this->client($runtime)->sendPromptAsync($sessionId, $prompt, $model);
    }

    public function events(AgentRuntime $runtime, string $sessionId, string $directory, int $idleTimeoutSeconds): \Generator
    {
        foreach ($this->client($runtime)->streamEvents($directory, $idleTimeoutSeconds) as $frame) {
            $event = OpenCodeEventNormalizer::normalize($frame, $sessionId);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    public function diff(AgentRuntime $runtime, string $sessionId): array
    {
        return $this->client($runtime)->sessionDiff($sessionId);
    }

    public function abort(AgentRuntime $runtime, string $sessionId): void
    {
        try {
            $this->client($runtime)->abort($sessionId);
        } catch (AgentRuntimeException $e) {
            // Aborting an already-dead session must not mask the real outcome.
            if ($e->category === AgentRuntimeException::RUNTIME_UNAVAILABLE) {
                throw $e;
            }
        }
    }

    /** Build the transport for a runtime row, enforcing endpoint safety. */
    public function client(AgentRuntime $runtime): OpenCodeClient
    {
        $endpoint = $runtime->resolvedEndpoint();
        $managed = $runtime->mode === AgentRuntime::MODE_MANAGED;

        if ($endpoint === null || $endpoint === '') {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Runtime endpoint is not configured.'
            );
        }

        AgentNetworkGuard::assertSafeEndpoint($endpoint, $managed);

        return new OpenCodeClient(
            $endpoint,
            $runtime->resolvedAuthUser(),
            $runtime->resolvedAuthSecret(),
            config('agent.limits.http_connect_timeout', 10),
            $runtime->timeout_seconds ?? 1800,
        );
    }

    public static function isCompatibleVersion(string $version): bool
    {
        if (! preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            return false;
        }

        // Same MAJOR family, MINOR at least the verified surface.
        $minMajor = (int) config('agent.opencode.min_major', 1);
        $minMinor = (int) config('agent.opencode.min_minor', 18);

        return ((int) $m[1]) === $minMajor && ((int) $m[2]) >= $minMinor;
    }

    /** Permission config TEMM pins onto every session of this runtime. */
    public static function sessionPermissionConfig(): array
    {
        return AgentExecutionPolicy::sessionPermissionConfig();
    }
}
