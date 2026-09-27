# AI Migration Copilot

> 25G · `MigrationCopilot` · Page: Project → AI Copilot · History: `copilot_runs`

## Modes

| Mode | Can | Cannot |
|---|---|---|
| ADVISOR | explain analysis, identify blockers, propose order, RLS→Laravel candidate mapping, RPC/Edge classification, client mapping | modify anything |
| BUILDER | generate code patches (Laravel migrations/models/services/controllers/routes/policies/tests, mapping manifests, client data-source swaps) into the isolated workspace | touch the original project directly |
| VALIDATOR | run allowlisted tests, run migration rehearsal via the Phase 24 engine, inspect structured failures, recommend repairs | mutate any source |

AUTONOMOUS mode is intentionally NOT enabled in this phase.

## Inputs — structured artifacts only (25G)

The Copilot never receives raw system access. It consumes: Migration Analysis
(+items), Compatibility/Risk classifications, RLS inventory, RPC inventory,
Edge Function inventory, Auth/Storage inventories, the client callsite
manifest, operator-selected source files, and validation/test failures.

## Actions (25K.2 action cards)

`explain_blockers`, `propose_order`, `analyze_rls`, `classify_rpc`,
`classify_edge`, `map_client_calls`, `generate_patch`, `fix_failures`,
`validate_patch`. Every run is operator-initiated, audited (`COPILOT_RUN`),
and stored with mode/provider/model/input-refs/structured-result/tool-ledger.

## Classification vocabularies

- Functions/RPC: `KEEP_POSTGRESQL, LARAVEL_SERVICE, LARAVEL_API,
  SERVER_FUNCTION, QUEUE_JOB, SCHEDULER, WEBHOOK, EXTERNAL_INTEGRATION,
  NEEDS_REVIEW` — AI output is a RECOMMENDATION until accepted.
- RLS (25G.6): candidate Laravel Policy/Gate mapping + ALLOW/DENY test cases +
  risk notes — security conversions are never auto-applied.
- Edge Functions (25G.7): internal_logic / integration / webhook /
  scheduled_job / notification / obsolete + target recommendation. Source
  code is often unavailable — the classification then relies on the imported
  manifest and says so.

## Client conversion planning (25G.8)

The Copilot maps each callsite to the platform SDK/API catalog (Phase 22 SDK
surface + project Connect config). Endpoints that do not exist are flagged
`gap: true` — never silently invented. A deterministic helper
(`MigrationCopilot::mapCallsiteToPlatform`) enforces the same rule outside
the AI.

## Structured output

Prompts demand strict JSON matching an action-specific schema; parsing is
defensive (tolerates fenced/prose-wrapped JSON, bounds oversized output to a
truncated `raw` payload).
