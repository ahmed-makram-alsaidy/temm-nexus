# TEMM NEXUS — v0.4.0-rc.1 RELEASE REPORT

**Date:** 2026-10-02
**Status: V0.4.0_RC1_PUBLISHED**

BASE:
Stable — v0.3.0
Stable Modified — **NO** (`v0.3.0` tag object `f1da4576752dfe6772bbe1da39f274d778d81134`, dereferenced `ad693fcddcda394bd7dbc9efcab2cb8fbeb8249a`, unchanged; all prior rc tags intact)

RELEASE:
Version — **0.4.0-rc.1**
Release Commit — `66c3ec7e67d20473f8a6b934d15807c77df08a89` (release branch `release/0.4.0-rc.1`; develop head at approval `26fcfca1cfde3fec000ea5e8f181aebecf02ff65`; the only release-branch delta is the release-notes commit)
Tag Object — `4c20d566a91c9ce93945ae22772fbd36e8a8a284`
Tag Commit — `66c3ec7e67d20473f8a6b934d15807c77df08a89`
Annotated tag; never retagged.

PHASES:
A–K — **COMPLETE** (Phase record: `docs/product/PHASE_IJK_FINAL_REPORT.md`, gate reports `PHASE_I_GATE.md`, `PHASE_J_GATE.md`)

TESTS (step 3 — publication re-run):
- Blocking/Hermetic — **660 tests / 3649 assertions / 0 failures / 0 errors / 22 skips**
  (the recorded gate run was 3633/23; this run executed the previously self-skipping
  MongoDB docker-dogfood test — +16 assertions, −1 skip, PASS. Differences are
  environment-conditional self-skips only; 0 failures / 0 errors in both runs.)
- CI blocking suite (real Redis + phpredis): **PASS** on the release commit
- Non-blocking CI fixture job (continue-on-error by repo policy, `docs/TEST_CLASSIFICATION.md`): fail as documented — fixtures do not exist on CI
- New Regressions — **0**
- Skips are NOT merged into the blocking result: 19 SetupWizardTest (phpredis, run in CI) + environment self-skips

SECURITY (step 4):
Secrets — **0** (secret scan PASS on tree and on the downloaded artifact)
Private Refs — **0** (private-reference scan PASS, run against the published artifact)
Critical Applicable Advisories — **0** (`composer audit`, 455 packages)
SSH keys / API keys / admin passwords / customer data / local machine paths in artifact — **0**
Support Bundle Redaction — **PASS** (`SupportBundleTest::test_bundle_never_leaks_planted_secrets`)
AI prompt/tool/argument redaction — **PASS** (hash-only prompts, secret-shaped result keys refused, audit arguments redacted)

ARTIFACT:
Filename — **platform-0.4.0-rc.1-20261002-163728.tar.gz**
SHA-256 — **`37da53b92feaeadc6fef8014ecaf02b7b37dd6211d814bd5a0025f7113af2ff0`**
Double Hash Verification — **PASS** (three independent tools agree: Git-Bash `sha256sum`, PowerShell `Get-FileHash`, Windows `certutil`; plus a fourth computation on the downloaded copy)
Manifest — **PASS** (`manifest-0.4.0-rc.1.json`: version 0.4.0-rc.1, 21 migrations, php ^8.3 / postgres 17 / redis 8, `secrets_included: false`)
SBOM — **PASS** (`docs/open-source/SBOM.md` updated in-artifact: no new runtime dependencies vs 0.3.0; composer audit 0)
Artifact Scan — **PASS**
Content verification — **PASS**: VERSION = 0.4.0-rc.1; Inspect Mode, safe-action framework, Nexus AI, workspace + action-plan migrations, connector manifests, frontend assets present; no `.env`, no credentials, no SSH material, no test databases, no runtime state, no local private paths
Fresh Install — **PASS** (CI fresh-install job on the exact release commit: install.sh → setup gate → edge assets → Caddy upgrade regressions, 10m5s green; plus the Phase K.12 full local wizard/lock/admin proof on the code-identical build)
Upgrade v0.3.0 → rc.1 — **PASS** (Phase K.13: real upgrader, pre-upgrade backup, 16/16 preservation checks, permissions fail-closed, signed CDC checkpoint verifies; the tree is code-identical to the published artifact except CHANGELOG.md)

PRODUCT:
Workspaces — PASS · Navigation — PASS · Visual Redesign — PASS · Nexus AI — PASS
Inspect Mode — PASS · Safe Actions — PASS · Approval — PASS · Verify — PASS · Audit — PASS

TENANT SECURITY:
Workspace Isolation — PASS · Project Isolation — PASS · AI Scope Isolation — PASS
Inspect Isolation — PASS · Action Isolation — PASS (suite-enforced; `TenantIsolationTest`, `WorkspaceModelTest`, `InspectModeTest`, `ActionSafetyTest`)

CORE:
PostgreSQL — PASS · MySQL — PASS (8.4 compat fixed) · MongoDB — **PASS WITH DOCUMENTED LIMITATIONS** (change streams proven live on the artifact; inherited snapshot edge cases with certain document shapes — engine byte-identical to v0.3.0)
PG WAL CDC — PASS (15k-event storm, restart/resume, deltas 0, DATA_READY_FOR_CUTOVER)
MySQL Binlog CDC — PASS (same)
Mongo Change Streams — PASS (stream establishment + resume tokens live)
Cutover — PASS · Backup/Restore — PASS

CI:
Release PR — **GREEN** (run 37014076320, head `66c3ec7…`, conclusion success; all blocking jobs pass: hermetic suite, fresh-install smoke, container build, SDK ×2, secret scan, private-ref scan, script tests)
Main — **GREEN** (run 37040189569, merge commit `06b73b1d960e69c6e8be9486abb48a1218ec03f4`, conclusion success)

PUBLICATION:
GitHub Release — **https://github.com/ahmed-makram-alsaidy/temm-nexus/releases/tag/v0.4.0-rc.1**
Pre-release — **YES** · Stable — **NO** · latest-release flag not moved
Assets attached — artifact, artifact `.sha256`, `SHA256SUMS`, `manifest-0.4.0-rc.1.json`
Downloaded SHA — **MATCH** (published download = release SHA = SHA256SUMS = manifest SHA; extracted and re-scanned clean)

AZURE:
Run — **NO** (no Azure credentials or VM access exist in this environment; not fabricated)
HTTPS / Login / Doctor / Nexus AI / Inspect / Safe Actions / CDC / Reboot — **NOT RUN**

PRIOR RELEASES MODIFIED: **NO**
PRODUCTION/CUSTOMER SYSTEMS TOUCHED: **NO**
Disposable release-test infrastructure: verified (only `@localhost.test` seed data, only its own `backend-plane_*` volumes) and torn down. Docker images/containers from the session were disposable.

ENVIRONMENT INCIDENT (documented, not a release defect):
During publication, the workstation's Docker Desktop/WSL2 engine failed to start
(`WSL/Service/CreateInstance/MountDisk/HCS/E_ACCESSDENIED` attaching the distro/data
VHDX on `E:\DockerWSL`) — an OS-level HCS denial, most plausibly a pending-reboot
Windows state on build 26200.9457. The VHDX files were **not modified or deleted**.
Consequence: the two local docker re-smokes on the final artifact were replaced by
(i) the CI fresh-install job executed on the EXACT release commit with the Caddy
upgrade regressions, and (ii) the Phase K.12/K.13 full local proofs on the
code-identical tree (delta: CHANGELOG.md only). A machine reboot should restore the
engine; images rebuild from the artifact.

KNOWN LIMITATIONS:
Firebase CDC DEFERRED · MariaDB PARTIAL · MongoDB snapshot edge cases (inherited, documented) ·
one-person action approval (no second factor; 15-min TTL + double-capability bound) ·
create_backup/retry_failed_job need operator-side runtime · conversations are per-session ·
SetupWizardTest skip-cleans on phpredis-less hosts (runs fully in CI)

BLOCKERS: **NONE**

FINAL STATUS: **V0.4.0_RC1_PUBLISHED**

DO NOT PUBLISH v0.4.0 STABLE. STOP.
