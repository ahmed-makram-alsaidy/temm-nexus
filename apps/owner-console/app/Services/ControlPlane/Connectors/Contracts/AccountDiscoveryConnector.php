<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

use App\Models\ExternalAccountConnection;

/**
 * Phase 27G.2 — connectors whose declared import flow is account-based
 * (connect an account with a secret → discover projects → select one).
 * The generic wizard drives these operations through the contract; it never
 * imports a provider class.
 */
interface AccountDiscoveryConnector extends DiscoverableSourceConnector
{
    /** Store the account secret encrypted-at-rest and register the connection. */
    public function connectAccount($user, string $displayName, string $secret): ExternalAccountConnection;

    /** Classify a connection test (result + detail, no secret material). */
    public function testAccount(ExternalAccountConnection $connection): array;

    /** Discover selectable projects for a connected account. */
    public function discoverAccountProjects(ExternalAccountConnection $connection): array;
}
