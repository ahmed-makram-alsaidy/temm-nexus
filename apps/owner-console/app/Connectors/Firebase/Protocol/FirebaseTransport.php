<?php

namespace App\Connectors\Firebase\Protocol;

/**
 * Phase 29A — HTTP transport seam for the Firebase REST APIs.
 *
 * All provider traffic (Firestore, Identity Toolkit, Firebase Storage,
 * Cloud Functions, OAuth token exchange) flows through this boundary so that:
 * - the SSRF guard is enforced at exactly one place (HttpFirebaseTransport);
 * - the sandbox/dogfood fixture transport (29J) can serve a synthetic
 *   project without any network I/O — same wire format as production.
 */
interface FirebaseTransport
{
    /**
     * Perform one HTTP request against a Firebase API surface.
     *
     * @param  string  $method  HTTP verb (the connector only ever sends GET/POST for read APIs)
     * @param  string  $url     absolute URL
     * @param  array{json?: mixed, query?: array<string, string>, headers?: array<string, string>}  $options
     * @return array{status: int, body: string}
     *
     * @throws FirebaseTransportException on transport-level failure
     */
    public function send(string $method, string $url, array $options = []): array;
}
