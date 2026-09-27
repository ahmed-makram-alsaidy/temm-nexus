# Upgrading the Platform

Safe, boring upgrades. The rules: never upgrade blind, always have a backup,
and use normal migrations only.

## Standard upgrade

```bash
./scripts/upgrade.sh            # checks, backup, build, migrate, verify
./scripts/upgrade.sh --check    # pre-upgrade report only
```

1. **Check out the new release** (tag or commit):
   `git fetch --tags && git checkout vX.Y.Z`
2. **Run the upgrader.** It verifies stack health and disk, takes a
   pre-upgrade database backup, builds the new image, applies Laravel
   migrations (`php artisan migrate --force` — **never** `migrate:fresh`),
   restarts services and verifies health.
3. If post-upgrade health fails, the script prints the rollback paths and
   exits non-zero.

## What the upgrader guarantees

- A backup exists before migrations run (or a loud warning + explicit
  `UPGRADE_FORCE=1` is required to proceed without one)
- Project data is preserved — the upgrader never recreates or truncates
  databases
- Rollback paths are printed, not faked (see
  [docs/open-source/UPGRADE_ROLLBACK.md](docs/open-source/UPGRADE_ROLLBACK.md))

## Version compatibility

- The platform version lives in the root `VERSION` file and is displayed in
  the console footer and on the welcome page.
- Each release documents its **minimum upgrade-from version** in the release
  notes. Upgrading across multiple releases is supported when the minimum
  upgrade path allows it; otherwise upgrade to intermediate releases first.
- Schema migrations are forward-only. Downgrades are restores, not rollbacks.

## Manual upgrade (equivalent steps)

```bash
git fetch --tags && git checkout vX.Y.Z
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml run --rm migrate
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps    # verify healthy
```

Take a backup first — see [BACKUP.md](BACKUP.md).
