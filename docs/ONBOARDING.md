# New project onboarding (Phase 13)

## Workflow

```sh
sh scripts/create-project.sh <slug>          # kebab-case, e.g. project-a
# ... work ...
REMOVE_CONFIRM=<slug> sh scripts/remove-project.sh <slug>
```

`create-project.sh` (idempotent — safe to re-run; skips completed steps):

1. `composer create-project laravel/laravel:^13` (dockerized composer)
2. requires Sanctum/Horizon/Pulse/Reverb (pins in `projects/_template/composer.template.json`)
3. runs `install:api`, `horizon:install`, `reverb:install`, publishes Pulse config/migrations
4. copies `projects/_template/overlay/` (health, audit, rate limits, gate test…)
5. creates `<slug>_db` + `<slug>_user` (random 32-hex password, least-privilege)
6. writes `.env` (0600) from `projects/_template/.env.example` (redis prefix, queue names)
7. `migrate --force` + `TemplateGateTest` (must pass)
8. registers the project in owner-console + appends a managed Caddy stanza

`remove-project.sh` (needs `REMOVE_CONFIRM`): safety backup → drop DB+role →
unregister → strip Caddy stanza → delete dir. Backup file is retained.

## GATE 13 evidence (2026-09-16, live local run, `gate13demo`)

- Full `create-project.sh gate13demo`: steps 1–8 green, `TemplateGateTest`
  **4 passed**, row `gate13demo|gate13demo_db|gate13demo` in owner-console,
  Caddy stanza appended and `caddy validate` → Valid ✅
- `remove-project.sh` without confirm → correctly refused ✅
- With confirm: backup retained (`gate13demo_db_*.dump` 36 KB), DB+role dropped,
  row deleted, dir deleted, stanza stripped, Caddy still Valid ✅

## Automation bugs found & fixed during the gate (all in committed scripts)

1. Unquoted `$PHP` helper split Windows paths with spaces → shell **function**.
2. `reverb:install` prompts (required validation) and aborts on closed stdin →
   `docker -i` + piped newline + `--no-interaction` on all installers.
3. `DC="docker compose -f $ROOT/…"` string split on spaces → `dc()` function.
4. Missing `MSYS_NO_PATHCONV=1` in `remove-project.sh` rewrote `/scripts/…`
   container paths → exported (same class of fix documented for `docker run -p`
   and port publishing in Phases 5/7: always verify on this host).
5. Caddy stanza strip used exact match vs suffixed BEGIN marker → prefix match.
