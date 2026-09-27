# AI Approval Model

> 25H.3 / 25I.3 · `PatchWorkspace::approve|reject|apply` + Copilot page actions

## The gate

```
PLAN → PATCH → REVIEW → APPROVE → APPLY
```

- AI output is always a RECOMMENDATION (classification, mapping, patch).
- Patches are staged as `proposed` rows in the private workspace — never
  written to any working tree at generation time.
- **Review UI** shows per-file: path, action, reason, risk badge, generated
  tests, and the unified diff (create files show staged content in the
  workspace). Actions: Approve / Reject / (request revision by rejecting with
  a reason and re-running the builder).
- **Apply is a separate, explicit operator action** (`repositories.manage`
  permission, confirmation modal) — approval alone never writes code.
- Partial failure is honest: `applied | applied_partial | failed` with
  per-file status preserved for the failed hunks.

## What apply guarantees

- Only files whose status is `approved` are written.
- Path containment re-checked at apply time (realpath under the approved root).
- `create` refuses to overwrite an existing file with different content.
- `modify` applies the unified diff deterministically; a non-applying diff
  fails that file without touching the original.
- Unrelated files are never deleted or modified; no `git reset --hard`, no
  `git clean`. Git status is recorded before/after where a repo exists.

## Audit trail

`PATCH_GENERATED` → `PATCH_APPROVED` → `PATCH_APPLIED` (or `PATCH_REJECTED`)
with counts and reasons — never with file contents or secrets.
