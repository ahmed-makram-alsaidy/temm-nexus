# TEMM Nexus v0.6.4

**Stable patch — 2026-11-25.**

- **SQL Editor:** Run works again in read-only and write mode. The CodeMirror files the page loads were never shipped (they sat in a `vendor/` folder, which git, Docker and the release package exclude for Composer), so the page script stopped before wiring any button. CodeMirror 5.65.21 (MIT) now ships in `public/js/codemirror`, and the editor falls back to a plain text box if the library cannot load.
- **Storage Studio:** no more HTTP 500. Without `CONTROL_PLANE_REPO_ROOT`, per-project storage now lives in the app's persistent storage instead of the unwritable `/`, and an unusable root reports 503 clearly. Projects whose storage is a managed Supabase-compatible runtime use its storage API with the active environment's vaulted credentials; missing credentials fail closed and backend errors stay private.
- **ERD:** the diagram no longer stops at `t is not a function` after drawing the tables; the asset version is bumped so browsers load the fix.
- **Logs:** successful sign-ins are logged for interactive requests only, so scheduled scripts no longer write a warning every minute.
- **Logs survive upgrades:** `storage/logs` is now a named volume shared by the app, Horizon, the scheduler and Reverb; before, every container recreation started from an empty log.
- **Release safety:** unit tests fail when a page references a static file that is missing from `public/` or sits in a folder that shipping leaves out.

No schema migration is required. Published v0.6.3 remains unchanged.
