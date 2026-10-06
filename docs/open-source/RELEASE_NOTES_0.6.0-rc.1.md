# TEMM Nexus v0.6.0-rc.1 — The Product Experience Release

**Release date:** 2026-10-07
**Status:** release candidate (PRE-RELEASE — not stable)
**Upgrade-from:** v0.5.0 installs upgrade in place; the release adds two fully
additive migrations and performs no destructive schema change.

## What's new

0.6.0 redesigns how the platform feels to operate. Where earlier releases
accumulated capability, 0.6.0 turns it into one coherent product: light-first
surfaces, a navigation model built around Projects, a guided migration
journey, an assistant you talk to, and a coding agent you supervise.

- **Light-first product design** — the entire operator surface moves to the
  light-first 0.6 design system: one canvas, one typography scale, one accent
  and radius language across login, setup, console and every project surface.
  Dark mode remains a first-class preference; light stays the default.
- **Simplified navigation** — the console shell is now
  Home · Projects · Clients · Activity · Settings, with Nexus AI and the
  Developer Agent grouped under AI. Project context lives in tabs
  (Overview, Migration, Data, Access, Build, Operate, Settings), not in a
  sidebar that grows with every feature.
- **Home as a command center** — one calm page that answers "what needs me
  today": a derived attention list instead of raw telemetry walls, with a
  Continue action that always agrees with the underlying project state.
- **Projects as the product** — a redesigned Projects index and a canonical
  Project Overview: migration progress, journey stages (Connect → Analyze →
  Plan → Migrate → Live Sync → Validate → Cutover), source connection and
  readiness facts derived from real telemetry, never invented.
- **Guided New Project flow** — a five-step wizard (Project, Source,
  Destination, Analyze, Review) with server-side drafts that survive refresh
  and closing the browser, inline workspace creation, a source connection
  test before anything is stored, and analysis warnings classified in plain
  language. A source with zero tables can never report Ready.
- **Unified Migration journey** — one stage model shared by the wizard, the
  project overview and the migration center, with honest per-stage states,
  bounded run history and a safe dry-run-first transfer flow.
- **Nexus AI chat-first** — the assistant is now a durable conversation:
  persistent multi-turn history per platform/workspace/project scope,
  humanized tool activity instead of raw dumps, grounded answers with visible
  context, and provider failures presented as classified, actionable product
  errors. Safe actions still preview first and apply only after explicit
  approval.
- **Developer Agent workbench, status-first** — the coding-agent surface
  leads with human status (Queued → Working → Ready for review → Completed),
  a humanized timeline, and a decision-oriented diff view. Transport and
  runtime plumbing moved behind an Advanced disclosure in the settings.
- **Managed OpenCode runtime** — OpenCode remains the first supported
  runtime (1.18.34 pinned; the adapter refuses incompatible servers). A
  managed runtime runs inside the platform's own stack with no operator
  endpoint configuration; external HTTPS runtimes remain fully supported.
- **Approval safety** — unchanged and re-verified: every task runs in a fresh
  disposable git worktree, produces a deterministic fingerprint-bound
  changeset, and never touches authoritative source until a human approves
  the exact diff. Approvals are single-use, validated against fingerprint and
  base revision, and followed by real verification checks (with only each
  check's result and duration retained).
- **English + Arabic** — complete RTL support across the redesigned surfaces,
  natural Arabic copy, isolated left-to-right rendering for technical values,
  and localized navigation and page titles everywhere.
- **Consistent statuses, errors and empty states** — one status dictionary
  across pages, raw enum values and internal vocabulary removed from default
  surfaces, and every empty state explains what belongs here and what to do
  next. Raw diagnostics live only behind explicit Technical details
  disclosures.
- **Performance** — bounded, indexed queries on the operator surfaces; the
  control-plane studios and record browser stay responsive with real-world
  data volumes.

## Upgrading from v0.5.0

1. Back up (the platform's `./scripts/upgrade.sh` does this as step one).
2. Replace the application source with this release.
3. Run the normal migrations — they are additive and preserve all existing
   workspaces, projects, repositories, activity and configuration.
4. Rebuild the app image and restart the stack.

Existing AI provider configurations, agent runtimes, projects and audit
history remain valid. No re-install or data repair is required.

## Verification

- The blocking release suite (hermetic, SQLite, no network) reports
  0 failures / 0 errors.
- A real upgrade from v0.5.0 with seeded projects was performed against this
  exact code: both migrations applied cleanly and all data remained
  accessible.
- A fresh install from the packaged artifact was performed: setup, first
  owner creation, login and the Home surface all verified.
- The managed OpenCode lifecycle was exercised end to end: runtime connect
  with version detection, real model discovery, a real coding task in an
  isolated worktree, human diff review, single-use approval, apply and
  post-apply verification — with authoritative source untouched before
  approval.

## Notes

- This is a **release candidate**: it is feature-complete for 0.6.0 and
  published for operator validation. It is not stable 0.6.0.
- OpenCode 1.18.34 is the pinned, supported runtime version.
- No other runtimes (Codex CLI, Claude Code, ACP) are included in this
  release; their adapters land when their integrations mature.
