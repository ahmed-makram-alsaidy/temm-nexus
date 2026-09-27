# First Run (Setup Wizard)

A fresh installation is deliberately empty: no projects, no demo data, no
default credentials. Everything starts at **/setup**.

## The gate

- Uninitialized platform: every URL redirects to /setup (admin login is not
  exposed until bootstrap completes).
- Health probes (/up, /api/health) stay reachable so the stack can be
  monitored during bootstrap.
- After completion: /setup locks permanently; reopening requires the
  authorized CLI command `php artisan platform:setup-reset` (typed
  confirmation; data is preserved).

## Steps

1. **Welcome & System Check** — PHP version/extensions, APP_KEY,
   PostgreSQL, schema, Redis, storage writability, queue, scheduler and
   Reverb status. Every check shows PASS/FAIL/INFO with an actionable
   detail; blocking failures must be fixed before continuing.
2. **Platform Identity** — display name + brand (generic defaults are safe).
3. **Database Verification** — server-side connectivity + schema check.
4. **Redis Verification** — ping/pong.
5. **Storage** — writability of runtime paths.
6. **Admin Account** — name/email/strong password (12+ chars, mixed case,
   numbers, symbols). Credentials are stored encrypted; the owner row is
   created exactly once at completion.
7. **Domain / URL** — platform URL; honest HTTPS explanation
   (see DOMAIN_TLS.md).
8. **Mail (optional)** — without SMTP, mail features report NOT CONFIGURED.
9. **Backup Configuration** — guidance; the Backup Center lives in the UI.
10. **AI (optional)** — BYOK explanation; keys are added later in the UI.
11. **Security Summary** — posture review + acknowledgement.
12. **Complete** — atomic bootstrap: owner created once, settings saved,
    wizard locked.

## Guarantees (tested)

- Refresh/retry never duplicates the admin or settings and never regenerates
  APP_KEY (idempotence).
- Two clients completing simultaneously: exactly one owner succeeds
  (cache lock + unique constraint + re-check in transaction).
- Replaying the completion request after completion is rejected.

## Admin recovery

Lost owner password — server CLI, audited, same password policy:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan platform:admin-password <email>
```
