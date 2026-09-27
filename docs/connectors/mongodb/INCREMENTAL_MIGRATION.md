# Incremental migration — what is supported and what is deferred

## Capability matrix today

| Capability | Declared | Mechanism | Status |
|------------|----------|-----------|--------|
| `resume` | yes | `_id`-ordered range scans; token = last streamed `_id` | implemented + tested |
| `incremental_export` | **no** | change streams (`$changeStream`) | not implemented; `capabilityStatus` → `NOT_SUPPORTED` |

The distinction matters: resume restarts a bulk transfer where it stopped;
incremental export captures ongoing changes. Phase 28 ships the former and
refuses to fake the latter.

## Change streams are NOT implemented in Phase 28

`INCREMENTAL_EXPORT` is **deliberately undeclared** in `connector.json`, and
`capabilityStatus('incremental_export', …)` returns `NOT_SUPPORTED`
(asserted in `MongodbConnectorTest::test_mongodb_registers_through_the_connector_sdk`).
There is no `$changeStream` code path: the wire client's `aggregate` is
allowlisted, but the connector only ever builds `$collStats` stages — it
never constructs a change-stream pipeline, and no resume-token persistence
for oplog positions exists. Declaring it would be dishonest status (27C.1).

## What "resume" means here (and is declared)

The `resume` capability IS declared and is real — it is `_id`-ordered range
scans, not change streams:

- extraction sorts every pass by `_id` ascending (BSON total order, so this
  works for ANY `_id` type);
- a completed or interrupted pass leaves the last streamed `_id` as the
  resume anchor;
- `streamFind()` accepts `resumeAfter` and the token is validated as a
  scalar, comparable shape before use (OBJECTID.md);
- re-running extraction resumes from the anchor instead of re-reading
  history.

This gives deterministic, restartable bulk transfer — the baseline
mechanism — without claiming live delta capture.

## Future cutover pattern (DESIGN ONLY — no code)

For minimal-downtime cutovers the intended Phase-28+ pattern is:

1. **Baseline** — full `_id`-ordered extraction + migration (what ships
   today).
2. **Change stream** — open a `$changeStream` cursor (full-document
   lookup) on the source, recording a cluster-time resume token; the
   incremental_export capability would be declared only once token
   persistence is real.
3. **Freeze** — stop application writes to the source.
4. **Delta** — drain the change stream, applying inserts/updates/deletes by
   document key until the tail is drained.
5. **Cutover** — point the application at the new store.

This section documents intent so the capability model's gap is explicit;
nothing in steps 2-5 exists in the codebase, and the capability matrix will
continue to report `incremental_export: not_supported` until that changes.

## Practical guidance today

For sources that change during migration: run the baseline, re-run
extraction for the resume range, and use the validation artifacts (counts,
id coverage, fingerprints — 28Q) to size the remaining gap. The 28V.3
immutability check makes it obvious whether the source moved mid-run
(source fingerprint before vs after).
