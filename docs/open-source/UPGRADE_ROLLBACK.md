# Upgrade & Rollback Boundaries

Honest rules — no fake one-command rollback where the schema is involved.

## Upgrade path

./scripts/upgrade.sh: health + disk pre-checks → pre-upgrade pg_dump →
image build → `php artisan migrate --force` (NEVER migrate:fresh) →
restart → health verify. Project data is preserved; version comes from the
root VERSION file.

## Application rollback (safe, supported)

```bash
git checkout <previous-tag>
./scripts/upgrade.sh     # or: docker compose -f docker-compose.prod.yml up -d --build
```

Older image + restored service health; data volumes untouched.

## Database changes are NOT auto-reversible

- Forward-only migrations: `migrate --force` applies; there is no automatic
  `migrate:rollback` on the platform database as part of upgrades.
- If a schema change must be undone: restore the pre-upgrade backup
  (BACKUP_RESTORE.md). This is the documented path; the upgrader prints it
  when post-upgrade health fails.
- Downgrading the application across a schema change therefore requires the
  restore too — check the release notes' "Upgrade notes" for schema-touching
  releases.

## Version compatibility

- VERSION file is canonical (displayed in the console footer).
- Each release notes its minimum supported upgrade version; upgrades across
  multiple releases follow the documented chain.
