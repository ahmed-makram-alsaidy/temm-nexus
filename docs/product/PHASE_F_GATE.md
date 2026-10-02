# TEMM Nexus — Phase 0.4.0 Gate Report: Phase F

**Branch:** `develop/0.4.0`
**Base:** `v0.3.0` (`ad693fc`) — **unmodified**
**Preceding gate:** [`PHASE_E_GATE.md`](PHASE_E_GATE.md) (commit `160a5bf`)
**Scope of this report:** Phase F — the **Connector Catalog** rebuilt as a real
product catalogue (§11).

---

## 1. Changes

### The problem this fixes (UX audit finding P9)

v0.3.0 rendered the catalogue as six full-width unstyled boxes of debug text:

```
PostgreSQL              [first_party]
key: postgres · v1.0.0
Enabled
```

No icons, no descriptions, no capability information, no per-card action, and a
"filter" row that was a line of text rather than controls. It was a debug
listing wearing a page title.

### New — `app/Services/Product/ConnectorCatalogView.php`

Turns each connector manifest into the card the mission specifies: **icon ·
provider name · short description · capabilities · migration support · Live Sync
support · trust level · status · version · CTA**, plus search and
capability/trust filters.

**Capability translation (§14).** The raw manifest keys (`database_metadata`,
`data_extraction`, `change_capture`, `read_only_enforcement`, …) are internal
vocabulary. They are mapped once, in `FEATURES`, onto user-facing feature names
with a one-line explanation each:

| Raw capability | Product label |
| --- | --- |
| `database_metadata` | Schema inspection |
| `data_extraction` | Data transfer |
| `change_capture` | **Live Sync** |
| `checkpoint` | Sync checkpoints |
| `read_only_enforcement` | Read-only guarantee |
| `consistent_snapshot` | Consistent snapshot |
| …16 more | … |

The raw keys remain available in the card's Advanced disclosure, so nothing is
lost — this is re-hierarchisation, not deletion.

**Trust levels** are labelled and explained rather than shown as a raw token:
`first_party` → "First-party", `community` → "Community", each with a hint.

### Changed — `app/Filament/Pages/ConnectorCatalog.php`

- Slug moved from the internal `connector-catalog` to the product path
  **`connectors`**, with a **302 redirect from the old URL** so existing
  bookmarks keep working.
- Navigation label is now "Connectors" (was "Connector catalog"), matching the
  new information architecture.
- Access now asks for the **`connectors.view`** capability. The previous gate
  required the legacy `projects.view` permission, which excluded roles that
  should legitimately be able to browse connectors.
- Live search (`wire:model.live.debounce`), capability chips, trust chips, and a
  "ready to use only" toggle — all real Livewire state, all server-side
  filtering.
- The CTA routes into a project's Connect screen (a connector is connected
  *inside* a project), and says "Create a project to connect" when the user has
  no project, rather than rendering a dead link.

### Changed — `resources/views/filament/pages/connector-catalog.blade.php`

Rebuilt as a card grid. Each card carries the required fields plus:

- **Migration / Live Sync / Trust** as a labelled three-column flag block with
  an explicit "Supported" / "Not supported" — never a bare colour.
- Feature chips in product language, each with a hover explanation.
- A read-only safety line where the connector enforces it.
- An **Advanced** disclosure with the raw key, import flow, category, and raw
  capability list.
- An intentional **empty state** that distinguishes "no connectors installed"
  (explaining what a connector is for) from "nothing matches your filters"
  (offering to clear them).

### A defensive detail worth noting

`safeIcon()` validates a manifest-declared icon against the installed Heroicons
SVG set and falls back to a neutral glyph. An unknown Heroicon **throws at
render time** and turns the whole page into a 500 — the same class of defect
that `heroicon-o-circle` caused in Phase D. A connector manifest is
third-party-ish input, so it must not be able to break the catalogue.

---

## 2. Screens changed

| Screen | Route | Evidence |
| --- | --- | --- |
| Connector Catalog | `/admin/connectors` (**was** `/admin/connector-catalog`) | `phaseF-catalog.png` |
| Platform navigation | Connectors entry | `phaseF-catalog.png` |
| Legacy catalogue URL | 302 → `/admin/connectors` | browser QA |

---

## 3. DB migrations

**None.** Phase F reads the connector registry (filesystem manifests) and writes
nothing.

---

## 4. Tests

### New — `tests/Feature/Phase40/ConnectorCatalogTest.php` (22 tests, 334 assertions)

| Group | Proves |
| --- | --- |
| Card data | Every card carries all 16 required fields; every card's **icon exists in the installed set**; descriptions come from the manifest and are not placeholders |
| Capability translation | Raw keys never appear as feature labels; product labels do ("Data transfer", "Live Sync", "Read-only guarantee"); raw keys survive for Advanced |
| Flag honesty | The `migration` and `live_sync` flags are computed from the real manifest capabilities, and at least one connector must report each value — so a stuck flag fails |
| Trust | Levels are labelled in product language with a hint and a valid tone |
| Filters | Search matches name/description/feature labels, is case-insensitive and trimmed; a capability filter requires **every** selected capability; a trust filter narrows correctly; filters combine; a non-matching search returns **empty** (the empty state depends on it); "ready only" excludes disabled |
| Counts | Every offered filter chip reports a real, non-zero count |
| HTTP | Renders for a capable user; shows Migration / Live Sync / Trust / search; the old URL redirects; a user without the capability is refused; unauthenticated redirects |
| Secrets | No credential **field name** is rendered as card content, and no secret-shaped value (PEM key, JWT, `sk-…` key, `service_role`) appears anywhere |
| Vocabulary completeness | Every capability any installed connector declares has a product-language label, so a new capability cannot become invisible in the catalogue |

`test_the_capability_vocabulary_has_no_untranslated_feature_mapping` is the one
that keeps the catalogue honest as connectors are added: it fails the moment a
manifest declares a capability the catalogue cannot describe.

### Updated — `tests/Feature/Phase35/ConnectorCatalogPageTest.php`

This test asserted the **old** URL returned 200. It now asserts the new URL
renders **and** that the old URL redirects and its target works — so both the
original regression guard (the page must not 500) and the new redirect are
pinned.

### Blocking suite

| | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| Baseline v0.3.0 | 427 | 2202 | 10 | 1 | 3 |
| After Phase D | 521 | 2743 | 10 | 1 | 3 |
| After Phase E | 549 | 2853 | 10 | 1 | 3 |
| **After Phase F** | **572** | **3192** | **10** | **1** | 3 |

**No regressions.** The 11 findings are again the pre-existing `SetupWizardTest`
phpredis-environment set (see [`BASELINE_0_4.md`](BASELINE_0_4.md) §4).

**One intermediate failure was mine and is fixed:** an early gate run reported
12 failures — the extra one was `Phase35\ConnectorCatalogPageTest`, a real
regression from moving the slug. The test was updated to follow the redirect
rather than the redirect being removed, so the compatibility guarantee is now
pinned instead of untested.

---

## 5. Browser proof

Real browser, Chromium, authenticated, `http://127.0.0.1:8123`.

```
CATALOGUE: 200 · 0 console errors · 0 broken assets · 6 cards
ASSERTIONS: 35 pass · 0 fail
```

| Assertion | Result |
| --- | --- |
| `/admin/connectors` returns 200 | PASS |
| Has page description, search box, capability chips, trust filter | PASS |
| Every card has a name / icon / description / version / status / CTA (6/6 each) | PASS |
| Every card shows Migration, Live Sync **and** Trust (6/6) | PASS |
| Capabilities shown in product language — no raw keys as chips | PASS |
| **Search narrows 6 → 4** and every result genuinely mentions the term | PASS |
| Search surfaces the directly-matching connector | PASS |
| Clearing search restores 6 | PASS |
| **Capability filter narrows 6 → 3** and every survivor truly supports Live Sync | PASS |
| No-match search shows a self-explaining empty state | PASS |
| `/admin/connector-catalog` redirects to `/admin/connectors` | PASS |
| Filter chips expose `aria-pressed`; summary is an `aria-live` region | PASS |
| No flag is text-empty — not colour-only (§34) | PASS |
| No horizontal overflow at 1366×768, 1440×900 **and** 1920×1080 | PASS |
| No capability flag wraps onto two lines (max 20px) | PASS |

**A real layout defect was found and fixed by this QA:** "Not supported" wrapped
onto two lines in the Live Sync column, pushing the three flags out of vertical
alignment and reading as a broken layout. Fixed with `white-space: nowrap` on the
flag, and the assertion is now pinned in the QA script.

**Two QA-script bugs were also corrected (not implementation bugs):** the search
assertion wrongly demanded the term appear in the card *name* (it is legitimate
for MySQL to match "postgres" because it migrates *into* PostgreSQL), and the
Live Sync filter read the flag from the wrong DOM node.

---

## 6. Security impact

| Item | Assessment |
| --- | --- |
| **Net change** | **Neutral-to-positive.** Access is now capability-based (`connectors.view`) instead of the legacy `projects.view`; the page reads manifests only. |
| Secrets | A dedicated test asserts no credential field name is rendered and no secret-shaped value appears. Connector credential *values* were never read by this page. |
| Manifest-declared icon | Validated against the installed set before render, so malformed input cannot cause a 500 |
| Broken connector | A connector whose definition fails to load is skipped and logged, not allowed to take down the catalogue |
| Arbitrary shell / SQL | Not exposed |
| Writes | None — the page cannot connect to, or modify, anything |

---

## 7. Known gaps (honest)

| Gap | Status |
| --- | --- |
| **Operations page redesign** | **Not built.** The Operations nav group lists the v0.3.0 project pages (Queues, Logs, Monitoring, Scheduler, Webhooks, Realtime) unchanged. |
| **Infrastructure page redesign** (Nodes/Services/Health/Topology) | **Not built** |
| **Security page redesign** (Members/Audit) | **Not built** |
| Installing / enabling a connector from the catalogue | **Not built.** The catalogue is read-only; installation remains a CLI/operator concern (`ConnectorPackageInstaller`). |
| Separate Connect / Analyze / Plan / Transfer journey screens | **Not built** |
| A dedicated Live Sync page | **Not built** |
| Wiring per-gate approve/reject controls to the Cutover UI | **Not built** |
| AI conversations, tool execution, Inspect Mode, approvals | **Not built** — Phases G–J |
| `docs/ai/NEXUS_COPILOT_ARCHITECTURE.md` | **Not written** — Phase G |
| Pre-existing storage 500 (`ProjectStorageManager::for`) | **Not fixed** — still open |

---

## 8. Gate

| Item | Value |
| --- | --- |
| Base | `ad693fc` (`v0.3.0`) |
| Preceding | `160a5bf` (Phase E) |
| **Phase F commit** | `7a79924` — `feat(0.4.0): Phase F — Connector Catalog as a product catalogue` |
| `v0.3.0` tag | still `ad693fc` — **not moved, not replaced, not retagged** |
| New tags published | **none** |
| `v0.4.0` published | **no** |

---

## 9. Gate decision

| Criterion | Status |
| --- | --- |
| Phase F internally green | **PASS** |
| Blocking tests: 0 new failures / 0 new errors | **PASS** (one self-introduced regression found and fixed before the gate) |
| Browser QA | **PASS** |
| Tenant isolation maintained | **PASS** |
| No secret sent to any AI provider | **PASS** (no provider configured; no AI call made) |
| No regression | **PASS** |

**Continue to Phases G–J (Nexus Copilot), the largest remaining block, then
Phase K (rc.1 readiness).**

**FINAL STATUS: `0.4.0_DEVELOPMENT_CONTINUES` — NOT ready for `v0.4.0-rc.1`.**
Stable is not published. Nothing was published by this phase.
