# OpenCode integration — official interface verification (Phase 43, Gate B)

This document records the verified, official OpenCode interface TEMM Nexus
integrates with, the exact version it was verified against, and the reason the
integration mode was selected. It was produced by inspecting the official
OpenCode documentation AND the actual binary's own OpenAPI schema — not from
assumptions or chat snippets.

## Verified version

| Item | Value |
|---|---|
| OpenCode version verified | **1.18.34** (installed binary + `GET /global/health` response) |
| Docs source | https://opencode.ai/docs/server/ (fetched 2026-10-04) |
| Machine-readable contract | `GET /doc` — OpenAPI 3.1 schema served by the server itself (478 KB, 162 paths, inspected in full) |
| Pinning | Managed deployment pins the Docker image tag; the adapter checks `GET /global/health` and refuses to run against major versions it was not built for (see `OpenCodeClient::assertCompatibleVersion()`, MAJOR must be 1 and MINOR >= 18). |

## Headless / server operation

```
opencode serve [--port N] [--hostname H]
```

- Default bind: **`127.0.0.1`**, default port **`4096`**. The server is
  loopback-only by default; the managed deployment runs it on an internal
  Docker network and never publishes a port.
- Authentication (official): `OPENCODE_SERVER_PASSWORD` enables HTTP Basic
  auth; username defaults to `opencode` (overridable via
  `OPENCODE_SERVER_USERNAME`). TEMM always sets a password for managed and
  external runtimes.
- `opencode attach <url>` is the official CLI attach surface (operator docs
  only; the managed service is never exposed publicly for it).

## API surface actually used by the TEMM adapter

All endpoints below were verified against the live 1.18.34 server and its
`/doc` schema. Nearly every route accepts a `?directory=` query parameter
which selects the project instance the call applies to — this is the official
multi-directory mechanism and is the cornerstone of TEMM's per-task workspace
isolation.

| Purpose | Call | Notes |
|---|---|---|
| Health / version | `GET /global/health` | `{healthy: true, version: "1.18.34"}` |
| Model/provider discovery | `GET /config/providers?directory=X` | Full provider list incl. model ids, capabilities, context limits, cost metadata as reported by OpenCode. TEMM renders ids verbatim; no price/"free" claims are inferred. |
| Create session | `POST /session?directory=X` | Body may pin `{title, agent, model: {providerID, id}, permission: {...}}`. Response carries the bound `directory`. |
| Submit task prompt (async) | `POST /session/{id}/prompt_async` | Returns `204`; progress arrives over SSE. |
| Event stream | `GET /event?directory=X` | SSE: `data: {json}` frames; first frame `server.connected`, then `message.part.updated`, `permission.updated`, `session.status`, `worktree.*`, … |
| Permission reply | `POST /permission/{requestID}/reply` | Body `{reply: "once"\|"always"\|"reject"}`. |
| Session diff | `GET /session/{id}/diff` | Official `FileDiff[]`: `{path, status: added\|modified\|deleted, additions, deletions, patch}`. |
| Abort / cancel | `POST /session/{id}/abort` | Official cancellation surface. |
| Session status | `GET /session/status?directory=X` | `{type: idle\|retry\|busy\|…}` per session. |

Per-session permission config (`PermissionConfig` in the schema): `read`,
`edit`, `glob`, `grep`, `list`, `bash`, `task`, `external_directory`, `question`
— each `allow`/`ask`/`deny`, with pattern maps for bash rules. TEMM sets
`edit: allow`, `bash: allow` (sandboxed by the workspace boundary and policy
limits), `external_directory: deny` on every session it creates, so the runtime
cannot reach outside the assigned workspace even when the host could.

## Why this integration mode was selected

1. **Public, supported, machine-readable contract.** The server ships an
   OpenAPI 3.1 schema at `/doc` that the official SDKs are generated from.
   TEMM consumes the same HTTP surface instead of reverse-engineering a
   private protocol or wrapping the TUI.
2. **First-class headless mode.** `opencode serve` is the documented headless
   operation with official basic-auth support, matching TEMM's managed-service
   and external-endpoint deployment modes.
3. **Official per-directory instances.** `?directory=` is supported on the
   endpoints TEMM uses, so ONE managed server can safely serve many isolated
   task workspaces, and the session response echoes back the exact directory —
   which TEMM validates (rejecting the task if the runtime reports a different
   workspace than the one provisioned).
4. **Official permission primitives.** Session-scoped `permission` config plus
   the `permission.*` reply endpoints let TEMM layer its execution policy on
   OpenCode's own mechanism rather than a brittle command allowlist.
5. **Official diff and abort surfaces.** `GET /session/{id}/diff` and
   `POST /session/{id}/abort` provide deterministic changesets and cancellation
   without filesystem sniffing.
6. **ACP exists** (`opencode acp`) but is a different integration surface
   (Agent Client Protocol over stdio for editors); the HTTP server API is the
   better fit for a PHP control plane and is the same surface the official
   web UI uses.

## What TEMM does NOT rely on

- No undocumented endpoints; no TUI control routes (`/tui/*`); no PTY routes;
  no experimental console/sync/worktree endpoints (TEMM provisions git
  worktrees itself so isolation works identically for external runtimes).
- No pricing/"free" inference: model cost fields are displayed only when
  upstream reports them, verbatim.
- No assumption that the runtime has valid provider credentials — missing
  credentials surface as `MODEL_UNAVAILABLE` / `RUNTIME_AUTH_FAILED`
  structured errors from the adapter.

## Version compatibility policy

- Managed: image tag pinned per release; compose files carry the pin.
- External: `GET /global/health` version is parsed; MAJOR must equal 1 and
  MINOR must be >= 18 (the release that stabilised the surface above). A
  mismatch fails the connection test with a classified error, never a silent
  degradation.
