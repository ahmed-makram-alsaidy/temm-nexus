# AI Tool Policy

> 25H · `CopilotToolRegistry`

## Tool surface

The LLM receives NO shell and NO unrestricted filesystem access. Every
capability is an allowlisted, mode-scoped, budgeted registry tool:

| Tool | Read-only | Approval | Modes |
|---|---|---|---|
| read_analysis / read_schema / read_policy / read_function | yes | — | advisor, builder |
| read_client_file | yes (safe-path guarded, redacted) | — | advisor, builder, validator |
| search_client_calls / read_api_catalog | yes | — | advisor, builder |
| generate_patch | no (stages into workspace) | — | builder |
| apply_patch_to_sandbox | no | **required** | builder |
| run_allowed_tests | no | **required** | validator |
| run_rehearsal | no | **required** | validator |
| read_validation_result | yes | — | validator |

Dispatch enforces, in order: forbidden-name rejection (structural), registry
membership, mode permission, explicit `approved: true` for gated tools, and a
per-run tool-call budget (50) against replay/bombing. Every dispatch appends
a ledger entry (tool name + args HASH — raw args are never ledgered).

## Forbidden tools (25H.2)

`shell, exec, bash, command, delete_file, rm, write_file_outside_patch,
install_package, deploy_production, mutate_dns, write_production_db,
supabase_write, reveal_secret, browse_filesystem, read_env_secrets, git_push,
git_reset_hard, git_clean` — rejected regardless of who asks, including when
project content pretends to authorize them (the registry is code, not text).

## Approval gates (25H.3)

Explicit operator approval is required for: apply patch, run tests, run
migration rehearsal, (and in the platform generally: create DB migration,
modify the original repository, external network tests, environment
promotion). The AI may PROPOSE all of these; it can never silently execute
them.
