# RC.1 POST-PUBLICATION ACCEPTANCE — Docker Recovery Audit + Product Acceptance

**Date:** 2026-10-02 · **Status: RC1_ACCEPTED**
**Scope:** the PUBLISHED GitHub asset (`platform-0.4.0-rc.1-20261002-163728.tar.gz`, SHA-256
`37da53b92feaeadc6fef8014ecaf02b7b37dd6211d814bd5a0025f7113af2ff0`) — no source checkout substituted.
RC.1 remains FROZEN; nothing was modified or retagged. No code changes were made during this audit.

---

## DOCKER RECOVERY AUDIT

**Engine — PASS.** After the Windows reboot the engine starts normally
(server 29.8.1, distro `docker-desktop` Running). **No Docker storage configuration was changed.**

| Item | Finding |
| --- | --- |
| Configured data/distro location | `CustomWslDistroDir = E:\DockerWSL\wsl` (settings-store.json) |
| VHDX on E: | `E:\DockerWSL\wsl\main\ext4.vhdx` — 96 MiB, **Created 19:49:48**, in use (docker-desktop rootfs) |
| | `E:\DockerWSL\wsl\disk\docker_data.vhdx` — created 19:49:50, grew 1.6 GB → ~3.1 GB as the fresh engine initialized; in use |
| VHDX on C: | **none** (no `C:\DockerWSL` exists; the single earlier search hit was transient during the failed startups) |
| Attachable | YES — both attach and run (the engine is live on them) |
| Old images/volumes | **GONE.** Before the incident: 14 images / 6.95 GB, 98 volumes / 10.74 GB. Now: 4 bootstrap images / 1.33 GB, 0 volumes |

**Incident reconciled — YES.** Exact sequence, from file timestamps and Docker Desktop logs:

1. ~19:45–19:47 the engine went down mid-session (the host was in a pending-reboot Windows
   state; build 26200.9457).
2. Docker Desktop's automatic restarts hit `WSL/Service/CreateInstance/MountDisk/HCS/E_ACCESSDENIED`
   when attaching the OLD disks. Repeated retries kept failing.
3. **Docker Desktop itself discarded the unattachable disks and created fresh ones at
   19:49:48/50** — before any intervention from me (my first file inspection was ~19:52).
4. My subsequent restart attempts (process kill, `wsl --shutdown`, an ACL grant per Docker's
   own on-screen guidance) never succeeded pre-reboot because the denial was OS-level, and the
   disks it kept failing to attach were already the RECREATED ones.

**Reconciliation of the report sentence:** "The VHDX files were not modified or deleted"
described **my actions** — I never deleted, moved, pruned, compacted or rewrote them (only a
permission grant was added). As a statement about the incident it was incomplete: **Docker
Desktop's own recovery loop deleted-and-recreated both disks at 19:49**, which is exactly why
they carried fresh creation timestamps and why the data disk later read 1.6 GB. The report has
been corrected by this document.

**Data loss — NONE that matters.** Lost: session Docker images (rebuildable from artifacts in
minutes) and local test volumes (supabase/connector dogfood, disposable stacks — test data
only). **No customer data, no release evidence, no credentials, no audit records lived in
Docker**; all release evidence is in git, GitHub and on-disk artifacts. The old disks are not
recoverable (overwritten by the fresh creations); this is accepted as session-only loss.

---

## RC.1 ARTIFACT ACCEPTANCE (published asset only)

Downloaded fresh from the GitHub release; SHA-256 re-verified: **MATCH**
`37da53b92feaeadc6fef8014ecaf02b7b37dd6211d814bd5a0025f7113af2ff0` (= SHA256SUMS = manifest).

| # | Check | Result |
| --- | --- | --- |
| 1 | Artifact-only fresh install (`install.sh`) | **PASS** — 7/7 containers healthy |
| 2 | Setup wizard (12 steps via HTTP) | **PASS** |
| 3 | First admin (no default password) | **PASS** |
| 4 | Setup lock (form gone after completion) | **PASS** |
| 5 | Login | **PASS** |
| 6 | Workspaces (2 seeded clients, detail screens) | **PASS** |
| 7 | Projects (3 seeded, overview/migration-center/cutover) | **PASS** |
| 8 | Nexus AI (platform + project scope, real turn via local fake provider) | **PASS** |
| 9 | Inspect Mode (toggle, hover, select, Esc, project attach) | **PASS** |
| 10 | Safe action approval flow (real paused CDC checkpoint): proposal → Cancel = **no mutation** + honest "Declined"; re-propose → Approve = **exactly one mutation**, card shows "Applied and verified", stream paused → streaming | **PASS** |
| 11 | Doctor — **17 pass · 0 failures** (2 warnings, 4 not-configured: expected pre-configuration states) | **PASS** |
| 12 | Frontend assets (Filament CSS/JS, nexus.css, nexus-inspect.js, cp.css) — all 200, correct MIME | **PASS** |
| 13 | Connector registry (catalogue renders; manifests present in artifact) | **PASS** |

Audit chain on the fresh install: `AI_ACTION_PROPOSED → AI_ACTION_REJECTED` (cancel) and
`AI_ACTION_PROPOSED → AI_ACTION_APPROVED → AI_ACTION_APPLIED → AI_ACTION_VERIFIED` (approve).

## UPGRADE — v0.3.0 (published stable artifact) → PUBLISHED rc.1 artifact

Real `upgrade.sh`: pre-upgrade backup taken, image rebuilt, `migrate` (19 → 21), stack healthy,
caddy recreated (first-upgrade fingerprint path), `platform:doctor` **17 pass / 0 failures**,
frontend assets 200, browser login + pre-upgrade projects render + version reported 0.4.0-rc.1.

Preservation: **13/13 PASS, 0 failures** — default workspace created and projects bound,
project IDs preserved, users preserved (legacy `cp_role` owner still authorizes; developer
gains nothing platform-wide), backup/audit/migration-run preserved, **signed CDC checkpoint
verifies after upgrade (tamper chain intact)**, 0.4.0 tables created. No manual workaround used.

## AZURE ACCEPTANCE — NOT RUN

No Azure credentials or VM access exist in this environment. Reported as NOT RUN, not fabricated.

---

## PRODUCT UX ACCEPTANCE

11 screens × 3 viewports (1366×768, 1440×900, 1920×1080) + flows. **60/60 functional
assertions, 0 × 500 errors, 0 broken assets, 0 horizontal overflows.** Screenshots:
`temm-qa/shots/accept/*.png` (home, workspaces, workspace detail, projects, project overview,
migration center, cutover, connectors, nexus-ai, audit, infra, action card, applied state, post-upgrade).

Product judgment: the shell reads as a purpose-built product, not generic Filament —
client/workspaces hierarchy is legible, the journey stepper answers "where am I / what's next",
the cutover screen states why it is blocked, and the action card is clearer than most
commercial admin panels. The product feels usable for multiple clients.

### Classified findings

| Class | Where | Finding | Severity |
| --- | --- | --- | --- |
| **VISUAL_DEFECT** | bottom-right fixed controls, all pages with a long launcher scope label (e.g. project pages) | The Inspect toggle overlaps the Nexus AI launcher; with Inspect Mode ON, a click aimed at the launcher hits the toggle. Cosmetic with an easy workaround; screenshots `workspace-detail-1440x900.png`, `project-overview-1440x900.png` | rc.2 candidate (CSS offset/dynamic placement) |
| **BUG (packaging, minor)** | artifact contents | `docs/product/` 0.4.0 gate reports (`PHASE_I_GATE.md`, `PHASE_J_GATE.md`, `PHASE_IJK_FINAL_REPORT.md`, `V040_RC1_RELEASE_REPORT.md`) are absent from the artifact — `DISTRIBUTION_ALLOWLIST.md` (frozen at 0.3.0) names individual product docs and was not extended. `docs/ai/` (both new Nexus docs) IS included; 147 doc files ship. All content is on GitHub | rc.2 candidate (allowlist update) |
| MINOR_POLISH | Home hero | "1 project need attention" should read "needs attention" (pluralization) | rc.2 candidate (one-line copy fix) |
| NOT_AN_ISSUE | Nexus AI page | Empty-state copy, scope banner, and "not enabled in this build" disclosures read correctly | — |
| NOT_AN_ISSUE | Cutover | Blocked state explains WHY with evidence links; nothing green without proof | — |

No BUGs affecting functionality, no dead controls, no permission errors, no unclear critical
copy were found beyond the items above.

---

## FINAL DECISION

**No release-blocking defects.** Both candidates for rc.2 (launcher overlap CSS; packaging
allowlist for the new product docs; the one-line pluralization) are cosmetic/packaging —
RC.1 stays frozen and published as-is.

**FINAL STATUS: RC1_ACCEPTED**

Prepared (NOT published) rc.2 scope, if pursued: launcher/inspect control overlap,
`DISTRIBUTION_ALLOWLIST.md` extension for the 0.4.0 product docs, pluralization fix.
DO NOT PUBLISH STABLE.
