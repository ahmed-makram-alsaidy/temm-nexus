# TEMM Nexus — Design System (0.4.0)

**Status:** design of record for 0.4.0.
**Asset:** `apps/owner-console/public/css/nexus.css` (loaded after Filament's own
stylesheet via the panel `HEAD_END` render hook).
**Legacy:** `public/css/cp.css` is retained — it styles the existing project
sub-navigation and custom panels. New work targets `nexus.css`.

---

## 1. Principles

| Principle | Consequence |
| --- | --- |
| **Calm over dense** | More whitespace, fewer cards, one dominant answer per screen |
| **Product language first** | "Live Sync" before "CDC"; raw tokens move to Advanced |
| **State must be unmistakable** | Every state carries an icon **and** a label, never colour alone |
| **Progressive disclosure** | Technical depth is one deliberate click away, never deleted |
| **Restrained hierarchy** | Max 3 visual weights per screen; no decorative gradients |
| **One primary action** | Exactly one primary CTA per screen; everything else is secondary |

The target feeling is *premium, modern, technical, calm, professional,
high-trust*. Explicitly not: generic admin panel, template-marketplace UI,
overloaded dashboard, random-gradient SaaS.

---

## 2. Tokens

All values are CSS custom properties on `:root`, scoped under `html` so they win
over Filament's defaults. Dark and light are both first-class.

### 2.1 Colour

```css
/* Surfaces — 4 levels, no more */
--nx-bg:               /* page background */
--nx-surface:          /* card / panel */
--nx-surface-raised:   /* popover, dropdown, drawer */
--nx-surface-sunken:   /* table header, inset area */

/* Borders — 2 weights */
--nx-border:           /* default hairline */
--nx-border-strong:    /* emphasis, focus ring base */

/* Text — 3 weights */
--nx-text:             /* primary */
--nx-text-muted:       /* secondary / labels */
--nx-text-subtle:      /* metadata, timestamps */

/* Brand */
--nx-accent:           /* primary action, active nav */
--nx-accent-hover:
--nx-accent-contrast:  /* text on accent */

/* Semantic — each has a soft companion for tinted surfaces */
--nx-success:  --nx-success-soft:
--nx-warning:  --nx-warning-soft:
--nx-danger:   --nx-danger-soft:
--nx-info:     --nx-info-soft:
--nx-neutral:  --nx-neutral-soft:
```

Dark is the default (a control plane is used in dark rooms); light is derived by
swapping the surface/text/border groups only — semantic and accent hues are
shared so status colour never shifts meaning between themes.

### 2.2 Spacing — 4px base, eight steps

`--nx-space-1: 4px` · `2: 8` · `3: 12` · `4: 16` · `5: 20` · `6: 24` · `8: 32` · `10: 40`

**Density rules**
- Page gutter: `space-6` (24px) desktop, `space-4` mobile.
- Between page sections: `space-8` (32px).
- Card internal padding: `space-5`/`space-6`.
- Label → value: `space-1`. Value → next field: `space-4`.

### 2.3 Radius

`--nx-radius-sm: 6px` · `--nx-radius: 10px` · `--nx-radius-lg: 14px` · `--nx-radius-full: 999px`

Cards and drawers use `--nx-radius-lg`; inputs and buttons `--nx-radius`;
badges `--nx-radius-full`.

### 2.4 Elevation

Only three levels, and borders do most of the work:

| Token | Use |
| --- | --- |
| `--nx-elev-0` | Flat — default for cards (border only) |
| `--nx-elev-1` | Raised — hover, dropdown, sticky header |
| `--nx-elev-2` | Overlay — modal, drawer |

No coloured or multi-layer shadows. No glow.

### 2.5 Typography

One family for UI, one for code. Sizes: 12 / 13 / 14 / 16 / 20 / 24 / 32.

| Role | Size | Weight | Colour |
| --- | --- | --- | --- |
| Page title | 24–32 | 600 | `--nx-text` |
| Page description | 14 | 400 | `--nx-text-muted` |
| Section heading | 16 | 600 | `--nx-text` |
| Card title | 14 | 600 | `--nx-text` |
| Body | 14 | 400 | `--nx-text` |
| Table header | 12 | 500, uppercase, `.04em` | `--nx-text-muted` |
| Metadata | 12–13 | 400 | `--nx-text-subtle` |
| Metric value | 24–32 | 600, tabular numerals | `--nx-text` |
| Code / LSN / IDs | 13 | 400 | mono |

Numeric metrics use `font-variant-numeric: tabular-nums` so values do not jitter.

### 2.6 Icons

`heroicon-o-*` (outline) throughout — matches the existing Filament usage.
Sizes: `14` inline with text, `16` in buttons and nav, `20` in nav group
headers, `24` in empty states. Icons never carry meaning alone; they always
accompany a label or an `aria-label`.

---

## 3. Layout

### 3.1 The product shell

```
┌───────────────────────────────────────────────────────────┐
│ topbar: brand · breadcrumb · context chip · ⌘K · avatar   │  56px
├──────────┬────────────────────────────────────────────────┤
│ sidebar  │ page header (title, description, primary CTA)  │
│ 248px    ├────────────────────────────────────────────────┤
│          │ content, max-width 1440px, gutter 24px         │
│          │                                                │
│          │                                  [ AI pill ]   │
└──────────┴────────────────────────────────────────────────┘
```

- **Sidebar** collapses to a 64px icon rail, and to a drawer under 1024px.
- **Topbar** is 56px, sticky, one hairline bottom border, no shadow.
- **Content** is left-aligned, not centred — a control plane reads better
  anchored than floating.
- The **AI pill** is fixed bottom-right, above the fold, never overlapping the
  primary CTA.

### 3.2 Page header (mandatory on every page)

```
Breadcrumb                                    [ secondary ]  [ PRIMARY ]
Page title
One-sentence description
```

- Exactly **one** primary button.
- The description is never omitted; if a page cannot be described in one
  sentence, the page is doing too much.
- Secondary actions move into an overflow menu beyond two.

### 3.3 Responsive targets

Verified at **1366×768, 1440×900, 1920×1080**.

| Breakpoint | Behaviour |
| --- | --- |
| ≥1600 | Sidebar expanded, content full width |
| 1280–1599 | Sidebar expanded, content max 1440 |
| 1024–1279 | Sidebar collapsed to rail |
| <1024 | Sidebar becomes an overlay drawer |

Tables: no horizontal scroll on the primary column set. Columns beyond the
first four collapse into an expandable row detail under 1280px.

---

## 4. Components

### 4.1 Cards

- Border, `--nx-elev-0`, `--nx-radius-lg`. **Not** a shadow.
- One idea per card. A card with no content does not render — it renders an
  empty state instead, or it does not exist.
- Optional header: title (14/600) + one secondary action; no more.

### 4.2 Metric / stat

```
LABEL            12px, uppercase, muted
942              32/600, tabular
+12% vs last week 12px, subtle, optional
```

A metric **must** carry a comparison or a state. A bare number with no context
is not a metric; it is noise. Maximum 5 per row.

### 4.3 Status language

One vocabulary, used everywhere — cards, tables, badges, the journey, AI.

| State | Icon | Label | Token |
| --- | --- | --- | --- |
| `NOT_STARTED` | `circle` | Not started | neutral |
| `IN_PROGRESS` | `arrow-path` (animated) | In progress | info |
| `READY` | `check-circle` | Ready | success |
| `NEEDS_ATTENTION` | `exclamation-triangle` | Needs attention | warning |
| `BLOCKED` | `no-symbol` | Blocked | danger |
| `COMPLETE` | `check-badge` | Complete | success |
| `UNKNOWN` | `question-mark-circle` | Unknown | neutral |

**Rules**
- Always icon + text. Never a bare coloured dot as the only signal (§34).
- `BLOCKED` must state **why** in the same view.
- `NEEDS_ATTENTION` must state **what degraded**.
- Raw internal tokens (`draft`, `paused`, `unknown`, `pending`) appear only
  under Advanced.

### 4.4 Journey stepper

A horizontal stepper for the migration journey
(Connect → Analyze → Plan → Migrate → Sync → Validate → Cutover).

- Each step: state icon, label, one-line detail.
- Current step is visually dominant; completed steps are settled, not loud.
- Clicking a step navigates to it.
- Under 1280px it becomes a vertical list.

### 4.5 Tables

- Sticky header, `--nx-surface-sunken`, uppercase 12px.
- Row height 48px desktop / 56px comfortable.
- Numeric columns right-aligned, tabular numerals.
- Row actions: one visible, rest in an overflow menu.
- Empty state replaces the body **in place** — never an empty `<tbody>`.
- Zebra striping is not used; hover highlight is.

### 4.6 Forms

- Label above input, 13px/500, `--nx-text-muted`; required marked with `*` plus
  a visually-hidden "(required)".
- Help text below the input, 12px subtle.
- Errors: red border + icon + message below, tied by `aria-describedby`; the
  message states how to fix it.
- One column by default; two only for genuinely paired fields.
- Destructive submit is never the default focus.

### 4.7 Buttons

| Variant | Use | Limit |
| --- | --- | --- |
| Primary (filled accent) | The one main action | 1 per screen |
| Secondary (outline) | Supporting actions | ≤2 visible |
| Ghost | Tertiary, row actions | unlimited |
| Danger (filled danger) | Destructive, always after confirm | — |

States: default / hover / active / focus-visible (2px ring) / disabled /
loading (spinner replaces label, width locked). Every button has a focus ring.

### 4.8 Badges

`--nx-radius-full`, 12px/500, soft background + full-strength text of the same
hue. Never the only carrier of state — always inside a row that also has a
label.

### 4.9 Drawers & modals

- **Drawer** (right, 480px) for inspecting/editing one object without losing
  context.
- **Modal** only for a decision that must block — confirmation, approval.
- Confirmation modals state: what will happen, what it affects, and whether it
  is reversible. Destructive confirmations require typing the object name.
- Focus is trapped while open; `Esc` closes; focus returns to the trigger.
- The approval modal renders the **diff** and the **affected resources**
  before the approve button.

### 4.10 Empty states

Mandatory shape:

```
[icon 24]
Title — what is missing
One sentence explaining WHY this object exists
[ Primary action ]
```

Never a blank card, never a bare "No data". Compare v0.3.0's Compatibility and
Risks cards, which rendered a title with an empty body (§32 violation).

### 4.11 Loading states

- Skeletons that match the final layout (not spinners) for content regions.
- Spinners only for in-place button actions.
- Never shift layout when data arrives — reserve the space.
- No panel region may sit blank for more than 300 ms without a skeleton.

### 4.12 Errors

Three levels, all designed:

1. **Inline field error** — see 4.6.
2. **Section error** — the card shows what failed + a Retry action; the rest of
   the page still works.
3. **Page error** — a real product 500 page with a request id, a retry, and a
   link to Advanced details. Laravel's debug page is never the user-facing
   fallback (v0.3.0 finding B3).

### 4.13 Success feedback

Toast, bottom-right, auto-dismiss 4 s, with an undo action when the operation is
reversible. Destructive or irreversible operations require acknowledgement
instead of a toast.

---

## 5. Nexus AI components

### 5.1 Scope label

Always visible at the top of the panel, bound to the route:

```
Nexus AI
Context: Project — Wasla
```

### 5.2 Tool activity

Tool calls are humanised, never raw JSON:

```
✓ Checking Live Sync…
✓ Checking failed jobs…
✓ Checking database health…
```

Raw payloads live behind a per-step **Advanced** disclosure.

### 5.3 Approval card

For any mutation, before execution:

```
Proposed action: Retry failed job #4821
Affects: Project Wasla · queue: cdc
Risk: Low — read-only retry of a failed job
[ View diff ]  [ Reject ]  [ Approve ]
```

Approve is disabled until the diff has been opened at least once.

### 5.4 Inspect Mode

- Toggle with the eye icon in the shell.
- While active, hoverable components gain a 1px accent outline and a label chip.
- Selecting a component attaches `{page, component, component_key, scope,
  workspace, project, data_source, permissions}` to the conversation.
- The attached context renders as a dismissible chip above the composer.
- Inspect Mode never mutates anything by itself.

---

## 6. Accessibility

| Requirement | Rule |
| --- | --- |
| Contrast | ≥4.5:1 body text, ≥3:1 large text and UI borders |
| Colour independence | Every state has an icon + label (see 4.3) |
| Keyboard | Every interactive element reachable; visible focus ring at all times |
| Focus order | Follows visual order; modals trap focus and restore on close |
| Labels | Every input has a real `<label>`; icons have `aria-label` or are `aria-hidden` |
| Headings | One `<h1>` per page, no skipped levels |
| Forms | Errors tied with `aria-describedby` + `aria-invalid` |
| Motion | Respect `prefers-reduced-motion`; the `IN_PROGRESS` spinner becomes static |
| Status | Live regions announce async completion |

---

## 7. Performance budget

The redesign must not be slower than v0.3.0.

| Budget | Limit |
| --- | --- |
| Dashboard queries | ≤ 12 per page render; no N+1 |
| `nexus.css` | ≤ 40 KB uncompressed, one request, cache-busted |
| AI panel | **No polling.** Tool calls are on-demand; a running job polls at ≥5 s with backoff and stops when the tab is hidden |
| Livewire payload | No full-collection hydration into a widget |
| Tables | Paginated; never render an unbounded collection |

---

## 8. Filament decoupling strategy

Filament stays as the underlying engine. The product shell is expressed through
supported extension points — **no fork**.

| Concern | Mechanism |
| --- | --- |
| Brand name, logo, colours | `Panel` config (`brandName`, `colors`, `favicon`) |
| Navigation structure | `->navigation(fn () => …)` with an explicit builder |
| Design tokens & overrides | `nexus.css` via `PanelsRenderHook::HEAD_END` |
| Shell additions (context chip, AI pill) | `TOPBAR_END` / `BODY_END` render hooks |
| Project context detection | Existing route-bound `Project` resolution |
| Page headers | A shared page-header partial used by every panel page |
| Empty states | A shared `<x-nexus.empty-state>` Blade component |

The test for success is the one in §40: **if a screen still looks like generic
Filament, redesign it.**
