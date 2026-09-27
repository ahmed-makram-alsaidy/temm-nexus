# Secrets Vault

> Route: `/admin/projects/{record}/secrets` (extended) · Permission: `secrets.manage`

Built on the Phase 20M encrypted store (`project_secrets.value` cast
`encrypted`, key = APP_KEY on the server, never in the DB) with Phase 24F
extensions: `environment_id`, `category`, `version`, `status`,
`created_by/updated_by`, `rotation_meta`.

## Categories (24F.3)

`database, redis, storage, oauth, webhook, whatsapp, ecommerce, payment,
partner, application, custom` — enforced at creation.

## Never-return-value contract (24F.1 / 24F.4)

- Encryption at rest is asserted by test (ciphertext contains no plaintext).
- Listings never include the value; `SecretVaultService::listingRow` shape is
  tested (`assertArrayNotHasKey('value', …)`).
- Masking: `sk_l…E123`-style prefix/suffix, `********` for short/unknown.

## One-time reveal (24F.2)

`SecretVaultService::reveal` requires `secrets.manage` and writes a
`SECRET_REVEALED` audit entry. The normal UI remains write-only (create /
rotate / delete) — values are never rendered back.

## Rotation (24F.5)

`rotate` bumps `version`, records `previous_version` / `previous_rotated_at`
in `rotation_meta` and audits `SECRET_ROTATED`. External provider credentials
are never rotated automatically — rotation here means "operator supplies the
next value".

## Access control (24F.6)

Every sensitive action goes through `CpAccess::require(user, 'secrets.manage')`;
observer/test roles are proven 403 in `SecretsVaultTest`. All actions audited
(`SECRET_CREATED/ROTATED/REVEALED/DELETED`).

## Leak scan (24F.7)

`SecretVaultService::leakScan(project)` scans owner-console logs and the last
500 audit metadata payloads for raw secret values (≥6 chars). Returns counts
and findings **without echoing values**. The test plants a leak in audit
metadata and proves detection.
