<?php

namespace App\Connectors\Firebase\Protocol;

/**
 * Phase 29B — parsed Firebase service account credential.
 *
 * The service account JSON is a SECRET (vault-backed, 29B): it is parsed once
 * in memory, never logged, never echoed, never persisted to migration
 * artifacts. Only non-secret derived facts (project_id, client_email) are
 * exposed read-only.
 */
final class ServiceAccount
{
    private function __construct(
        private readonly string $projectId,
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly string $tokenUri,
    ) {
    }

    /** Parse and validate a service account JSON string. */
    public static function parse(string $json): self
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('service account JSON is not valid JSON.');
        }
        $projectId = (string) ($data['project_id'] ?? '');
        $clientEmail = (string) ($data['client_email'] ?? '');
        $privateKey = (string) ($data['private_key'] ?? '');
        $tokenUri = (string) ($data['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
            throw new \InvalidArgumentException('service account JSON is missing project_id, client_email or private_key.');
        }
        if (! str_contains($privateKey, 'PRIVATE KEY')) {
            throw new \InvalidArgumentException('service account private_key is not a PEM key.');
        }

        return new self($projectId, $clientEmail, $privateKey, $tokenUri);
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    /** Non-secret identity — safe for display surfaces. */
    public function clientEmail(): string
    {
        return $this->clientEmail;
    }

    public function tokenUri(): string
    {
        return $this->tokenUri;
    }

    /**
     * Sign a compact JWS (RS256) over header.claims with the private key.
     * ext-openssl only — no external SDK dependency.
     *
     * @return array{assertion: string}
     */
    public function signJwt(array $claims, int $issuedAt, int $expiresAt): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            $this->b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->b64(json_encode($claims + ['iss' => $this->clientEmail, 'iat' => $issuedAt, 'exp' => $expiresAt], JSON_UNESCAPED_SLASHES)),
        ];
        $input = implode('.', $segments);
        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false) {
            throw new \RuntimeException('service account private_key could not be loaded (invalid PEM).');
        }
        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('service account JWT signing failed.');
        }
        $segments[] = $this->b64($signature);

        return implode('.', $segments);
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
