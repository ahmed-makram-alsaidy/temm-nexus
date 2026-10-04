# Developer Agent — operator documentation (Phase 43)

The Developer Agent is TEMM Nexus's second AI capability: an actual
coding-agent runtime that can inspect an isolated copy of a project, reason
about code, edit files, run commands inside that sandbox, and hand you a
reviewable diff. Nothing it produces ever reaches your authoritative source
until an authorized human explicitly approves that exact changeset.

Nexus AI and the Developer Agent are distinct systems. Nexus AI is the
platform assistant (diagnosis, infrastructure context, safe action plans).
The Developer Agent writes code. Nexus AI does not silently launch agent
tasks; the workbench (or the CLI) is where tasks start.

## The safety lifecycle (always, no exceptions)

```
PLAN → WORK IN ISOLATED WORKSPACE → DIFF → HUMAN APPROVAL → APPLY → VERIFY
```

1. **Task created** — a task is created for a project. The platform
   immediately cuts a disposable `git worktree` from the project's current
   revision (`storage/app/private/agent-workspaces/<project>/<AGT-XXXX>…`).
   The agent NEVER works inside the authoritative checkout.
2. **Run** — the runtime (OpenCode) is pointed at that worktree only. Every
   session is created with an explicit permission ruleset that DENIES
   external-directory access.
3. **Diff** — when the session goes idle, TEMM computes the deterministic
   changeset from the worktree with plain `git diff --binary HEAD` and
   stores it with a SHA-256 fingerprint. The runtime's own claims are
   cross-checked for path safety only.
4. **Approval** — an authorized user approves THE FINGERPRINT. The approval
   binds task + changeset content + base revision + approver. It is
   single-use.
5. **Apply** — before applying, TEMM revalidates everything: the approval is
   unspent, the recomputed fingerprint matches (TAMPERED otherwise), and the
   authoritative repo is still at the base revision (STALE otherwise).
   Application is repository-native (`git apply` after a `--check` dry run
   of the whole patch), never a recursive copy. A pre-apply stash snapshot
   hash is recorded in the audit trail for recovery.
6. **Verify** — the project's verification policy (operator-defined
   commands; never agent-proposed, never deploy) runs against the applied
   source and the honest result (command/exit/duration) is recorded.
   Verification can be `skipped` when a project defines no commands — that
   is reported as skipped, not "passed".

## Nexus AI vs Developer Agent

| | Nexus AI | Developer Agent |
|---|---|---|
| Purpose | platform assistance, diagnosis, safe action plans | writes/changes code in a sandbox |
| Mutates source | never | only after fingerprint-bound approval |
| Runs where | inside the console conversation | isolated git worktree per task |

## Deployment modes

- **Managed** — TEMM runs OpenCode as a service in its own Docker stack
  (`opencode` service in docker-compose.yml / docker-compose.prod.yml). The
  service is internal-only: no published port, never routed through Caddy.
  It mounts exactly one path — the isolated task-workspace root — at the
  same absolute path the app uses. Authentication uses the shared
  `OPENCODE_SERVER_PASSWORD` (required in `.env`; generate e.g. with
  `openssl rand -base64 24`). Provider credentials for the agent are
  passed through as environment variables (`ANTHROPIC_API_KEY`,
  `OPENROUTER_API_KEY`, `OPENAI_API_KEY`, `GOOGLE_GENERATIVE_AI_API_KEY`).
- **External** — the platform owner registers an independently operated
  OpenCode server (Settings → Developer Agents → mode "External"). The
  endpoint must be HTTPS and passes the platform's SSRF guard (private and
  metadata ranges refused; `AGENT_ALLOW_LOOPBACK_ENDPOINTS=true` is a
  local-development-only escape hatch for the shipped mock server).
  Credentials are stored with the platform's encrypted-cast posture and are
  write-only in the UI.

OpenCode version support: the adapter verifies `GET /global/health` and
accepts MAJOR 1, MINOR ≥ 18 (the verified 1.18.x surface — see
`docs/agent-runtime/OPENCODE_INTEGRATION.md`). Anything else fails the
connection test with a classified error. The managed image pins
`OPENCODE_VERSION` (default 1.18.34).

## Using it

### Web (Settings → Developer Agents / Developer Agent)

- **Settings → Developer Agents** (`/admin/developer-agent-settings`):
  add/edit runtimes, managed/external mode, endpoint, write-only auth
  secret, default model, task time budget, concurrency, workspace
  retention; "Test connection" runs a real health check; "Discover models"
  lists the models the runtime reports (ids verbatim; no pricing claims are
  invented by TEMM). The page also shows the v1 execution policy:
  read/write/execute inside the assigned workspace only; apply requires
  human approval; deploy is not part of this feature.
- **Developer Agent** (`/admin/developer-agent`): pick project, runtime and
  model (or leave the runtime default), write the task, start it. The task
  page polls live: status, activity stream, commands (metadata only —
  output content is never stored), files changed, the diff with its
  fingerprint, approval actions, verification results.

### CLI

The CLI drives the exact same service layer and database state as the web
UI. Commands (each acting under a named user's authorization where they
mutate anything):

```
php artisan agent:status                     # connection health for all runtimes
php artisan agent:runtimes                   # configured runtimes
php artisan agent:models [--filter=SUBSTR]   # models reported by a runtime
php artisan agent:run --project=SLUG --user=EMAIL [--runtime=NAME] [--model=provider/model] [--wait] "task text"
php artisan agent:show CODE [--events=N]     # task state + activity
php artisan agent:diff CODE [--raw]          # the deterministic changeset
php artisan agent:approve CODE --user=EMAIL [--note=...]
php artisan agent:apply CODE --user=EMAIL    # apply + verify
php artisan agent:cancel CODE --user=EMAIL
php artisan agent:verify CODE                # re-run verification policy
```

A complete round trip:

```
php artisan agent:run --project=demo --user=owner@corp.test \
  --model=google/gemini-2.5-pro "Fix the failing test in tests/ExampleTest.php"
php artisan agent:show AGT-0001
php artisan agent:diff AGT-0001
php artisan agent:approve AGT-0001 --user=owner@corp.test --note="reviewed"
php artisan agent:apply AGT-0001 --user=owner@corp.test
```

Tasks execute on the dedicated `agents` queue (Horizon supervisor
`supervisor-agents`, long timeout). In development run
`php artisan queue:work --queue=agents` or Horizon.

### Direct OpenCode access for operators

`opencode attach <url>` and the TUI can connect to an OpenCode server the
same way TEMM does. The managed service stays internal; for local
development you may run `opencode serve` bound to loopback with
`OPENCODE_SERVER_PASSWORD` set. TEMM never disables authentication on a
managed runtime and never exposes it publicly.

## Permissions

Capability vocabulary (checked server-side at execution time, everywhere):

| capability | who (typical) | meaning |
|---|---|---|
| `agents.view` | viewers and up | see tasks |
| `agents.run` | workspace admins, project admins/developers | create/cancel-eligible tasks |
| `agents.cancel` | workspace admins, project admins | cancel tasks |
| `agents.approve` | workspace admins, project admins | approve/reject changesets |
| `agents.apply` | workspace admins, project admins | apply approved changesets |
| `agents.audit` | workspace admins+ | inspect the agent audit trail |
| `agents.configure` | platform only (owner/admin) | manage runtime configuration |

A project-level user can NEVER configure a platform runtime: the settings
page and every configuration mutation require `agents.configure` at
platform scope.

## Audit

The admin audit ledger records: runtime created/updated/tested, task
created/cancelled/failed, changeset generated, approval granted/rejected,
apply failed, changeset applied (with file list and recovery snapshot),
per-file apply, verification recorded. Command execution and file activity
during a task live in the task's bounded event/command ledger
(`agent_task_events`, `agent_task_commands`) — command OUTPUT CONTENT is
never stored, only byte size and truncation state. Secrets never appear in
any ledger, notification, or log line.

## Session filesystem isolation (Landlock)

Every shell the runtime spawns for a session runs through `ll-sh`, a
Landlock (LSM) wrapper baked into the managed image. It fail-closes unless
the working directory is beneath TEMM's agent-workspaces root, scrubs the
inherited environment (the runtime auth password and provider
configuration are invisible to sessions), and grants filesystem access
ONLY to the session workspace (rw), the worktree's own gitdir (rw) plus
the repository common store (read-only — `git status`/`git diff` work but
a session cannot commit into the authoritative repository), system paths
(rx), /proc (r), /dev and /tmp (rw). Sibling workspaces, app_storage,
HOME, and everything else are denied by default; cross-directory
rename/link is not granted; symlink escapes are blocked by real-path
evaluation. Tool-level access outside the workspace is separately denied
by the session permission ruleset (`external_directory: deny` — the
runtime auto-rejects such tool calls). Host filesystem, Docker socket and
the platform database remain unreachable at the container and network
level. Kernels without Landlock ABI >= 3 (kernel < 6.2) get no session
shell at all — the wrapper fails closed.

## Workspaces & retention

Every task gets a unique worktree; paths are guarded against traversal,
absolute escapes, `.git` access, and symlink escapes. Workspaces are
retained per-runtime (`workspace_retention_days`, platform default
`AGENT_WORKSPACE_RETENTION_DAYS=7`) and cleaned by the retention sweep;
`0` keeps them until manually cleaned. Workspaces live under
`storage/app/private` (volume-backed — they survive container recreation,
like every operational artifact).

## Logs & observability

Task telemetry lives in the database (survives restarts). Runtime wire
diagnostics never include Authorization material. The Horizon
`supervisor-agents` worker reports failures; failed tasks carry a
structured category (`RUNTIME_UNAVAILABLE`, `RUNTIME_AUTH_FAILED`,
`MODEL_UNAVAILABLE`, `SESSION_FAILED`, `WORKSPACE_FAILED`,
`COMMAND_FAILED`, `TASK_CANCELLED`, `INVALID_RUNTIME_RESPONSE`,
`TIMEOUT`) and a safe, classified message — never a raw wire dump.

## Troubleshooting

| symptom | meaning | fix |
|---|---|---|
| `RUNTIME_UNAVAILABLE` | server unreachable / unhealthy | check the service (`docker compose ps opencode`), endpoint, and version gate |
| `RUNTIME_AUTH_FAILED` | credentials rejected | re-enter the password in Settings → Developer Agents (write-only field) |
| `MODEL_UNAVAILABLE` / provider 402 | model or provider account issue | pick another model from `agent:models`; check provider credentials/balance |
| task stuck "Running" | session stream quiet | the runner reconnects and enforces the task time budget; it will fail with `TIMEOUT` rather than hang forever |
| "Source revision moved… (stale refused)" | source advanced since the task started | re-run the task; approvals never apply over moved source |
| "Changeset no longer matches… (tamper refused)" | stored changeset changed after approval | re-review and re-approve; the mismatch is the protection working |
| "exceeds the size limit" | diff too large to auto-apply | review manually; split the task |

## Upgrades

The Phase 43 schema is additive (eight `agent_*` tables; no existing table
is modified). `php artisan migrate` upgrades existing installations; fresh
installs get everything from the normal flow. Rollback drops only the
tables the migration created. Existing Nexus AI provider rows, projects and
workspaces are untouched.

## Non-goals (v1)

No deployment, no production shell, no Docker daemon access, no autonomous
merge, no multi-agent orchestration. High-risk infrastructure operations
remain under TEMM's existing safe-action architecture.
