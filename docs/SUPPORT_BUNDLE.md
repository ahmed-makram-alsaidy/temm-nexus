# Support Bundle

`php artisan platform:support-bundle` creates a sanitized diagnostic archive
for troubleshooting with maintainers. Run it inside the app container:

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan platform:support-bundle --output=/backups/support.zip
```

(`/backups` in the container maps to `backups/data` on the host — copy the
bundle out from there.)

## Contents

| File | Contents |
|---|---|
| `manifest.json` | version, environment, PHP/Laravel versions, generation time |
| `doctor.json` | full `platform:doctor --json` output (already secret-safe) |
| `migrations.json` | migration run summary + pending list |
| `runtime.json` | loaded extensions, storage-path presence, disk free, hostname |
| `queue.json` | queue connection, pending/failed job counts |
| `backups.json` | destination count, last backup status, verified restores |
| `environment-keys.txt` | configuration key NAMES present — never values |
| `recent-errors.txt` | last 40 log ERROR lines, truncated + secret-shaped strings redacted |

## Never included

`.env` values · passwords · API keys · PATs · tokens · password hashes ·
customer data · database dumps · private file contents · AI prompts.

## Redaction guarantee

The bundle's collectors redact token-shaped strings (`sbp_`, `sk-`, `ghp_`,
`AIza…`) and `KEY=value` pairs before any log line enters the archive, and a
regression test plants fake secrets and scans the produced archive for zero
leakage (`tests/Feature/Phase261/SupportBundleTest.php`).
