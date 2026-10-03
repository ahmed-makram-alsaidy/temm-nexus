# v0.4.0-rc.6 Closure Report — REQUIRES_NEW_RC (stable v0.4.0 not published)

**Date:** 2026-10-03
**Status:** `V0.4.0_RC6_PUBLISHED` — stable `v0.4.0` remains UNPUBLISHED pending an rc.7
**Base:** v0.4.0-rc.5 (frozen, untouched: tag `a28dc59f` → commit `88e525b`, verified unchanged before and after this closure)

## Why stable stopped (bug policy invoked)

The v0.4.0 stable closure (release/0.4.0 @ `33c5dd4`, metadata-only over rc.5,
diff verified: VERSION + CHANGELOG + release notes + SBOM only) ran the full
gate set: rc.5 integrity PASS, hermetic suite 717/3957/0F/0E on CI Linux,
localization 26/26 PASS, AI + wizard regression PASS, security scans PASS,
artifact `platform-0.4.0-20261003-171103.tar.gz` (SHA-256 `54ab94b3…7104`,
hash computed 3× identical), artifact scan PASS, CI 2× GREEN on the branch.

The **artifact-only fresh-install gate failed**: on a fresh install there is
NO UI path to create the first workspace —

- `Workspaces::canAccess()` (app/Filament/Pages/Workspaces.php:64) required
  `accessibleWorkspaces()->isNotEmpty()` → 403 at zero workspaces;
- workspace creation exists ONLY on that page (Workspaces.php:169);
- the New Project wizard requires an existing accessible workspace
  (NewProjectWizard.php:272);
- the setup wizard seeds no workspace; the classic create form has no
  workspace field (the known 0.4.0 gap).

Per the bug policy stable publication was STOPPED (no tag, no release, no
main merge, no VPS stable deploy) and **rc.6** was created.

## rc.6 — fix + verification

Branch `release/0.4.0-rc.6` from `88e525b`:

- `3445977` fix(0.4.0-rc.6): fresh-install workspace bootstrap — entry also
  granted to holders of the `workspaces.create` platform capability; everyone
  else stays fail-closed. Regression test `WorkspaceBootstrapAccessTest`
  (owner-200 / plain-user-403 / create-action-visible).
- `7d966fb` chore(release): 0.4.0-rc.6 metadata (VERSION, CHANGELOG).

Verification:

- Hermetic suite: local 720/3878/0F/0E; **CI Linux 720 tests / 3961 assertions
  / 0 failures / 0 errors** (run 37130995379, all 8 jobs green).
- Artifact `platform-0.4.0-rc.6-20261003-174942.tar.gz`, SHA-256
  `5e52d77b2469bf906b2ad88580e8b181268495e113cc188f26411b88e3c99eb3`
  (sha256sum == certutil), manifest + SHA256SUMS + CycloneDX SBOM (166 comps),
  private-reference scan PASS, secret scan PASS, composer audit 0 advisories.
- Published pre-release: https://github.com/ahmed-makram-alsaidy/temm-nexus/releases/tag/v0.4.0-rc.6
  (tag object `029981d6` → commit `7d966fb`; 5 assets).
- Download-back: downloaded SHA == `.sha256` == SHA256SUMS == local; extracted
  VERSION `0.4.0-rc.6`; fix present; secret scan clean.
- Artifact-only fresh install (isolated compose project, ports 8091/8445):
  12-step setup (EN) → first admin → setup lock → login → **Workspaces page
  reachable at zero workspaces (the fix)** → first workspace created →
  New Project wizard FULL flow against a disposable PostgreSQL source:
  source cards, Test Connection honest SSRF-guard failure AND real success
  (read-only session enforced), TEMM-managed destination default, real
  analysis (1 table, "no blocking issues"), review summary, dry-run start →
  project created WITH workspace assignment (Workspaces shows it inside).
- Nexus AI Settings render (real providers only, masked keys); Arabic RTL
  verified with reload persistence (dir=rtl, lang=ar) — local + VPS.
- Local rc.5-tree → rc.6 upgrade drill: backup taken by upgrader, users /
  audit / 22 migrations preserved, fix active on the upgraded tree, footer
  v0.4.0-rc.6.
- Disposable acceptance VPS (temm-test.ahmed-alsaidy.online): explicit
  pre-upgrade dump (458 KB) + counts BEFORE (4 users / 1 workspace /
  46 projects / 606 audit / 30 CDC checkpoints) → published rc.6 artifact
  downloaded ON the VPS, SHA-verified, upgrader run (its own backup kept) →
  counts AFTER identical, Doctor 19-pass / 0-fail, HTTPS 200 (login, cp.css,
  filament js, fonts), EN/AR switch + RTL + persistence verified in browser
  over HTTPS with real data, no 500s in logs. Temporary smoke admin created
  for the login check was deleted afterwards (audited trail).
- Bounded CDC smoke (disposable rig temm356-*): **PostgreSQL WAL PASS**
  (15,009 events captured+applied, deltas 0/0/0, cdc_lag PASS, cleanup clean),
  **MySQL binlog PASS** (15,007 events, deltas 0, ids_all_match, GTID
  evidence, cdc_lag PASS), **MongoDB change streams: DEFECT FOUND (below)**.
- Controlled reboot: all 7 containers auto-recovered healthy, HTTPS 200,
  Doctor 19-pass/0-fail, version + data intact.

## NEW BLOCKER (pre-existing, unexercised) — requires rc.7

**MongoDB change-stream CDC: checkpoint signature verification fails when
real events drain.** With a clean rig: `setup` → `establish` (resume token,
HMAC ok, 0 events) → ANY real event (reproduced with 9 events; also with a
10,000-event storm) → next checkpoint load throws
`CdcCheckpointTampered: checkpoint for run N failed signature verification`
(CdcCheckpointManager.php:75). `stream_status` becomes `error`; applied
stays 0. Zero-event checkpoints verify fine; the PG and MySQL checkpoint
payloads round-trip through the same manager without issue, so the Mongo
position payload is not signature-stable across the JSONB round-trip
(save→load) once real stream data lands in it.

- NOT an rc.6 regression: the rc.5→rc.6 diff contains zero CDC code
  (Workspaces.php + one test file). The defect is pre-existing at least since
  the accepted rc.5 codebase; the Phase 35.6 rehearsal harness could never
  hit it (its `mongo-mutate.sh phase2` storm fails silently on shell quoting,
  and phase1 seed events always predate the stream establishment).
- Tamper-evidence itself works as designed (tampered resume token refused:
  `CdcCheckpointTampered` twice).
- Suggested rc.7 scope: canonicalize the checkpoint position payload for
  signing (normalize types before `sign()`/persist), plus a regression test
  that drains real Mongo events through establish→mutate→drain→verify.

## Operator-environment notes

- The first local installer attempt ran before `COMPOSE_PROJECT_NAME`
  isolation and recreated the operator's local `backend-plane`
  postgres/redis containers (same images, same volumes, both healthy, app
  untouched; no image rebuild happened).
- `CDC_ALLOW_SOURCE_SETUP=true` was added temporarily to the VPS `.env` for
  the bounded disposable-rig CDC smoke and removed afterwards (verified 0
  occurrences); app+horizon restarted healthy.
- Local disposable test stacks remain (v040fresh :8090 upgraded to rc.6,
  v040rc6 :8091, source container rc6-smoke-pg :15432) for reproduction.

## Publication state

- v0.4.0 stable: **NOT published** (no tag, no release, main untouched).
- v0.4.0-rc.6: published pre-release, CI green, all gates green except the
  pre-existing Mongo CDC defect above.
- Stable may proceed only after rc.7 fixes the Mongo CDC checkpoint defect
  and passes acceptance.
