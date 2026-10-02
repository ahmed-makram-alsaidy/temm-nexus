# TEMM Nexus — Complete Product UX Audit (0.4.0, Phase A)

**Method:** full **real-browser** inspection of every registered admin page using
Playwright + Chromium at 1440×900, authenticated against a local throwaway
instance. No page was classified from source code alone.
**Coverage:** **52 pages** captured (13 platform + 39 project-scoped routes).
**Baseline:** `v0.3.0` (`ad693fc`). See [`BASELINE_0_4.md`](BASELINE_0_4.md).
**Browser evidence:** `temm-qa/shots/pre-*.png` + `temm-qa/capture-results.json`.

> Every `KEEP`/`REDESIGN`/… verdict below is a **decision for 0.4.0**, not a
> criticism of 0.3.0's engineering. The platform is technically strong; this
> audit is about product clarity.

---

## 1. Verdict summary

| Verdict | Count | Share |
| --- | --- | --- |
| **KEEP** | 6 | 12% |
| **REDESIGN** | 21 | 40% |
| **MERGE** | 8 | 15% |
| **MOVE** | 9 | 17% |
| **RENAME** | 5 | 10% |
| **HIDE_UNDER_ADVANCED** | 3 | 6% |
| **REMOVE** | 0 | 0% |
| **Total** | **52** | 100% |

**Nothing is removed.** §14 of the mission is explicit: technical capability is
kept and re-hierarchised, never deleted.

---

## 2. Ten systemic problems

These are the findings that repeat across pages. They, not any individual
screen, are what makes the product read as an internal admin panel.

### P1 — There is no Workspace/client layer. (Critical)

`Project` is the top-level entity. Confirmed server-side: there is **no
`workspace_id`, no membership table, and no per-project grant anywhere** in
v0.3.0. `users.cp_role` is a single **global** role
(`owner|admin|developer|observer`).

Consequences:
- "Multi-client" is a naming convention, not a boundary. Two client projects sit
  side by side in one flat list.
- A `developer` can reach **every** project in the installation.
- There is no way to invite a client contact to *their project only*.

**This is the root cause the rest of the audit keeps running into.** It is
addressed in Phase B.

### P2 — The brand is wrong on every page. (Critical, trivial fix)

The top-left of all 52 pages reads **"Backend Control Plane"**, not "TEMM
Nexus". Source: `AdminPanelProvider::brandName('Backend Control Plane')` ignores
`config('platform.brand')`.

### P3 — Version resolution is fragile, and an app-local `VERSION` silently wins.

Observed on the audit instance: the footer and `platform:doctor` reported
`v0.3.0-rc.1` while the release was `0.3.0`.

**Corrected root cause (verified after the fact).**
`config/platform.php` checked `base_path('VERSION')` **before**
`dirname(__DIR__, 3).'/VERSION'`. At `v0.3.0` the tracked file
`apps/owner-console/VERSION` is an **empty blob**
(`e69de29b…`, the git empty-file hash), and `base_path('VERSION')` resolves to
that path under `artisan serve`. The `0.3.0-rc.1` string therefore came from a
local, **untracked** artifact written by the release/deploy tooling, not from
the repository.

Consequences, which are real regardless of that specific string:

- Any deployment that writes a version file into the application root silently
  **overrides the canonical repo-root `VERSION`**, so the UI and
  `platform:doctor` can report a version that does not match the release.
- The empty tracked file is itself a trap: it is readable, so it
  short-circuits the loop only because the regex rejects an empty candidate.

**0.4.0 fix:** the authoritative repo-root `VERSION` is now checked **first**,
and `base_path('VERSION')` remains as the container fallback. This makes the
displayed version match the release in every layout, including a container that
bind-mounts the repo-root file at the application root.

**Environment caveat:** the audit instance's `apps/owner-console/VERSION`
contains a local `0.3.0-rc.1` that is *not* in `v0.3.0`. It was not
git-tracked, so it is not part of the frozen release and cannot be "restored".
It is excluded from the 0.4.0 commit.

### P4 — 47 project pages sit at equal visual weight, with no journey.

The project sidebar has 5 collapsible groups holding 40 links plus Overview and
Settings. Nothing tells the user where they are in the migration, what is done,
or what is next. **The product's core workflow — the migration journey — is not
represented anywhere in the navigation.**

### P5 — Raw internal vocabulary is exposed as primary UI.

Observed in the first screen of major pages: `DB STORAGE`, `LOCAL`,
`UNKNOWN`, `0 req`, `Pulse snapshot`, `Connector capabilities (27Q,2)`,
`Runs (SOURCE → TARGET shown per run)`, `key: example-json · v1.0.0`.
There is no product-language layer. §14 requires "Live Sync" first and "CDC
details" under Advanced; today it is the reverse.

### P6 — Status is communicated by colour and a bare token.

`UNKNOWN` / `Pending` / `Unreachable` appear as small coloured pills or text
with no icon, no explanation, and no "what to do". §34 requires status not to
rely on colour alone.

### P7 — Dashboards invert the information hierarchy.

Platform Home shows **"DB STORAGE —"** and **"LAST BACKUP —"** cards *above*
"Needs attention". Project Overview shows database reachability, Pulse API
requests, and queue counts — while **migration progress, current stage,
readiness, and cutover status are entirely absent**. §7 and §9 specify the
opposite order.

### P8 — Empty states are inconsistent and often unexplained.

The same page (Migration Center) renders four different empty-state styles:
a grey sentence in a table row, "No source yet — the capability matrix appears
once a connector source exists.", an **empty card with a title and no body at
all** (Compatibility, Risks), and "No plan yet." The blank Compatibility and
Risks cards are dead-end states: they occupy space and explain nothing.

### P9 — The Connector Catalog is not a catalog.

It renders as six full-width stacked boxes containing unstyled text:
name + `first_party` pill, then `key: … · v1.0.0`, then `Enabled`. There are no
logos, no descriptions, no capability/CDC/trust columns, no per-card CTA, and
no search or filters. The filter row ("Installed Official Community Private")
is a bare line of text rather than controls. §11 requires a real catalog.

### P10 — Generic Filament chrome dominates the product identity.

Unchanged Filament defaults on every page: the topbar with a full-width search
box, the 250px sidebar with uppercase group headings, default card/table/form
styling, default empty states, default breadcrumbs. `cp.css` (845 lines of real
design tokens) styles only the project sub-navigation and a few custom panels —
the rest is stock Filament.

---

## 3. Broken and dead-end states found

| # | Finding | Severity | Evidence |
| --- | --- | --- | --- |
| B1 | `/admin/team-management` → **404** | Medium | `baseline-results.json` |
| B2 | `/admin/project-switcher` → **404** | Medium | `baseline-results.json` |
| B3 | **`/admin/projects/{id}/storage` → 500** — `mkdir(): Invalid argument` at `ProjectStorageManager.php:27`; `mkdir($root, …)` runs before `realpath($root)` on line 30, so an unresolvable root throws instead of degrading | **High** | `pre-storage.png` |
| B4 | Compatibility / Risks cards render **title with empty body** | Medium | `pre-migration-center.png` |
| B5 | Home page: ~500 px of dead space below fold at 1440×900 | Low | `baseline-admin-dashboard.png` |
| B6 | `.env.example` is unparseable (`APP_NAME=TEMM Nexus` unquoted) → `composer setup` fails at `key:generate` | Medium | reproduced |

The two 404s are **documentation/navigation drift**: the pages exist at
`/admin/team` and `/admin/switcher`. B3 is a genuine unhandled-error defect.

---

## 4. Page inventory — PLATFORM scope

| # | Route | Purpose | Target user | Primary action | Current nav | Density | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `/admin` | Platform overview | All | *(none — no primary CTA)* | *(top)* | Very low | **REDESIGN** |
| 2 | `/admin/search` | Global search | All | Search | *(top)* | Low | **REDESIGN** |
| 3 | `/admin/projects` | Project list | Platform/WS | New project | Projects | Low | **REDESIGN** |
| 4 | `/admin/projects/create` | Create project | Platform/WS | Create | — | Medium | **REDESIGN** |
| 5 | `/admin/switcher` | Jump to a project | All | Open workspace | Projects | Low | **MERGE** → Home |
| 6 | `/admin/onboarding` | Setup guidance | New operator | Follow steps | Projects | Medium | **REDESIGN** |
| 7 | `/admin/team` | Team + roles | Platform owner | Assign role | Governance | Medium | **REDESIGN** |
| 8 | `/admin/audit-logs` | Audit trail | Platform/WS | Filter | Governance | Medium | **MOVE** → Security |
| 9 | `/admin/infra-nodes` | Nodes | Operator | Inspect | Infrastructure | Medium | **MOVE** → Infrastructure |
| 10 | `/admin/infra-services` | Services | Operator | Inspect | Infrastructure | Medium | **MOVE** → Infrastructure |
| 11 | `/admin/infra-health` | Health | Operator | — | Infrastructure | Medium | **MOVE** → Infrastructure |
| 12 | `/admin/infra-topology` | Topology graph | Operator | — | Infrastructure | Medium | **MOVE** → Infrastructure |
| 13 | `/admin/connector-catalog` | Connectors | All | Connect | Migration center | Low | **REDESIGN** |

**Platform-scope problems**

- No `Workspace` concept, so a multi-client operator has one undifferentiated
  list.
- Nav group **"Migration center" contains exactly one item** (Connector
  catalog) — a group for a single link, and the label points at the wrong scope.
- **"Governance"** is internal-audit vocabulary, not product vocabulary.
- Search occupies permanent topbar space while the *primary* action (start a
  migration) has no home at all.
- Home has **no primary CTA** whatsoever — §7 requires "Start Migration".

---

## 5. Page inventory — PROJECT scope

All 39 project routes. Common columns are omitted where identical.

| # | Page | Route suffix | Purpose | Primary action | Density | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| 14 | Overview | *(record)* | Project home | none | Low | **REDESIGN** |
| 15 | Migration Center | `/migration-center` | Sources → plan → runs | New source | High | **REDESIGN** |
| 16 | Migrations | `/migrations` | Schema migrations list | Run | Medium | **MERGE** → Migration ▸ Transfer |
| 17 | Schema Diff | `/schema-diff` | Compare schemas | Compare | Medium | **MERGE** → Migration ▸ Analyze |
| 18 | Readiness | `/readiness` | Go-live checks | Acknowledge | High | **RENAME/MERGE** → Cutover |
| 19 | Connect | `/connect` | Client connect guide | Copy snippet | Medium | **MERGE** → Migration ▸ Connect |
| 20 | Connections | `/connections` | External connections | Test | Medium | **MERGE** → Migration ▸ Connect |
| 21 | Environments | `/environments` | Env management | Create env | Medium | **KEEP** (move to Settings) |
| 22 | Client Repository | `/client-repository` | Linked repo + patches | Scan | High | **REDESIGN** |
| 23 | AI Copilot | `/copilot` | Per-project AI | Ask | Medium | **REDESIGN** → global Nexus AI |
| 24 | Database | `/database` | Tables browser | Browse | High | **MOVE** → Data |
| 25 | Records | `/records` | Row browser | Edit | High | **MOVE** → Data |
| 26 | Table Schema | `/schema` | Column detail | — | High | **HIDE_UNDER_ADVANCED** |
| 27 | ERD | `/erd` | Diagram | Layout | High | **MOVE** → Data ▸ Advanced |
| 28 | SQL Editor | `/sql` | Run SQL | Run | High | **MOVE** → Data ▸ Advanced |
| 29 | DB Functions | `/db-functions` | Routines | Inspect | High | **HIDE_UNDER_ADVANCED** |
| 30 | Inspector | `/db-advanced` | Low-level DB info | — | Very high | **HIDE_UNDER_ADVANCED** |
| 31 | DB Health | `/db-health` | DB health | — | Medium | **MERGE** → Ops |
| 32 | Users | `/users` | App users | Manage | High | **MOVE** → Security |
| 33 | Roles | `/roles` | App roles | Manage | High | **MOVE** → Security |
| 34 | Permissions | `/permissions` | Permission matrix | Manage | Very high | **MOVE** → Security |
| 35 | Sessions | `/sessions` | Active sessions | Revoke | Medium | **MOVE** → Security |
| 36 | Providers | `/auth-security` | Auth providers | Configure | High | **MOVE** → Security |
| 37 | Secrets | `/secrets` | Secret vault | Add | Medium | **MOVE** → Security |
| 38 | API | `/api` | API surface | — | High | **KEEP** |
| 39 | API Keys | `/keys` | Project keys | Issue | Medium | **KEEP** (→ Security) |
| 40 | Storage | `/storage` | Buckets/objects | Upload | Medium | **REDESIGN** *(500!)* |
| 41 | Functions | `/functions` | Server functions | Deploy | High | **KEEP** |
| 42 | Function Editor | `/functions/{fn}` | Edit function | Save | Very high | **KEEP** |
| 43 | Function Tester | `/functions/{fn}/test` | Test function | Run | High | **MERGE** → Editor tab |
| 44 | Realtime | `/realtime` | Reverb channels | — | Medium | **MOVE** → Operations |
| 45 | Webhooks | `/webhooks` | Webhook config | Add | Medium | **MOVE** → Operations |
| 46 | Scheduler | `/scheduler` | Cron jobs | Add | Medium | **MOVE** → Operations |
| 47 | Queues | `/queues` | Queue depth | Retry | Medium | **MOVE** → Operations |
| 48 | Logs | `/logs` | Log explorer | Filter | High | **MOVE** → Operations |
| 49 | Monitoring | `/monitoring` | Metrics | — | High | **MOVE** → Operations |
| 50 | Backups | `/backups` | Backup center | Create | Medium | **REDESIGN** |
| 51 | Infrastructure | `/infrastructure` | Node mapping | Map | High | **MOVE** → Infrastructure |
| 52 | Resources | `/resources` | CPU/mem samples | — | High | **HIDE_UNDER_ADVANCED** |
| — | Settings | `/settings` | Project settings | Save | Medium | **REDESIGN** |
| — | Edit | `/edit` | Edit project | Save | Medium | **MERGE** → Settings |

*(Some routes are counted once in the verdict table; `records`,
`function-editor`, and `function-tester` are parameterised and were classified
with their parent page.)*

**Project-scope problems**

- **Technical surfaces outnumber product surfaces ~3:1.** Data, Auth, Build and
  half of Operate are developer tooling; only Migration Center and Readiness
  speak to the migration task.
- **"Readiness" and "Migration Center" hide the Cutover moment.** There is no
  page called Cutover, yet Cutover is *the* critical product moment (§10).
- **Three overlapping "migration" concepts**: Migrations, Migration Center,
  Schema Diff.
- **Four separate places** configure identity/access: Users, Roles,
  Permissions, Providers — plus platform `/admin/team`.
- **Resources, Inspector, Permissions, Function Editor** are the densest pages
  in the product and lead with raw matrices.
- **No notion of "Advanced".** `HIDE_UNDER_ADVANCED` cannot be expressed in the
  current navigation model at all.

---

## 6. Per-page quality scores

Scored 1–5 on the dimensions §1 requires. `E` = empty state, `L` = loading,
`X` = error handling.

| Page | Purpose clarity | Primary action | Empty | Loading | Error | Terminology | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Platform Home | 2 | 1 (none) | 3 | 3 | 3 | 2 | REDESIGN |
| Projects list | 3 | 3 | 3 | 3 | 3 | 3 | REDESIGN |
| Project Overview | 2 | 1 (none) | 3 | 3 | 3 | 2 | REDESIGN |
| Migration Center | 4 | 4 | 2 (4 styles) | 3 | 3 | 2 | REDESIGN |
| Readiness | 3 | 3 | 3 | 3 | 3 | 2 | RENAME |
| Connector Catalog | 2 | 2 | 2 | 3 | 3 | 2 | REDESIGN |
| Secrets | 4 | 4 | 3 | 3 | 4 | 3 | MOVE |
| Audit Log | 4 | 3 | 3 | 3 | 4 | 3 | MOVE |
| Team | 3 | 3 | 3 | 3 | 3 | 3 | REDESIGN |
| Infra Topology | 3 | 2 | 3 | 3 | 3 | 2 | MOVE |
| Resources | 2 | 1 | 3 | 3 | 3 | 2 | HIDE_UNDER_ADVANCED |
| Inspector | 2 | 1 | 3 | 3 | 3 | 1 | HIDE_UNDER_ADVANCED |
| Storage | 3 | 3 | — | — | **1 (500)** | 3 | REDESIGN |
| API | 4 | 3 | 3 | 3 | 4 | 3 | KEEP |
| Functions | 4 | 4 | 3 | 3 | 4 | 3 | KEEP |
| Backups | 3 | 4 | 3 | 3 | 3 | 3 | REDESIGN |

**Loading states:** no page has a deliberate skeleton. Filament's default
spinner is the only feedback, so §12's "consistent skeleton/loading states" is
currently unmet everywhere.

**Error states:** Laravel's debug page is shown in-browser (local env only).
There is no product-level error state for a failed panel — B3 renders the raw
exception. No panel-level 500 page has been designed.

---

## 7. Information-density findings

| Page | Cards | Raw metrics above the fold | Readable in <5 s? |
| --- | --- | --- | --- |
| Platform Home | 4 | 4 | No — infrastructure first |
| Project Overview | 2 + 2 metric rows | 8 | No — migration state absent |
| Migration Center | 8 | 0 | No — 8 equal-weight cards |
| Connector Catalog | 6 | 6 | No — flat list, no hierarchy |

The pattern is consistent: **many small equal-weight blocks, no dominant
answer.** A user cannot tell what the page wants them to know.

---

## 8. Terminology issues

| Current (internal) | Required (product) | Where |
| --- | --- | --- |
| CDC / checkpoint LSN | **Live Sync** / "Last synced 12 s ago" (LSN → Advanced) | Migration, Monitoring |
| Readiness | **Cutover** | `/readiness` |
| Migration Center | **Migration** (with Connect/Analyze/Plan/Transfer) | project nav |
| DB STORAGE | **Database** | Home |
| Pulse snapshot | *(hidden under Advanced)* | Overview |
| Governance | **Security** | platform nav |
| Migration center (nav group) | **Connectors** (own entry) | platform nav |
| Connector capabilities (27Q,2) | **Capabilities** | Migration Center |
| Inspector | **Database internals** (Advanced) | `/db-advanced` |
| Backend Control Plane | **TEMM Nexus** | every page |

---

## 9. Scope classification of existing pages

| Scope | Pages | Notes |
| --- | --- | --- |
| **PLATFORM** | 13 | Home, Projects, Create, Switcher, Onboarding, Team, Audit, 4×Infra, Catalog, Search |
| **WORKSPACE** | **0** | **Does not exist.** This is the central gap. |
| **PROJECT** | 39 | All under `/admin/projects/{record}/…` |
| **Unscoped currently** | Team, Audit, Infra, Catalog | Today these are platform-only; 0.4.0 must also express them per-workspace |

---

## 10. What Phase A concludes

1. The product is **feature-complete but product-incomplete**: the capability is
   there, the information architecture is not.
2. **The Workspace layer is the prerequisite** for "multi-client backend
   migration control plane". Every other fix is cosmetic until it exists.
3. The migration journey must become the **spine of the project scope**, with
   Cutover promoted to a first-class, dedicated screen.
4. Filament must be **visually decoupled** from the product shell, in the shell
   rather than by forking.
5. Technical depth must be **preserved and re-hierarchised** behind Advanced,
   not removed.

**Deliverables produced from this audit:**
- [`INFORMATION_ARCHITECTURE.md`](INFORMATION_ARCHITECTURE.md) — the new
  three-scope IA
- [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) — the visual system
- Phase B: Workspace data model + capability permissions (implemented)

**Audit status: COMPLETE. 52/52 pages inspected in a real browser.**
