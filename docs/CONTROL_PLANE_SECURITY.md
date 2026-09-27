# Control-plane security review (18P)

## Tests performed (all passing, `tests/Feature/`)

- Guest → project pages redirect to login; non-admin → 403 everywhere.
- Cross-project user listings disjoint (A/B seeded, asserted both directions).
- Foreign table name through another project's browser → 404.
- Protected tables (`migrations`, `jobs`, …) refuse writes (403).
- Secret columns excluded; password hashes absent from rendered HTML.
- Log sanitizer redacts passwords/tokens/Bearer (unit + live-log assertions).
- Storage traversal (`..`, backslashes, null byte, nested escape) blocked.
- Download route: guest redirected to `/admin/login`; traversal refused.
- Monitor (`infra_monitor`) account: reads catalog, cannot CREATE/write.
- Artisan bridge: `db:wipe`/`tinker` → 403; allowlist only.
- Audit resource: create/edit URLs 404; index readable.
- Login surface renders over Livewire (vendor 5-attempt throttle); CSRF via framework stack.

## Failures found & fixed during Phase 18

1. Guest web hits 500 (`Route [login] not defined`) → `Authenticate::redirectUsing('/admin/login')`.
2. `queue:retry`/`forget` need UUID, not numeric id.
3. `pulse:check` scheduled would hang `schedule:run` (daemon) → removed from template + demo.
4. Greedy backup-filename regex captured the log prefix → tightened to `\S+`.
5. Filament page tables reject collections (must be Eloquent) → converted.
6. Dead custom login RateLimiter removed (Filament's own throttle is the control).

## Remaining risks

- File-upload AV scanning: none (24 MB cap + private-by-default only).
- `/pulse` and Horizon dashboards are powerful — owner-gated, keep them that way.
- Offsite backup target still unprovisioned (pre-existing Phase 17 blocker).
- No WAF/edge rate limiting yet (pre-existing).
