# Control Plane Design System (Phase 20C+)

Single source of truth for product UI. Filament stays as the application
framework (forms, tables, auth, actions); everything the owner *sees* as
"the platform" goes through these tokens and `cp-` components.

## Delivery

- `public/css/cp.css` — hand-authored, **no build step** (Vite is not built in
  this repo; Filament ships its own assets). Injected via the Filament
  `HEAD_END` render hook in `AdminPanelProvider`.
- Bump `ControlPlaneChrome::CSS_VERSION` (`app/Filament/Support/ControlPlaneChrome.php`)
  on every `cp.css` change — static-asset cache buster.
- Icons: **Heroicons only** (`Heroicon::Outlined*`), already the stack standard.

## Tokens (`:root` / `:root.dark`, light via `:root:not(.dark)`)

Colors: `--cp-bg / --cp-surface / --cp-surface-2 / --cp-surface-3`,
`--cp-border / --cp-border-strong`, `--cp-text / --cp-text-dim / --cp-text-faint`,
`--cp-accent` (+ `-strong`, `-ink`), `--cp-success / --cp-warning / --cp-danger / --cp-info`,
`--cp-code-bg`, `--cp-shadow`. Shape: `--cp-radius-sm / --cp-radius / --cp-radius-lg`.
Type: `--cp-font-sans` (Instrument Sans), `--cp-font-mono`.

## Components

| Class | Use |
|---|---|
| `.cp-subnav` (+ `__project`, `__group`, `__label`, `__tab[aria-current]`, `__switch`) | Project workspace tab bar. Grouped, single-row, horizontal scroll, sticky. Rendered by `HasProjectContext::subnav()`. Groups: Project / Access / Data / Build / Operate. |
| `.cp-pill` (+ `__name`, `__env`) | Topbar active-project context (name + env + health dot), links to Overview. Rendered by `ControlPlaneChrome::projectPillHook()`; empty string off project pages; never breaks rendering (try/catch). |
| `.cp-dot.is-*` | Status dots: `is-healthy/ok/success`, `is-unhealthy/error/danger`, `is-unknown/pending/warning`, `is-info`. |
| `.cp-badge.is-*` | Small status badges, same state vocabulary. |
| `.cp-code` (+ `__toolbar`) | Monospace panel for SQL, function code, payloads (display; editing comes with Monaco/CodeMirror in 20F/20H). |
| `.cp-empty` (+ `__icon`, `__title`, `__hint`) | Empty states: icon circle + title + guidance + next action. |
| `.cp-skeleton` | Loading shimmer (disabled under `prefers-reduced-motion`). |
| `.cp-error` | Error panels (user-safe messages only — never raw exceptions with paths). |
| `.cp-kv` (`dl/dt/dd`) | Key/value fact rows (health, connections, overview facts). |
| `.cp-drawer[data-open]` | Right slide-over for detail views (log detail in 20Q, etc.). |
| `.cp-tablewrap` | Horizontal-scroll wrapper for wide tables. |
| `.fi-ta-row:hover` | Subtle row hover (only Filament override; internals otherwise untouched). |

## Shell layout

```text
Sidebar (Home · Projects group · Governance group, Heroicons, collapsible)
Topbar (global search · cp-pill project context · user menu)
Content (breadcrumbs · cp-subnav on project pages · page)
```

Rules: the owner must always see (1) which project is active, (2) environment,
(3) stored health state, (4) whether an action is safe or destructive
(destructive = confirmation modal + danger styling, no exceptions).

## States & safety

- Destructive actions: `requiresConfirmation()` + danger color; bulk destructive
  needs strong confirmation (per-phase implementation).
- Secrets: states only (`Configured / Not configured`), never values — see
  Settings page pattern; enforced by tests.
- Errors shown to owners must be curated strings, not exception dumps
  (counter-example fixed in 20C backlog: Schema "… .env. ()" message).

## Responsive

- Subnav scrolls horizontally; pill compacts under 768px (env hidden).
- Tables scroll inside `.cp-tablewrap`; drawers go near-full-width on mobile.
- Narrow widths are verified with real mobile emulation (`mobile: true`),
  not desktop squeezed — see 20A correction note in `PHASE20_UI_AUDIT.md`.

## Backlog from 20A audit (visual system adjacent, tracked for later phases)

- Schema page connection-path bug + message quality (20E + fix).
- Database list layout + Est. rows -1 (20E).
- Monitoring raw Pulse dump → readable views (20D/20R).
- API/Realtime terminal pointers → in-GUI workflows (20I/20N).
- Dashboard placeholders + contradictory health line (20D).
