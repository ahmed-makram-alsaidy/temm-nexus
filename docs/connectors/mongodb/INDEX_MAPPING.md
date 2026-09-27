# Index mapping (28G)

The connector reads every collection's indexes with `listIndexes`
(read-only; a namespace that refuses listing yields an honest empty list,
never a guess) and inventories them with their types, so nothing is
silently approximated (28G.1).

## What is inventoried

Per index: sanitized `name` (`safeMetadataName`), `columns` (key order
preserved — compound indexes stay compound), `unique`, `types[]` and
`expire_after_seconds`. Database-wide, `mongodb.index_types` aggregates the
classification counts.

## MongoDB → PostgreSQL candidates

| MongoDB index | Inventory | PostgreSQL candidate |
|---------------|-----------|----------------------|
| unique | `unique: true` | UNIQUE index/constraint candidate |
| compound (`{a: 1, b: -1}`) | columns in key order | composite index candidate |
| TTL (`expireAfterSeconds`) | type `ttl` + seconds recorded | flagged: **may require scheduler/job — not a direct index equivalent** (PostgreSQL has no TTL index; deletion must be scheduled, e.g. pg_cron) |
| text | type `text` | full-text search (tsvector/GIN) candidate |
| 2dsphere / 2d / geoHaystack | type `geospatial` | PostGIS candidate — only if the extension is enabled/reviewed by the operator |
| hashed | type `hashed` | recorded; no silent equivalence claimed |
| sparse | type `sparse` | recorded (PostgreSQL partial-index candidate for review) |

The flags are evidence for the planning layer and the operator — the
connector itself never rewrites an index into something semantically
different without saying so.

## Reading mechanics

`listIndexes` runs per collection against the source database; the cursor
is drained with the same `getMore` batching as extraction. Each key
document's field order is the index's column order (BSON documents are
ordered), so a compound `{user_id: 1, created_at: -1}` is inventoried as
`columns: ["user_id", "created_at"]`. Direction is preserved in the
per-field type map, and special spec values (`"2dsphere"`, `"text"`,
`"hashed"`) drive the classification — an index whose spec field holds a
sub-document is recorded as `compound-doc` rather than guessed. A
namespace that refuses index listing (e.g. restricted views) yields an
honest empty list with no error propagation into the run.

## Example (synthetic source)

| Index | Result |
|-------|--------|
| `users.email_unique` (unique) | inventoried `unique: true` → UNIQUE candidate |
| `orders.created_ttl` (`expireAfterSeconds: 3600`) | `types: ["ttl"]`, seconds recorded → scheduler flag |
| `events.expires_ttl` (`expireAfterSeconds: 0`) | `types: ["ttl"]` |
| `products` `$jsonSchema` validator | validator-backed flag on the table (SCHEMA_INFERENCE.md) |

`MongodbConnectorTest::test_index_and_validator_analysis` asserts the
unique index shape and the database-wide `ttl` classification.

## Relationship to the plan

Index metadata travels on the table item (`indexes[]`) and in
`mongodb.index_types`, so the plan can propose PostgreSQL equivalents and
the operator approves them. TTL and geospatial entries carry their
"requires review / extension" nature with them instead of becoming
look-alike btree indexes by default.
