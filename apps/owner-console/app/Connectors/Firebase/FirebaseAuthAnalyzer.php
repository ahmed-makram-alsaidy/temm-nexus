<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29F — Firebase Authentication inventory and honest migration
 * planning.
 *
 * SAFETY PROPERTIES (29F, enforced by construction and asserted by tests):
 * - the connector reads auth accounts ONLY through accounts:batchGet, whose
 *   payload does not include password hashes, salts or refresh tokens;
 * - even if a payload carried such material, it is SCRUBBED here before any
 *   downstream surface ever sees it — hash/salt/token keys never survive;
 * - custom claims are reduced to SHAPE (key names + value types), never
 *   values — claim payloads may contain business or authorization data;
 * - password portability is NEVER faked: without provable hash compatibility
 *   every password-provider account is NEEDS_REVIEW.
 */
class FirebaseAuthAnalyzer
{
    /** Keys that must never reach any migration surface (29F). */
    public const FORBIDDEN_KEYS = [
        'passwordHash', 'passwordSalt', 'salt', 'refreshToken', 'mfaEnrollments',
        'phoneConfidence', 'emailConfirmationToken', 'emailConfirmationVerifyTime',
    ];

    /**
     * Analyze one page-batch of raw Identity Toolkit user objects.
     *
     * @param  list<array>  $users  raw batchGet user payloads
     * @return array{users: list<array>, providers: array<string,int>, password_users: int, anonymous_users: int, oauth_only_users: int, disabled_users: int, claims_shapes: list<array>, migration: array<string, mixed>}
     */
    public function analyze(array $users): array
    {
        $inventory = [];
        $providers = [];
        $passwordUsers = 0;
        $anonymousUsers = 0;
        $oauthOnlyUsers = 0;
        $disabledUsers = 0;
        $claimsShapes = [];

        foreach ($users as $user) {
            $scrubbed = $this->scrub($user);
            $uid = (string) ($scrubbed['localId'] ?? '');
            if ($uid === '') {
                continue;
            }
            $userProviders = [];
            foreach ((array) ($scrubbed['providerUserInfo'] ?? []) as $provider) {
                $providerId = (string) ($provider['providerId'] ?? '');
                if ($providerId !== '') {
                    $userProviders[] = $providerId;
                    $providers[$providerId] = ($providers[$providerId] ?? 0) + 1;
                }
            }
            $hasPassword = in_array('password', $userProviders, true);
            $hasOauth = (bool) array_diff($userProviders, ['password', 'anonymous']);
            if ($hasPassword) {
                $passwordUsers++;
            }
            if (in_array('anonymous', $userProviders, true) && ! $hasPassword && ! $hasOauth) {
                $anonymousUsers++;
            }
            if (! $hasPassword && $hasOauth) {
                $oauthOnlyUsers++;
            }
            if (! empty($scrubbed['disabled'])) {
                $disabledUsers++;
            }
            $claimsShape = null;
            if (isset($scrubbed['customClaims']) && is_array($scrubbed['customClaims'])) {
                $claimsShape = array_map(fn ($v) => gettype($v), $scrubbed['customClaims']);
                ksort($claimsShape);
                $claimsShapes[] = ['uid' => $uid, 'shape' => $claimsShape];
            }
            $inventory[] = [
                'uid' => $uid,
                // Email is required migration data for the target user record
                // (not a secret); claim VALUES are the privacy boundary here.
                'email' => isset($scrubbed['email']) && is_string($scrubbed['email']) && $scrubbed['email'] !== '' ? $scrubbed['email'] : null,
                'email_present' => isset($scrubbed['email']) && is_string($scrubbed['email']) && $scrubbed['email'] !== '',
                'email_verified' => (bool) ($scrubbed['emailVerified'] ?? false),
                'providers' => $userProviders,
                'disabled' => (bool) ($scrubbed['disabled'] ?? false),
                'created_at_ms' => isset($scrubbed['createdAt']) ? (int) $scrubbed['createdAt'] : null,
                'last_login_ms' => isset($scrubbed['lastLoginAt']) ? (int) $scrubbed['lastLoginAt'] : null,
                'custom_claims_shape' => $claimsShape,
                'migration_decision' => $this->decide($userProviders, $hasPassword),
            ];
        }

        return [
            'users' => $inventory,
            'providers' => $providers,
            'password_users' => $passwordUsers,
            'anonymous_users' => $anonymousUsers,
            'oauth_only_users' => $oauthOnlyUsers,
            'disabled_users' => $disabledUsers,
            'claims_shapes' => $claimsShapes,
            'migration' => [
                'password_compatibility' => 'NEEDS_REVIEW',
                'password_note' => 'Firebase hashes (scrypt) are not exposed through account listing; password portability cannot be proven — password users must reset or re-enter credentials on the target (29F).',
                'oauth_note' => 'OAuth-only accounts carry no password: provider identity can be mapped, but re-authorization with the provider on the target is expected.',
                'anonymous_note' => 'Anonymous accounts have no credential material; skeleton records can be created but sessions cannot transfer.',
                'hash_strategy' => 'not_exposed_by_listing',
            ],
        ];
    }

    /** Migration decision for one account — honest, no fake portability. */
    protected function decide(array $providers, bool $hasPassword): string
    {
        if ($hasPassword) {
            return 'NEEDS_REVIEW'; // password compat unprovable (29F)
        }
        if (in_array('anonymous', $providers, true)) {
            return 'NEEDS_REVIEW'; // no credential material to migrate
        }
        if ($providers !== []) {
            return 'MAPPABLE_WITH_PROVIDER_REAUTH';
        }

        return 'NEEDS_REVIEW'; // no provider information at all
    }

    /** Remove any forbidden credential-material keys from a raw payload. */
    protected function scrub(array $user): array
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($user[$key]);
        }

        return $user;
    }
}
