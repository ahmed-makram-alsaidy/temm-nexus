# AI Test / Repair Loop

> 25J · `RepairLoopService` · State: `ai_repair_loops`

## Loop

```
Patch (approved + applied) → Test → Analyze Failure → Proposed Repair
  → Review/Approval → Apply → Re-test → …
```

Every repair proposal is a normal Copilot builder run — it lands in the patch
workspace as `proposed` and requires review + approval before the next test
round. No self-applying repairs.

## Allowlisted commands (25J.2)

The AI never supplies commands. `RepairLoopService::COMMANDS` is the only
source: `phpunit`, `flutter_analyze`, `flutter_test`, `npm_test`,
`dart_analyze`, `php_lint` (id → fixed argv). Anything else is refused with
422. Commands run with cwd = approved root and a 10-minute timeout. If the
binary is unavailable on the host, the loop records
`available: false` honestly instead of faking a pass.

## Failure structure (25J.3)

Captured per iteration: exit code, up to 25 parsed failures (PHPUnit class::
test names, summary counters, analyzer issue counts), and a truncated
(2 000-char), REDACTED output digest (secret patterns stripped via
`AiContextBuilder::redact`). Giant logs are never shipped to the AI — only
the structured subset.

## Limits (25J.1)

Defaults: `max_iterations = 3`, `max_ai_calls = 6` (operator-overridable, hard
caps 10/20). When the iteration limit hits, the loop stops with status
`limit_reached` — never an infinite repair loop.

## Migration rehearsal (25J.4)

`run_rehearsal` (validator mode, approval-gated) goes through the Phase 24
`MigrationRunManager` exclusively — dry-run/rehearsal on disposable targets,
all Phase 24 guardrails active. No ad-hoc DB scripts against the source,
ever.
