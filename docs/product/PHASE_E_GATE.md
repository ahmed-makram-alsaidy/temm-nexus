# TEMM Nexus — Phase 0.4.0 Gate Report: Phase E

**Branch:** `develop/0.4.0`
**Base:** `v0.3.0` (`ad693fc`) — **unmodified**
**Preceding gate:** [`PHASE_D_GATE.md`](PHASE_D_GATE.md) (commit `cd9bd33`)
**Scope of this report:** Phase E — the dedicated **Cutover readiness
experience** (§10), promoted from a section of a generic Readiness page to a
first-class product destination, plus the large pre-existing access-control
defect that building it exposed.

---

## 1. Changes

### New — `app/Services/Product/CutoverReadiness.php`

Assembles the Cutover screen from real evidence. It reports the mission's list
**verbatim**: overall readiness · blocking issues · CDC state · lag ·
reconciliation · validation · backup status · rollback readiness · final sync
readiness · human approvals.

**Honesty rules carried over from the Phase 34 service, and enforced here:**

| Rule | Implementation |
| --- | --- |
| A gate with no evidence is **UNVERIFIED**, never green | `toProductState()` maps anything unrecognised to `UNVERIFIED`, whose tone is neutral and whose label is "Not verified" |
| "Could not check" must not read as "you may proceed" | `overall()` degrades UNVERIFIED to **WARNING**; only an all-PASS set yields READY |
| One BLOCK blocks the window | `overall()` returns BLOCKED if any gate is BLOCK |
| Nothing auto-approves | A gate in `APPROVAL_REQUIRED` with no row reads "Awaiting", never "granted" |
| Rollback needs proof | `rollback()` requires **both** a recorded plan **and** a verified, restore-drilled backup |

### New — `app/Filament/Resources/Projects/Pages/ProjectCutover.php`

`/admin/projects/{record}/cutover`. Registered in `ProjectResource::getPages()`
and added to the project sidebar, so Cutover is a real destination rather than a
link to a general-purpose page.

### New — `resources/views/filament/projects/cutover.blade.php`

The screen itself. Structure, in the mission's order:

1. **Overall readiness** — one of **READY / WARNING / BLOCKED**, sized to be read
   from across the room, with a one-sentence explanation.
2. **Why you cannot proceed** — every blocking and unverified item, each with the
   underlying evidence as its reason.
3. **Readiness gates** — all seven gates, each with a state icon, a section name,
   its evidence, and an explicit state label.
4. **Readiness detail** — Live Sync, Reconciliation, Backup, Rollback, Final sync.
5. **Human approvals** — every approval-required gate and its decision.
6. **Ordered cutover plan** — the eleven steps, with operator-owned ones marked.
7. **Advanced details** — the raw internal gate states, the raw Live Sync state,
   lag in seconds, the rollback window expiry and procedure.

### Safety posture (deliberately unchanged)

The platform **never** performs a DNS or endpoint switch. That was true of the
Phase 34 service and remains true here:

- The primary action is **disabled** and says "Cutover is not available yet"
  until every gate passes, so the UI cannot invite an unsafe click.
- The `endpoint_switch` step is marked approval-required and its note states
  it is **operator-executed, recorded, never automatic**.
- A test asserts `CutoverCenterService` has grown **no executor method**
  (`executeCutover`, `switchEndpoint`, `performCutover`, `applyCutover`). If
  anyone ever adds one, that test is the tripwire.
- Recording an approval requires the `cutover.approve` **capability**, checked at
  execution time inside the action — not merely hidden in the UI.

### API addition — `CutoverCenterService::createPlanStepsPreview()`

The screen must be able to show the ordered plan **before** an operator commits
to one. The existing `createPlan()` persists a row and writes an audit event, so
it cannot be used for a read-only page render. The new method returns the same
ordered steps from the same source (`orderedSteps()`) with **no side effects**,
and a test asserts that previewing creates no plan.

---

## 2. The significant pre-existing defect this phase exposed

**All ~45 project-scoped pages were gated on the raw `is_admin` flag.**

`HasProjectContext::canAccess()` read:

```php
return auth()->check() && (bool) auth()->user()->is_admin;
```

That trait is used by every project page. Once 0.4.0 introduced scoped roles it
had two consequences:

1. **The Workspace layer was unusable.** A user granted a workspace or project
   role — the entire point of Phases A–C — could not open *any* project page.
   `CutoverReadinessTest` caught this immediately: an `alphaOwner` with full
   workspace authority was refused the Cutover screen with a 403.
2. **The capability model was unreachable for non-admins.** This check sat
   *above* every per-page capability check, so the granular permissions Phase B
   introduced could never be consulted by anyone who was not a legacy admin.

It now asks the capability question and, when the route names a record, also
requires that the record is one the user can actually reach — so a crafted URL
still cannot open another tenant's project. When no record is resolvable at that
point (Filament does not guarantee the parameter), it does **not** guess at
reach; the per-record check in `project()` and `mount()` remains the authority.

This is the same class of defect as the `ProjectSwitcher` finding in Phase D:
legacy `is_admin` checks surviving alongside the new model, disagreeing with it.

---

## 3. Screens changed

| Screen | Route | Evidence |
| --- | --- | --- |
| **Cutover** (new) | `/admin/projects/{record}/cutover` | `phaseE-cutover.png` |
| Project Overview — journey step 7 now links to Cutover | `/admin/projects/{record}` | `phaseE-overview.png` |
| Project sidebar — Cutover entry added | all project routes | `phaseE-*.png` |
| Every project page — access gate corrected | all ~45 project routes | regression suite |

---

## 4. DB migrations

**None.** Phase E reads existing tables (`cutover_plans`, `cutover_approvals`,
`cdc_checkpoints`, `readiness_checks`, `backup_records`) and writes nothing new.

---

## 5. Tests

### New — `tests/Feature/Phase40/CutoverReadinessTest.php` (28 tests, 110 assertions)

| Group | Proves |
| --- | --- |
| Never optimistic | No evidence ⇒ BLOCKED, not READY; a missing or un-drilled backup BLOCKS; a backup that is `ok` but never restored still BLOCKS; a verified + drilled backup PASSES |
| No fake green | Every `UNVERIFIED` gate renders neutral tone and the label "Not verified" |
| The three product states | `overall()` always returns READY, WARNING or BLOCKED; WARNING when nothing blocks but items are unverified |
| Reasons, not just state | Every blocking issue carries a non-empty `detail` and a gate key |
| All six dimensions | `overall`, `liveSync`, `validation`, `backup`, `rollback`, `finalSync` each carry a label **and** an explanation |
| Product language | Live Sync detail contains no `CDC` / `LSN` / `checkpoint` |
| Approvals | Start awaiting (nothing auto-approves); a recorded decision is reflected; a gate outside the approval matrix cannot be approved; an invalid decision is rejected |
| The plan | Previewable without persisting; operator-owned steps marked; the endpoint switch explicitly "never automatic" |
| HTTP | A viewer can read the screen; it states why it is blocked; a non-approver is told they lack `cutover.approve`; an approver is not; a cross-tenant request is refused; unauthenticated access redirects |
| Safety tripwire | `CutoverCenterService` has no executor method |

### Blocking suite

| | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| Baseline v0.3.0 | 427 | 2202 | 10 | 1 | 3 |
| After Phase A/B/C | 495 | 2590 | 10 | 1 | 3 |
| After Phase D | 521 | 2743 | 10 | 1 | 3 |
| **After Phase E** | **549** | **2853** | **10** | **1** | 3 |

**No regressions**, including through the access-control change that touches all
~45 project pages. The 11 findings remain the pre-existing `SetupWizardTest`
phpredis-environment set (see [`BASELINE_0_4.md`](BASELINE_0_4.md) §4).

---

## 6. Browser proof

Real browser, Chromium, authenticated, `http://127.0.0.1:8123`.

```
CUTOVER SCREEN: 200 · 0 console errors · 0 broken assets
ASSERTIONS: 26 pass · 0 fail
```

| Assertion | Result |
| --- | --- |
| `/cutover` is a real destination (200) | PASS |
| Shows overall readiness, blocking issues, CDC/Live Sync, reconciliation, backup, rollback, final sync, approvals, ordered plan, gates | PASS |
| Overall state is one of READY / WARNING / BLOCKED (rendered "Blocked") | PASS |
| States a **reason** for the block | PASS |
| Primary action unambiguous: disabled when blocked, ready when not | PASS |
| States the platform does not change DNS/endpoints | PASS |
| Raw gate states kept under Advanced details (§14) | PASS |
| All 7 gates show an explicit state label (7/7) | PASS |
| 4 approval gates listed; an undecided one reads as awaiting | PASS |
| No status element is text-empty — not colour-only (§34) | PASS |
| No horizontal overflow at 1366×768, 1440×900, **and** 1920×1080 | PASS |
| Breadcrumbs show labels not URLs | PASS |
| Journey step 7 links to `/cutover` | PASS |

---

## 7. Security impact

| Item | Assessment |
| --- | --- |
| **Net change** | **Positive, and materially so.** The phase corrected an authorisation defect that made the Workspace layer unusable and bypassed the capability model on 45 pages. |
| Project page entry | Now `projects.view` capability + per-record reach, instead of a global `is_admin` flag |
| Cross-tenant reach | Still refused (test asserts 403/404 for another tenant's cutover screen) |
| Approval authority | `cutover.approve` capability, re-checked at execution time inside the action (§17) |
| Platform execution of DNS/endpoint changes | **Still none.** Asserted by test. |
| Secrets | None added; the screen reads status and evidence only |
| Arbitrary shell / SQL | Not exposed |

---

## 8. Known gaps (honest)

| Gap | Status |
| --- | --- |
| Migration journey page redesign — `Connect` / `Analyze` / `Plan` / `Transfer` as separate screens (§5) | **Not built.** The journey model and links exist (Phase D); the pages themselves are still the v0.3.0 surfaces. |
| Dedicated **Live Sync** page in product language (§5/§6) | **Not built.** Live Sync is summarised on Overview and Cutover; there is no dedicated Live Sync screen. |
| **Connector Catalog** product redesign (§11) | **Not built** — Phase F |
| Operations / Infrastructure / Security page redesigns | **Not built** — Phase F |
| Recording an approval from the UI | **Not built.** The screen displays approvals and the service can record them; the per-gate approve/reject control is not wired to the page yet. |
| AI conversations, tool execution, Inspect Mode, approvals | **Not built** — Phases G–J |
| `docs/ai/NEXUS_COPILOT_ARCHITECTURE.md` | **Not written** — Phase G |
| Pre-existing storage 500 (`ProjectStorageManager::for`) | **Not fixed** — still open |
| Legacy project URLs for pages that moved | Still open; only the two B1/B2 dead links were redirected |

---

## 9. Gate

| Item | Value |
| --- | --- |
| Base | `ad693fc` (`v0.3.0`) |
| Preceding | `cd9bd33` (Phase D) |
| **Phase E commit** | `e7a2ef2` — `feat(0.4.0): Phase E — dedicated Cutover readiness experience` |
| `v0.3.0` tag | still `ad693fc` — **not moved, not replaced, not retagged** |
| New tags published | **none** |
| `v0.4.0` published | **no** |

---

## 10. Gate decision

| Criterion | Status |
| --- | --- |
| Phase E internally green | **PASS** |
| Blocking tests: 0 new failures / 0 new errors | **PASS** |
| Browser QA | **PASS** |
| Tenant isolation maintained | **PASS** |
| No secret sent to any AI provider | **PASS** (no provider configured; no AI call made) |
| No regression | **PASS** |
| Platform still cannot execute a cutover | **PASS** (asserted) |

**Continue to Phase F (Connector Catalog + Operations), then G–J (Nexus
Copilot).**

**FINAL STATUS: `0.4.0_DEVELOPMENT_CONTINUES` — NOT ready for `v0.4.0-rc.1`.**
Stable is not published. Nothing was published by this phase.
