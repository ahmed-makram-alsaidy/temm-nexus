# AI Patch Workspace

> 25I · `PatchWorkspace` · Storage: `storage/app/private/ai-workspaces/<project>/<run-uuid>/`

## Isolation

Every AI run that proposes code gets its own private workspace directory
(never public storage, gitignored tree). `create` patches stage their full
content there; `modify` patches carry a unified diff against the approved
target root. The workspace is the ONLY place AI output exists until approval.

## Patch format (25I.1)

Per file: `path, action (create|modify), reason, risk (low|medium|high),
diff (modify) or content (create), tests`. Prefer unified diffs for
modifications — deterministic and reviewable.

## Path guard (25I.2)

`validatedRelativePath` rejects: `..` traversal, absolute paths
(`/...`, `C:/...`), embedded system-path segments. `safePathForRoot` then
enforces realpath containment under the approved root at stage AND apply
time. All patch rows are created only AFTER every path validates — refused
patches leave zero residue (asserted by test).

## Deterministic diff application

`applyUnifiedDiff` implements standard `diff -u` hunks (context/-/+, applied
bottom-up, context verified before splice). A diff whose context does not
match returns null → the file is marked failed and the ORIGINAL content is
preserved. No fuzzy matching, no silent corruption.
