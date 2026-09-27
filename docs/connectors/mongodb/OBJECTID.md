# ObjectId and _id handling (28M/28H)

## ObjectId preservation by default

ObjectId values are preserved as their canonical **24-character lowercase
hex text** (`64b1f0c0a1b2c3d4e5f60718`). The codec decodes the 12 wire
bytes with `bin2hex` and `TypeMapper` maps `objectId → text`, so `_id`
round-trips exactly and cross-collection references stored as ObjectIds
keep pointing at the same ids after migration. Every table's primary key is
`_id`; derived child tables link through `parent_id` of the same type.

## _id-ordered extraction — the determinism backbone (28H.1)

Extraction always sorts by `_id` ascending (`find` with `sort: {_id: 1}`),
and the scripted-server comparator mirrors BSON's total type-order
comparison. Because `_id` values are unique per collection, this gives:

- **Deterministic runs** — identical sources yield identical row order,
  fingerprints and byte-stable JSONB payloads;
- **Safe resumption for ANY _id type** — not just ObjectId: string, int32,
  int64, double, date `_id`s all compare in BSON's canonical order, so a
  "last seen `_id`" anchor is always a valid range boundary.

## Resume tokens

The resume capability (declared in the manifest) is anchored on the last
streamed `_id`. `streamFind()` accepts a `resumeAfter` option that becomes
the range anchor for the next pass. Tokens are validated before use
(28T): only scalar, comparable shapes are accepted —

| Token | Verdict |
|-------|---------|
| objectId with 24-hex value | valid |
| string / int / double / date scalar | valid |
| array or document (e.g. injected `{$gt: ""}`) | rejected as tampered |
| objectId with malformed value (`zz-not-hex!!`) | rejected |

`MongodbSecurityTest::test_resume_tokens_reject_tampered_shapes` pins this
contract: non-scalar shapes are not comparable resume anchors and are
treated as tampered input, never passed to the server.

## Fingerprints

- Source-level: `SchemaFingerprint::compute(inventory())` — stable across
  calls, changes when the source's structure changes; the 28V.3
  immutability check re-computes it after a migration run.
- Document-level: `MongodbSourceAdapter::documentFingerprint()` — SHA-256
  of `TypeMapper::canonicalJson()`, used by validation artifacts (28Q.1).

## Id coverage validation (28Q)

The connector-provided validation artifacts include `mongodb_id_coverage`:
after a run, every source `_id` is checked against the target (the
migration test streams the source ids and asserts each has exactly one
target row). Because ids are preserved verbatim (24-hex text) and
extraction is `_id`-ordered, a coverage gap is always a real transfer gap —
never an id-representation mismatch — which makes the validator decisive
instead of noisy.

## Honest limits

The connector preserves ObjectIds; it does not reinterpret them as UUIDs.
BSON binary UUIDs (subtype 4) are carried as `bin64:` text (TYPE_MAPPING.md)
— a deliberate, visible mapping rather than a silent conversion.
