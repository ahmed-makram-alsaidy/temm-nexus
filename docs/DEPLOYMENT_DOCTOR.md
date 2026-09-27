# Deployment Doctor

`php artisan platform:doctor` diagnoses a running installation. A wrapper
(`./scripts/doctor.sh`) is not required — run it inside the app container:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan platform:doctor
docker compose -f docker-compose.prod.yml exec app php artisan platform:doctor --json
```

## What it checks (26.1D)

Version · APP_ENV · APP_DEBUG · APP_KEY presence · PostgreSQL connectivity +
version · Redis connectivity + version · storage writability · required
directories · queue worker (Horizon) status · scheduler heartbeat · Reverb
health · reverse-proxy pointer · public URL · HTTPS expectation · backup
destinations · last successful backup · last restore drill · disk free ·
pending migrations · platform initialization · first admin · setup lock ·
AI providers (optional) · mail (optional).

## Statuses

| Status | Meaning |
|---|---|
| PASS | verified healthy |
| WARNING | degraded or environment not production-hardened |
| FAIL | blocking problem — fix before operating |
| NOT_CONFIGURED | optional feature not set up (mail, AI, backups…) |
| NOT_APPLICABLE | not measurable from inside the application (edge config lives on the host) |

## Exit codes

| Code | Meaning |
|---|---|
| 0 | healthy (no failures, no warnings) |
| 1 | warnings or not-configured items |
| 2 | one or more FAIL — blocking |

## Secret safety

The doctor NEVER prints secret VALUES: APP_KEY, DB/Redis passwords, PATs and
AI keys appear only as presence/absence. This is covered by a regression test
(`tests/Feature/Phase261/DoctorTest.php`).

Each run is recorded in `platform_doctor_runs` (diagnostic counts only — no
secret values) so upgrade/rollback drills can be compared over time.
