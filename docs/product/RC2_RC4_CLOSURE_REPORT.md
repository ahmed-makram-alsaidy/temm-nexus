# v0.4.0-rc.2 → rc.4 CLOSURE + PUBLICATION + VPS ACCEPTANCE REPORT

**Date:** 2026-10-03 · **FINAL STATUS: V0.4.0_RC4_PUBLISHED_AND_ACCEPTED**
(v0.4.0-rc.2 closure itself = REQUIRES_FURTHER_RC — see §1; corrected per policy by rc.4)

**Do not publish v0.4.0 stable.**

---

## 1. RC.2 CLOSURE — shipped, then superseded

The three scoped rc.1-acceptance fixes were implemented, tested (667-test blocking suite 0F/0E),
built, tagged `v0.4.0-rc.2`, published as a GitHub pre-release, download-back-verified (SHA
`9dcab90f…` matched all four records), and merged to main with green CI.

**The VPS acceptance then found a release-blocking defect** in the shipped rc.2 code: Home and
Project Overview returned **HTTP 500** for any project whose CDC checkpoint carries a
`last_event_at` value — `ProjectPulse::checkpoint()` reads the row via the query builder, the
timestamp arrived as a raw string, and `syncLagSeconds()` called `->diffInSeconds()` on it.
(An intermediate rc.3 tag was created locally for this fix alone and deleted un-pushed when a
SECOND defect of the same class surfaced — nothing public was modified.)

Per the release-correction policy: **rc.2 stays frozen, tagged, and published**; rc.4 supersedes it.
The rc.2 closure verdict on its own terms: **REQUIRES_FURTHER_RC**.

## 2. RC.4 — the corrected release line

`release/0.4.0-rc.4` = rc.2 + the two hotfixes (commit `2547401`), each with a regression in
`ProjectPulseCheckpointTest`:
1. `last_event_at` normalized before lag measurement (no `->diffInSeconds()` on a raw string);
2. blocked-run `failure` payloads (model-cast arrays) reduced to readable text instead of
   `mb_substr()` on an array.

**No other changes.** The rc.2 three fixes (launcher/Inspect layout, docs packaging allowlist +
`dist-docs.test.sh`, attention pluralization) are all included.

- Blocking suite: **667 tests / 3664 assertions / 0 failures / 0 errors / 22 skips** (skips =
  the documented skip-clean wizard suite + environment self-skips; CI runs them with real Redis)
- Security: secret scan PASS · private-ref scan PASS · `composer audit` **0 advisories** ·
  support-bundle + AI redaction tests green in suite
- CI on the release commit (`2547401`): **success** — hermetic suite (BLOCKING), fresh-install
  smoke, production image build, SDK ×2, secret/private-ref scans, script tests
  (note: GitHub's `pull_request` trigger silently produced no runs for PR #6/#7; `workflow_dispatch`
  was added to `ci.yml` and the release run was dispatched directly — run 37067918714)
- Tag: `v0.4.0-rc.4` annotated — object `91aef2139acfbbaa38a214af73c1abfc35c6dab8` → commit
  `2547401cc1852a91a68ff550bce841173abf2460`
- Publication: **https://github.com/ahmed-makram-alsaidy/temm-nexus/releases/tag/v0.4.0-rc.4**
  (Pre-release = YES, stable = NO); assets: artifact + `.sha256` + SHA256SUMS + manifest
- Download-back: SHA `bfead8c1f50ae13a8df24cc8fa631b07cb1f7301052034158a052058a2a0e821`
  **MATCH** on all four records; extracted VERSION = 0.4.0-rc.4; secret + private-ref scans clean
- main: PR #9 merged (`af4be315…`), **main CI success**

**Artifact content check:** VERSION = 0.4.0-rc.4; Inspect Mode, Nexus AI, safe actions, workspace
migrations, connector manifests, frontend assets, and ALL required 0.4.0 docs present (the rc.2
allowlist fix verified in the shipped file); no `.env`/credentials/SSH material/runtime
DBs/customer data/private paths.

## 3. VPS DEPLOYMENT + ACCEPTANCE (temm-test.ahmed-alsaidy.online)

Disposable Azure test VM (`temm-nexus-test`), pre-RC.4 state recorded (version 0.3.0, Doctor
18-pass/0-fail, containers healthy, manual platform backup `pre-rc2-manual.sql.gz` taken).
Upgraded **using the actual published GitHub rc.4 artifact** (downloaded ON the VPS from the
release URL, SHA-verified `bfead8c1…` before overlaying; `.env`, `backups/`, `storage/` preserved;
the upgrader took its own pre-upgrade backup). 0.3.0 → rc.2 → rc.4 across two supported
`upgrade.sh` runs (21 migrations applied).

| Check | Result |
| --- | --- |
| Version | **0.4.0-rc.4** |
| HTTPS (real cert on the domain) | **PASS** |
| Login (existing users preserved) | **PASS** |
| Doctor | **19 pass · 0 warnings · 0 failures** |
| Containers | 7/7 healthy |
| Frontend assets (Filament + nexus + inspect) | **PASS** |
| Setup lock | preserved |
| Data | **39 projects / 1 default workspace / users preserved** |
| Caddy | recreated by the upgrader on app recreation; HTTPS works |
| Home/Overview with CDC-telemetry projects | **no 500** (the rc.4 hotfix proven on the exact data that triggered it) |
| Inspect/launcher overlap | **0** on every screen at every viewport |
| Browser QA | 11 screens × 3 viewports + flows: **110/111 then 4/4 post-reboot** (the single fail was a screenshot-timing race; the screenshot itself shows "Applied and verified") |
| Nexus AI platform/workspace/project scopes | PASS (real turns on the VPS) |
| Inspect → AI handoff, Explain | PASS |
| Safe actions (propose → cancel = no mutation → propose → approve → apply → verify) | PASS, audited (`AI_ACTION_PROPOSED/APPROVED/APPLIED/VERIFIED` on the VPS ledger) |
| Execution-time authorization | PASS — a workspace viewer was denied `approveAndExecute` (`not_authorized`) |
| CDC ×3 | PostgreSQL **PASS** (snapshot→slot→mutate→capture→verify, deltas 0) · MySQL **PASS** (15,000-event storm, deltas 0, IDs match) · MongoDB **PASS** (change stream via resume tokens; snapshot edge cases remain documented limitations) |
| Backup | PASS — pre-upgrade + manual platform backups on the VPS; project backup destinations remain not-configured (honest Doctor state) |
| Scheduler / queues | PASS — scheduler + horizon containers healthy, Doctor checks green |
| Controlled reboot | **PASS** — all containers auto-started healthy, HTTPS returned, login worked, version/data intact, Doctor 19-pass/0-fail |

PRODUCTION/CUSTOMER SYSTEMS TOUCHED: **NO** (only the disposable test VPS).

## KNOWN LIMITATIONS (unchanged, honest)

Firebase CDC DEFERRED · MariaDB PARTIAL · MongoDB snapshot edge cases with certain document
shapes (change streams proven; engine unchanged where not fixed) · one-person action approval
(15-min TTL + double-capability bound) · `create_backup`/`retry_failed_job` need operator-side
runtime · conversations are per-session · SetupWizardTest skip-cleans on phpredis-less hosts
(CI runs it fully) · GitHub's `pull_request` trigger silently failed during this closure
(workflow_dispatch workaround added, documented).

BLOCKERS: **NONE**

FINAL STATUS: **V0.4.0_RC4_PUBLISHED_AND_ACCEPTED**

DO NOT PUBLISH v0.4.0 STABLE. STOP.
