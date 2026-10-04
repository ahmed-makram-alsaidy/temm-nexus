<?php

namespace Tests\Feature\Phase43;

use App\Services\Agent\AgentNetworkGuard;
use App\Services\Agent\Contract\AgentRuntimeException;
use App\Services\Agent\Runtimes\OpenCode\OpenCodeClient;
use Tests\TestCase;

/**
 * Endpoint safety gates (config-aware) and URL normalization. Live wire
 * behavior (auth headers, SSE streaming, timeouts) is proven against the
 * mock server in the other Phase 43 suites.
 */
class AgentNetworkGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin the posture: the operator's local .env must never influence
        // the guard tests (acceptance environments enable loopback).
        config(['agent.allow_loopback_endpoints' => false]);
    }

    public function test_managed_endpoints_are_trusted_by_construction(): void
    {
        AgentNetworkGuard::assertSafeEndpoint('http://opencode:4096', true);
        $this->assertTrue(true);
    }

    public function test_external_https_public_endpoint_passes(): void
    {
        AgentNetworkGuard::assertSafeEndpoint('https://agents.example.com', false);
        $this->assertTrue(true);
    }

    public function test_external_http_and_private_targets_are_refused(): void
    {
        $refused = [
            'http://agents.example.com',        // not HTTPS
            'http://127.0.0.1:4096',            // loopback
            'http://203.0.113.5:4096',           // documentation range (TEST-NET-3)
            'http://198.51.100.9',               // documentation range (TEST-NET-2)
            'http://metadata.google.internal',  // metadata
        ];

        foreach ($refused as $endpoint) {
            try {
                AgentNetworkGuard::assertSafeEndpoint($endpoint, false);
                $this->fail("{$endpoint} was accepted.");
            } catch (AgentRuntimeException $e) {
                $this->assertSame(AgentRuntimeException::INVALID_RUNTIME_RESPONSE, $e->category);
            }
        }
    }

    public function test_loopback_flag_opens_only_loopback(): void
    {
        config(['agent.allow_loopback_endpoints' => true]);
        AgentNetworkGuard::assertSafeEndpoint('http://127.0.0.1:14096', false);
        $this->assertTrue(AgentNetworkGuard::allowLoopback());

        try {
            AgentNetworkGuard::assertSafeEndpoint('http://203.0.113.5', false);
            $this->fail('Private range was accepted with the loopback flag.');
        } catch (AgentRuntimeException $e) {
            $this->assertSame(AgentRuntimeException::INVALID_RUNTIME_RESPONSE, $e->category);
        }
    }

    public function test_client_url_normalization(): void
    {
        $client = new OpenCodeClient('http://127.0.0.1:4096/');
        $this->assertSame('http://127.0.0.1:4096', $client->baseUrl());
    }
}
