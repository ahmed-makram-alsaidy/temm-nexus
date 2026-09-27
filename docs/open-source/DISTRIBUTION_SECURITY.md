# Distribution Security

What the self-hosted distribution guarantees, and how it is verified.

## Verified properties (every RC)

| Property | Mechanism |
|---|---|
| No default credentials | first-run wizard only; seeder disabled without explicit opt-in |
| Secrets encrypted at rest | Laravel encrypted casts (PATs, AI keys, project secrets, settings) |
| Databases not host-published | production compose: no ports on postgres/redis; internal networks |
| Minimal edge surface | only Caddy publishes 80/443; security headers; request size cap |
| Rate limiting | login (Filament), setup 30/min, API 60/min, machine endpoints |
| Setup takeover defense | cache lock + unique constraint + transactional re-check (tested) |
| Setup replay defense | /setup locked after bootstrap; authorized reopen via CLI only |
| No telemetry | PRIVACY.md; verified by scan |
| Secret scan | scripts/security/secret-scan.sh (CI gate) |
| Private-reference scan | scripts/release/private-ref-scan.sh on the packaged artifact (CI gate) |
| Admin recovery | CLI-only password reset, audited, strong-password enforced |

## False positives (documented for the scans)

- Test fixtures asserting secrets are REJECTED (they contain token-shaped
  strings on purpose) — filtered by the "test/never stored" markers.
- .gitignore/.dockerignore rules naming `.env` are rules, not secrets.
- Phase 24/25 fixtures referencing machine paths do so as traversal-eviction
  test vectors.

## Reporting

See SECURITY.md at the repository root.
