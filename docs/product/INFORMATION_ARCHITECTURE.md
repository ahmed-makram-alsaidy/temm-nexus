# TEMM Nexus — Information Architecture (0.4.0)

**Status:** design of record for 0.4.0.
**Derived from:** [`UX_AUDIT_0_4.md`](UX_AUDIT_0_4.md).
**Base:** v0.3.0 (frozen).

---

## 1. The three scopes

The product is organised around exactly three nested scopes. Every page,
permission, AI conversation, and audit entry belongs to one of them, and the
scope must be **visually obvious at all times**.

```
PLATFORM                        (the installation you are operating)
   └── WORKSPACE                (a client, company, internal team — an org boundary)
          └── PROJECT           (one migration / one backend)
```

| Scope | Answers the question | Boundary means | Typical holder |
| --- | --- | --- | --- |
| **PLATFORM** | "Is the whole installation healthy? Who is on it?" | The self-hosted install | Platform Owner / Platform Admin |
| **WORKSPACE** | "How is *this client* doing?" | Client data + projects must not leak across workspaces | Workspace Owner / Admin / Member |
| **PROJECT** | "Where is *this migration*?" | One backend, one schema, one cutover | Project Admin / Developer / Operator / Viewer |

### Why this ordering

The mission's example — *Client: Nayrouz → Projects: Website Production,
Booking Backend, Internal CRM* — cannot be expressed in v0.3.0 at all, because
`Project` is the top-level entity. Everything below follows from adding the
middle scope.

---

## 2. Scope signals (how the UI must show where you are)

| Signal | PLATFORM | WORKSPACE | PROJECT |
| --- | --- | --- | --- |
| Global nav | full, 9 entries | full, workspace entries emphasised | **collapses** to project nav |
| Breadcrumb | `Platform` | `Platform ▸ <Workspace>` | `Platform ▸ <Workspace> ▸ <Project>` |
| Persistent context chip | none | workspace name + health dot | workspace + project + environment |
| Accent | neutral | workspace identity colour | inherited from workspace |
| AI context label | `Context: Platform` | `Context: Workspace — Nayrouz` | `Context: Project — Wasla` |

**Rule:** entering a project switches navigation into **PROJECT CONTEXT**. It is
not a submenu of the platform nav — the whole navigation model changes, and a
"← Back to <Workspace>" control is always present.

**Rule:** the AI context label is never inferred from the last conversation.
It is bound to the route. Crossing a scope boundary requires an explicit user
action and is recorded.

---

## 3. Global navigation (PLATFORM scope)

Exactly **nine** top-level entries. The v0.3.0 sidebar had 5 groups holding 11
links, one of which was a group containing a single link.

| # | Entry | Answers | Owning scope | Absorbs from v0.3.0 |
| --- | --- | --- | --- | --- |
| 1 | **Home** | What needs me now? | PLATFORM | Dashboard, Switcher, Search |
| 2 | **Projects** | All work, filterable | PLATFORM / WS | Projects, Create |
| 3 | **Clients / Workspaces** | Who we work for | PLATFORM | *(new)* |
| 4 | **Connectors** | What I can migrate from | PLATFORM / WS | Connector catalog |
| 5 | **Operations** | What is running / failing | PLATFORM / WS / PROJ | Queues, Logs, Monitoring, Scheduler, Webhooks, Realtime |
| 6 | **Infrastructure** | What it runs on | PLATFORM | Nodes, Services, Health, Topology |
| 7 | **Security** | Who can do what, and proof | PLATFORM / WS | Team, Audit Log, Roles, Permissions, Secrets, Providers, Sessions |
| 8 | **Nexus AI** | Ask / diagnose / act | PLATFORM / WS / PROJ | AI Copilot (per-project) |
| 9 | **Settings** | Configure the platform | PLATFORM | Platform settings, Environments |

### Design rules

1. **No group with one child.** If a group has one page, it is a page.
2. **No more than nine top-level entries.** New capability joins an existing
   entry or goes under Advanced.
3. **Scope filtering, not duplication.** Operations/Security/Connectors render
   *filtered to the active scope* rather than existing twice.
4. **`Advanced` is a first-class destination**, not a scattering of links.
5. **Primary CTA is always visible:** *Start Migration* on Home.
6. Search moves out of permanent topbar real estate into a command palette
   (`/` or ⌘K) plus the existing `/admin/search` page.

### Mapping: v0.3.0 nav → 0.4.0 nav

| v0.3.0 group | v0.3.0 items | Destination |
| --- | --- | --- |
| *(top)* | Dashboard | Home |
| *(top)* | Search | command palette + Advanced |
| Projects | Onboarding Wizard | Home ▸ Get started (progressive) |
| Projects | Project Switcher | **merged** into Home recents |
| Projects | Projects | Projects |
| Infrastructure | Nodes | Infrastructure ▸ Nodes |
| Infrastructure | Services | Infrastructure ▸ Services |
| Infrastructure | Health | Infrastructure ▸ Health |
| Infrastructure | Topology | Infrastructure ▸ Topology |
| Governance | Audit Log | **Security** ▸ Audit |
| Governance | Team | **Security** ▸ Members |
| Migration center | Connector catalog | **Connectors** |
| *(none)* | — | **Clients / Workspaces** *(new)* |
| *(none)* | — | **Nexus AI** *(new global)* |

---

## 4. Project navigation (PROJECT scope)

The project scope is the **migration journey**, in order. This is the single
most important IA change.

```
Project
├── Overview            where am I, what is next
├── Migration           the journey spine
│   ├── Connect         source/target connections
│   ├── Analyze         inventory, compatibility, risk
│   ├── Plan            reviewable plan + diff
│   └── Transfer        initial data movement
├── Data                tables, records, storage
├── Live Sync           CDC, lag, reconciliation
├── Validation          row counts, checksums, rules
├── Cutover             readiness + approvals + execution
├── Backups             policies, runs, restore drills
├── Activity            events, audit, logs for this project
├── Advanced            technical depth (progressive disclosure)
└── Settings            project config, environments, members, secrets
```

### Project journey states

Every journey step renders exactly one of these, in product language:

| State | Means | Visual |
| --- | --- | --- |
| `NOT_STARTED` | Available, not begun | Hollow circle, muted |
| `IN_PROGRESS` | Running now | Filled + progress indicator |
| `READY` | Complete, awaiting next | Check + "Ready" |
| `NEEDS_ATTENTION` | Complete but degraded | Warning icon + reason |
| `BLOCKED` | Cannot proceed | Stop icon + **what blocks it** |
| `COMPLETE` | Done and verified | Check, settled |

**Rule:** raw internal status tokens (`draft`, `paused`, `unknown`) are never
the only label. A product label is primary; the raw token may appear in
Advanced.

### Mapping: v0.3.0 project pages → 0.4.0 project nav

| 0.4.0 destination | Absorbs |
| --- | --- |
| Overview | Overview, Readiness summary, health summary |
| Migration ▸ Connect | Connect, Connections, Migration Center (sources) |
| Migration ▸ Analyze | Migration Center (analysis), Schema Diff, Compatibility |
| Migration ▸ Plan | Migration Center (plan/risks), Migrations |
| Migration ▸ Transfer | Migration Center (runs), Migrations |
| Data | Database, Records, Storage |
| Live Sync | *(CDC surfaces, currently inside Monitoring/Migration)* |
| Validation | Readiness (data checks), Reconciliation |
| **Cutover** | **Readiness**, Cutover Center |
| Backups | Backups |
| Activity | Recent activity, Audit Log (project), Logs |
| Advanced | Table Schema, ERD, SQL Editor, DB Functions, Inspector, Resources, Permissions matrix |
| Settings | Settings, Edit, Environments, Users, Roles, Providers, Sessions, Secrets, API Keys, API, Functions, Function Editor, Realtime, Webhooks, Scheduler, Queues, Monitoring, Infrastructure |

---

## 5. Workspace scope

A workspace page is the client's home.

```
Workspace
├── Overview        identity, health, projects, activity, AI summary
├── Projects        projects in this workspace
├── Members         who has access, at what role
├── Operations      filtered operational view
├── Security        workspace-scoped access + audit
└── Settings        identity, timezone, defaults
```

Primary actions: **New Project**, **Invite Member**, **Ask Nexus AI**.

A workspace must never render another workspace's data, and the server — not
the navigation — enforces that.

---

## 6. Page ownership matrix

Every v0.3.0 page has exactly one 0.4.0 owner. No page is orphaned.

| v0.3.0 page | 0.4.0 scope | 0.4.0 location |
| --- | --- | --- |
| Dashboard | PLATFORM | Home |
| Search | PLATFORM | Command palette + Advanced |
| Projects | PLATFORM | Projects |
| Create project | PLATFORM/WS | Projects ▸ New |
| Switcher | PLATFORM | *(merged)* Home ▸ Recent |
| Onboarding | PLATFORM | Home ▸ Get started |
| Team | PLATFORM | Security ▸ Members |
| Audit Log | PLATFORM/WS | Security ▸ Audit |
| Infra Nodes | PLATFORM | Infrastructure ▸ Nodes |
| Infra Services | PLATFORM | Infrastructure ▸ Services |
| Infra Health | PLATFORM | Infrastructure ▸ Health |
| Infra Topology | PLATFORM | Infrastructure ▸ Topology |
| Connector Catalog | PLATFORM | Connectors |
| — | WORKSPACE | Clients / Workspaces *(new)* |
| Project Overview | PROJECT | Overview |
| Migration Center | PROJECT | Migration ▸ Connect/Analyze/Plan/Transfer |
| Migrations | PROJECT | Migration ▸ Transfer |
| Schema Diff | PROJECT | Migration ▸ Analyze |
| Readiness | PROJECT | **Cutover** |
| Connect | PROJECT | Migration ▸ Connect |
| Connections | PROJECT | Migration ▸ Connect |
| Environments | PROJECT | Settings ▸ Environments |
| Client Repository | PROJECT | Migration ▸ Plan (patches) |
| AI Copilot | PROJECT | **Nexus AI** (global, project-scoped) |
| Database | PROJECT | Data |
| Records | PROJECT | Data ▸ Table |
| Table Schema | PROJECT | Advanced |
| ERD | PROJECT | Advanced |
| SQL Editor | PROJECT | Advanced |
| DB Functions | PROJECT | Advanced |
| Inspector | PROJECT | Advanced |
| DB Health | PROJECT | Advanced |
| Users/Roles/Permissions/Sessions/Providers/Secrets | PROJECT | Settings ▸ Access *(matrix → Advanced)* |
| API | PROJECT | Settings ▸ API |
| API Keys | PROJECT | Settings ▸ API |
| Storage | PROJECT | Data ▸ Storage |
| Functions / Editor / Tester | PROJECT | Settings ▸ Functions |
| Realtime / Webhooks / Scheduler / Queues | PROJECT | Operations |
| Logs / Monitoring | PROJECT | Activity / Operations |
| Backups | PROJECT | Backups |
| Infrastructure | PROJECT | Infrastructure ▸ Mapping |
| Resources | PROJECT | Advanced |
| Settings / Edit | PROJECT | Settings |

---

## 7. Navigation implementation contract

1. **One primary navigation.** No horizontal secondary bar duplicating the
   sidebar (v0.3.0 already moved away from this — keep it that way).
2. **Active state is unambiguous** on exactly one item.
3. **Collapsible groups remember state** per user, persisted as a
   `user_ui_preferences` row.
4. **Project context is detectable server-side**, not from a session flag set by
   clicking: it comes from the route's bound `Project`. (v0.3.0's
   `ControlPlaneChrome::currentProject()` is the right instinct and is kept.)
5. **Navigation never grants access.** Every destination re-checks the
   capability server-side.

---

## 8. URL contract

| Scope | Pattern | Example |
| --- | --- | --- |
| PLATFORM | `/admin/<area>` | `/admin/connectors` |
| WORKSPACE | `/admin/workspaces/{workspace}` | `/admin/workspaces/nayrouz` |
| PROJECT | `/admin/projects/{project}/<page>` | `/admin/projects/wasla/cutover` |

**Backward compatibility:** every v0.3.0 project URL
(`/admin/projects/{record}/<page>`) keeps resolving. Pages that move get a
redirect, so an operator's bookmarks and any external link keep working.

---

## 9. What is explicitly *not* changing

- The migration/CDC/security **guarantees**. No IA change may weaken them.
- The **verification chain** PLAN → DIFF → HUMAN APPROVAL → APPLY → VERIFY.
- **Technical depth.** Everything hidden under Advanced remains reachable.
- **Existing URLs.** They redirect; they do not 404.
