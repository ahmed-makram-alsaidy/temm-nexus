# Production security hardening (Phase 11) — VPS runbook, NOT applied locally

Execution order on a fresh Ubuntu 24.04 VPS (see `deploy/production/bootstrap.sh`):

## 1. SSH
- Key-only auth: `PasswordAuthentication no`, `PermitRootLogin no` (or
  `prohibit-password` during migration week), `ChallengeResponseAuthentication no`.
- Non-standard port optional (security-by-obscurity + log-noise reduction, not a control).
- `AllowUsers deploy` (single deploy user, sudo without password only for docker).

## 2. Firewall (UFW) — default deny incoming
- Allow: 22 (or custom SSH), 80, 443. NOTHING else (no 5432/6379/5050/8025).
- `deploy/production/ufw.rules` documents the exact set.

## 3. Fail2ban
- `sshd` jail + Caddy 401/429 jail reading Caddy JSON logs. Enabled in bootstrap.

## 4. OS updates
- `unattended-upgrades` for security pockets; Docker CE pinned major, upgraded manually.

## 5. Docker
- Daemon `log-driver json-file` + rotation (in compose already), `no-new-privileges`
  where images support it, user namespaces left default (documented trade-off),
  socket never mounted into app containers.

## 6. Data layer
- Postgres/Redis: no published ports, internal-only networks (verified Phase 2/7),
  per-project roles + CONNECT isolation (Phase 3), `infra_monitor` read-only (Phase 6).
- Redis `requirepass`, `noeviction`, AOF.

## 7. Management surfaces
- pgAdmin: Cloudflare Access or VPN (Phase 7). Owner console: admin-only Filament
  behind HTTPS + HSTS; Horizon/Pulse routes gated to `is_admin`.

## 8. Secrets
- `.env` files 0600, never in git (`scripts/security-check.sh` in CI/pre-commit):
  scans tracked files for `BEGIN PRIVATE KEY`, `AKIA`, `password\s*=\s*['\"][^'\"]{4,}`
  outside `.example` files, and fails on any `.env` (non-example) being tracked.
- Laravel `APP_DEBUG=false`, `APP_KEY` from `key:generate --show` into secrets file.

## 9. Laravel production flags (deploy script asserts)
- `APP_ENV=production DEBUG=false`, `config:cache route:cache view:cache`,
  `opcache.validate_timestamps=0` + `php-fpm reload` on deploy, storage perms
  `www-data`, scheduler + Horizon supervised.

## 10. Backups encryption (consideration → decision at deploy)
- rclone `crypt` remote over the S3 remote for DB dumps (cheap, no key server).
  Documented in `docs/BACKUP_DR.md` flow; key stored with VPS provider console,
  NOT in repo.

## GATE 11 — review

Completed controls: image pins, no `latest`, internal DB networks, per-project DB
isolation proven, redis auth+namespaces proven, loopback-only admin ports, secrets
hygiene enforced by `security-check.sh` (run below), least-privilege roles.

Run: `sh scripts/security-check.sh` → must print OK.

Unresolved risks (accepted for local stage, must close before VPS):
1. Offsite backup target not yet provisioned (R2/B2 account + rclone crypt key).
2. Cloudflare Access / VPN for pgAdmin + owner console not yet configured (no VPS/DNS yet).
3. No WAF/rate-limit at edge yet (Laravel throttles only) — acceptable at ~300 users.
4. Container image vulnerability scanning not automated (add `docker scout` to deploy).
5. Secrets rotation cadence defined in runbook but never exercised end-to-end.
