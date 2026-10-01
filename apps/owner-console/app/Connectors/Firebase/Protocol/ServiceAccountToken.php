<?php

namespace App\Connectors\Firebase\Protocol;

/**
 * Phase 29B — OAuth2 access-token minting for a service account.
 *
 * Exchanges a signed JWT for a short-lived access token at the service
 * account's token_uri. Tokens live in process memory only, are never logged
 * or persisted, and are refreshed slightly before expiry.
 */
class ServiceAccountToken
{
    public const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    protected static array $cache = [];

    public function __construct(
        protected ServiceAccount $account,
        protected ?FirebaseTransport $transport = null,
    ) {
    }

    /** A valid bearer token, minted or cached. */
    public function token(): string
    {
        $key = hash('sha256', $this->account->clientEmail());
        $cached = self::$cache[$key] ?? null;
        if (is_array($cached) && $cached['expires_at'] > time() + 60) {
            return $cached['token'];
        }

        $now = time();
        $assertion = $this->account->signJwt(
            ['scope' => self::SCOPE, 'aud' => $this->account->tokenUri()],
            $now,
            $now + 3600
        );
        $transport = $this->transport ?? new HttpFirebaseTransport;
        $response = $transport->send('POST', $this->account->tokenUri(), [
            'json' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);
        $data = json_decode($response['body'], true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        $expiresIn = is_array($data) ? (int) ($data['expires_in'] ?? 3600) : 3600;
        if ($token === '') {
            throw new FirebaseTransportException('OAuth token exchange returned no access token.', $response['status']);
        }
        self::$cache[$key] = ['token' => $token, 'expires_at' => $now + $expiresIn];

        return $token;
    }

    /** Clear the in-process token cache (used between operations in tests). */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
