# TEMM Nexus — Phase 0.4.0 Gate Report: Phase D

**Branch:** `develop/0.4.0`
**Base:** `v0.3.0` (`ad693fc`) — **unmodified**
**Preceding gate:** [`PHASE_ABC_GATE.md`](PHASE_ABC_GATE.md) (commits `ad34a6f`, `dc17c8e`)
**Scope of this report:** Phase D — the redesigned Platform Home, Workspace
dashboard, and Project Overview, plus the shared migration-journey model that
Phases E and F will build on.

---

## 1. Changes

### New — the journey model (the reusable core)

| Class | Purpose |
| --- | --- |
| `app/Services/Product/JourneyStage.php` | The seven stages: Connect → Analyze → Plan → Migrate → Sync → Validate → Cutover. Ordered, labelled, each with a one-line description. |
| `app/Services/Product/JourneyState.php` | The six product states (`NOT_STARTED`, `IN_PROGRESS`, `READY`, `NEEDS_ATTENTION`, `BLOCKED`, `COMPLETE`), each carrying an **icon and a label** so no state is ever colour-only (§34). Includes `fromRaw()` — the single translation point from internal tokens. |
| `app/Services/Product/ProjectPulse.php` | Derives one project's journey from real telemetry: sources, analyses, plans, runs, CDC checkpoints, readiness checks, backups, cutover plans. |
| `app/Services/Product/PlatformPulse.php` | The platform dashboard's source of truth. Fixed query count, scoped to reachable projects. |

**Why one model.** v0.3.0 exposed raw subsystem status across a dozen pages with
nothing tying it together, so a user could not answer "where am I, what is
blocked, what is next". These services answer it once; every 0.4.0 surface reads
them rather than re-deriving state.

### New — the journey stepper

`resources/views/components/nx/journey.blade.php` — a `<x-nx.journey>` component
rendering the seven stages with their state, current-step marker, per-stage
detail, and a link into the relevant page. Horizontal rail at ≥1440px; a
vertical list below that (see §4).

### Redesigned — Platform Home (§7)

| Before (v0.3.0) | After |
| --- | --- |
| Led with **DB STORAGE**, **LAST BACKUP** metric cards | Leads with greeting, one-sentence platform state, and the primary CTA |
| No primary CTA at all | **Start a migration** (+ Clients & workspaces) |
| Raw tokens: `LOCAL`, `UNKNOWN`, `demo_project_a_db · pending` | Product language throughout |
| Infrastructure above user-relevant information | Infrastructure **last**, summarised as backups taken / needing review |
| ~500px of dead space below the fold | Lean sections: needs attention → in flight → recent projects → platform health → activity |

The five summary figures are the mission's list: Projects, Active migrations,
Live syncs, Ready for cutover, Needs attention. **Each carries a hint line** —
there are no bare numbers.

### Redesigned — Workspace dashboard (§8)

Projects now show **migration progress and current stage**, not just a health
chip, so the workspace answers "how is this client doing" rather than only "are
these projects up". Identity, scope banner, members, attention-first ordering,
and the three primary actions (New project, Invite member, Ask Nexus AI) are all
present.

### Redesigned — Project Overview (§9)

Rebuilt around the journey. The mission's own example composition is met: name,
source/target context, migration progress %, current stage, health, Live Sync,
last backup, readiness, and **one** primary CTA that follows the journey.

**Progressive disclosure, not deletion (§14).** Every v0.3.0 figure — database
reachability and connections, Pulse API requests and errors, queue depth,
database size, storage, application users, runtime functions, project slug, Live
Sync lag — is preserved under **Advanced details**.

### Fixed — baseline findings closed

| Finding | Fix |
| --- | --- |
| **B1** `/admin/team-management` → 404 | 302 redirect to `/admin/team` |
| **B2** `/admin/project-switcher` → 404 | 302 redirect to `/admin/switcher` |

Both verified in the real browser (redirect followed, target returns 200).

### Fixed — four real defects found while building

1. **A blocked project was reported as "Not started".**
   `ProjectPulse::cutoverState()` only consulted failing readiness checks *once a
   cutover plan existed*. A project with failed validation and no plan therefore
   reported `NOT_STARTED` — the most dangerous thing this service could say,
   because it presents a blocked project as one that simply has not begun.
   Failed checks now outrank everything. Covered by
   `test_an_unacknowledged_blocking_readiness_check_blocks_cutover`.

2. **Legacy `CpAccess`-gated pages could not see the new roles.**
   About a dozen v0.3.0 pages authorise through `CpAccess`, which only knew
   `is_admin`/`cp_role`. A user holding `platform_role = platform_owner` with the
   legacy flag unset was granted `team.manage` by `Access` and refused it by
   `CpAccess`, so `/admin/team` returned 403 for a platform owner. `CpAccess`
   now consults the new role first and falls back to the legacy path, so a
   migrated installation is governed by scoped roles while an unmigrated one
   behaves exactly as in v0.3.0.

3. **`ProjectSwitcher` used a raw `is_admin` check and listed every project.**
   It was the only admin page gating on the legacy flag instead of a capability
   — and its table ran an **unscoped** `Project::query()`, which would have
   listed every tenant's projects to anyone who reached it. It now uses
   `projects.view` and `PlatformAccess::projectsQuery()`.

4. **Breadcrumbs rendered URLs as visible text.**
   Laravel/Filament expect `[url => label]`; the first revision of the new
   `getBreadcrumbs()` passed `[label => url]`, so the crumb read
   `http://…/admin/workspaces/default-workspace` with a label as its href.

### Fixed — one regression I introduced, caught by the existing suite

`tests/Feature/Phase26/EmptyStateTest` requires the fresh-install dashboard to
show **both** original primary actions. My first Home empty state replaced them
with a single "Create your first project", which broke that contract. The empty
state now offers **Create new project** and **Import existing project** — a fresh
install may be adopting an existing backend rather than starting one, so
dropping the import path was a real product regression, not just a test failure.

---

## 2. Screens changed

| Screen | Route | Evidence |
| --- | --- | --- |
| Platform Home | `/admin` | `phaseD-01-home.png` |
| Workspace dashboard | `/admin/workspaces/{slug}` | `phaseD-03-workspace-detail.png` |
| Project Overview | `/admin/projects/{record}` | `phaseD-04-project-overview.png` |
| Project switcher (scoped + capability-gated) | `/admin/switcher` | `phaseD-07-switcher.png` |
| Shell (breadcrumbs, context chip on project routes) | all project routes | `phaseD-*` |

---

## 3. DB migrations

**None.** Phase D is read-only over the existing schema. No table, column, or
index was added, changed, or dropped.

---

## 4. Tests

### New

| Suite | Tests | Asserts | Proves |
| --- | --- | --- | --- |
| `DashboardTest` | 19 | 77 | Journey derivation from real telemetry; BLOCKED beats NOT_STARTED; product language (no `CDC`/`LSN` on Home); platform summary scoped to reachable projects; a tenant never sees another tenant's attention items; the five figures each carry context; the two redirects resolve |
| `JourneyStateTest` | 7 | 75 | **Every icon resolves** against the installed Heroicons set; no state relies on colour alone; the seven stages are ordered and complete; raw tokens translate correctly; unknown input degrades to `NOT_STARTED`, never to success |
| **Total** | **26** | **152** | |

`JourneyStateTest::test_no_invalid_heroicon_is_referenced_in_the_product_layer`
scans the source for quoted `heroicon-*` literals (comments stripped) and fails
if any does not exist. This exists because `heroicon-o-circle` — **not a real
Heroicon** — shipped in an earlier revision of this branch and turned Home into a
500. An invalid icon name is invisible to unit tests and only appears at render
time, which is exactly the class of defect browser QA had to catch first.

### Blocking suite

| | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| Baseline v0.3.0 | 427 | 2202 | 10 | 1 | 3 |
| After Phase A/B/C | 495 | 2590 | 10 | 1 | 3 |
| **After Phase D** | **521** | **2743** | **10** | **1** | 3 |

**No new failures.** The failing set is again exactly the 11 pre-existing
`SetupWizardTest` phpredis-environment findings (see
[`BASELINE_0_4.md`](BASELINE_0_4.md) §4). Phase D added 26 tests / 153
assertions.

An intermediate run during Phase D reported an 11th failure —
`Phase26\EmptyStateTest` — which was a **real regression introduced by this
phase** and is described in §1. It was fixed before the gate above.

---

## 5. Browser proof

Real browser, Chromium, authenticated, `http://127.0.0.1:8123`.

```
PAGES: 12 tested · 0 non-200 · 0 console errors · 0 broken assets
ASSERTIONS: 35 pass · 0 fail
```

Highlights:

| Assertion | Result |
| --- | --- |
| Home has exactly **one** `<h1>` | PASS |
| Home leads with greeting + state + CTA | PASS |
| **Infrastructure renders AFTER needs-attention** (offset 604 vs 431) | PASS |
| Overview leads with migration progress, %, current stage | PASS |
| Overview shows Health, Live Sync, Last backup, Readiness | PASS |
| Overview renders the 7-step journey with "You are here" | PASS |
| Overview keeps **Advanced details** (nothing deleted) | PASS |
| Breadcrumbs show **labels, not URLs** | PASS |
| Workspace offers New project / Invite member / Ask Nexus AI | PASS |
| Both dead links redirect and their targets return 200 | PASS |
| Every `.nx-status` carries text (0 empty) — not colour-only | PASS |
| Every `[role=progressbar]` has an `aria-label` | PASS |
| No horizontal overflow at 1366×768 (0px on Home and Overview) | PASS |
| Journey stacks vertically at 1366px | PASS |

**A real layout defect was found and fixed by this QA:** at 1366×768 the
seven-column journey rail left each step ~130px, wrapping "Needs attention"
across three lines and making the stepper unscannable — on an explicit support
target (§33). The breakpoint moved from 1279px to 1439px, since seven columns of
readable detail need ~1440px.

---

## 6. Security impact

| Item | Assessment |
| --- | --- |
| **Net change** | **Positive.** One unscoped query eliminated, two authorisation inconsistencies aligned. |
| `ProjectSwitcher` | Was unscoped (`Project::query()`); now `PlatformAccess::projectsQuery()`, which is itself built from `Access::accessibleProjects()` |
| `ProjectSwitcher` gate | Was a raw `is_admin` flag; now the `projects.view` capability |
| `CpAccess` bridge | Consults the new scoped role **first** and only falls back to legacy when the user holds no platform role. A stale legacy column can no longer re-grant a permission the new role withheld. |
| Legacy RBAC behaviour | `TeamRbacTest` (5 tests) still green |
| Dashboard data | Every collection is scoped to reachable projects; proven by `test_platform_summary_never_leaks_another_tenants_attention_items` |
| Secrets | None added. No new external call. No AI provider contacted. |
| Arbitrary shell / SQL | Not exposed. |

---

## 7. Known gaps (honest)

| Gap | Status |
| --- | --- |
| **Cutover as a dedicated readiness experience (§10)** | **Not built** — Phase E. Today the journey links Cutover to the existing Readiness page. |
| Migration journey / Live Sync page redesigns (§5/§6) | **Not built** — Phase E |
| Connector Catalog product redesign (§11) | **Not built** — Phase F |
| Operations / Infrastructure / Security page redesigns (§2) | **Not built** — Phase F |
| AI conversations, tool execution, Inspect Mode, approvals | **Not built** — Phases G–J |
| `docs/ai/NEXUS_COPILOT_ARCHITECTURE.md` | **Not written** — Phase G |
| Pre-existing storage 500 (`ProjectStorageManager::for`) | **Not fixed** — still open, out of Phase D scope |
| URL redirects for pages that will move in Phase E/F | **Not built** — needed when those pages move |
| Journey "source/target" identity on Overview | **Partial** — the connectivity strip is not shown when no source exists yet (there is nothing to name). It will render real source/target once Phase E wires the connector list. |

---

## 8. Regressions

| Check | Result |
| --- | --- |
| Blocking release suite | No new failures (see §9) |
| `EmptyStateTest` (broken during Phase D) | **Fixed** before the gate; 1 test / 8 assertions green |
| Everything else | No regression |

---

## 9. Gate

### Commit

| Item | Value |
| --- | --- |
| Base | `ad693fc` (`v0.3.0`) |
| Preceding | `dc17c8e` (Phase A/B/C gate) |
| **Phase D commit** | `90cf1c6` — `feat(0.4.0): Phase D — redesigned Home, Workspace and Project Overview` |
| Branch | `develop/0.4.0` |
| `v0.3.0` tag | still `ad693fc` — **not moved, not replaced, not retagged** |
| New tags published | **none** |
| `v0.4.0` published | **no** |

---

## 10. Gate decision

| Criterion | Status |
| --- | --- |
| Phase D internally green | **PASS** |
| Blocking tests: 0 new failures / 0 new errors | **PASS** |
| Browser QA | **PASS** |
| Tenant isolation maintained | **PASS** |
| No secret sent to any AI provider | **PASS** (no provider configured; no AI call made) |
| No regression | **PASS** |

**Continue to Phase E (migration journey, Live Sync, and the dedicated Cutover
readiness screen), then F (Connector Catalog + Operations).**

**FINAL STATUS: `0.4.0_DEVELOPMENT_CONTINUES` — NOT ready for `v0.4.0-rc.1`.**
Stable is not published. Nothing was published by this phase.
