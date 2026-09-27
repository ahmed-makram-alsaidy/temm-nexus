# Relationship inference (28F)

MongoDB relationships are implicit. `RelationshipInferer.php` produces
CANDIDATES with honest confidence tiers; low-confidence inference never
auto-creates foreign keys.

## The three confidence tiers

| Confidence | Trigger | Example |
|------------|---------|---------|
| `EXPLICIT` | DBRef convention — a `<field>.$id` path | `creator.$id` → `users` |
| `HIGH_CONFIDENCE` | `<singular>_id` / `<singular>Id` naming AND observed type matches the referenced collection's `_id` value-domain (objectId / string / int64) | `orders.user_id` → `users` |
| `POSSIBLE` | name resolution alone (value-domain type not confirmed) | `orders.items[].product_id` variants |

Candidates carry: `collection`, `path`, `references`, `references_field`
(always `_id`), `confidence`, `kind` (`dbref` / `array_of_ids` /
`single_ref`), `observed_bson_types` and `auto_fk`.

## What becomes a foreign key

Only `EXPLICIT` and `HIGH_CONFIDENCE` candidates have `auto_fk: true` and
enter the normalized inventory's `foreign_keys` (column → referenced
collection's `_id`, with the confidence recorded). Everything else —
including all array-element references — stays in the analysis metadata
(`mongodb.relationships`) for operator review (28F.1). The synthetic source
proves the split: `orders.user_id` lands as an FK column on the `orders`
table, while `orders.items[].product_id` remains a candidate.

## Name resolution

`referencedCollection()` strips id suffixes (`._id`, `_id`, `.id`, `Id`,
`_ids`, `Ids`), singularizes (`users` → `user`, `categories` → `category`)
and matches against the database's collections, also trying the pluralized
form. Dot-path tails are snake-cased before matching. No match → no
candidate; the inferer never invents a target.

Worked examples from the synthetic source:

| Field path | Resolution | Confidence |
|------------|------------|------------|
| `orders.user_id` | `user_id` → `user` → pluralized `users` (exists) | `HIGH_CONFIDENCE` (ObjectId type matches `users._id`) |
| `orders.items[].product_id` | `product_id` → `products` (exists) | array-of-ids candidate, kept for review |
| a hypothetical `creator.$id` | DBRef convention | `EXPLICIT` |

Candidates are sorted by collection/path so analysis output is stable
across runs.

## Array-element references stay candidates

A reference inside an array of objects (`items[].product_id`) has no column
on the parent collection — the parent table cannot carry the FK. The
candidate is kept in metadata; when the array is split into a derived child
table (ARRAYS.md), the CHILD table carries the parent linkage
(`parent_id` → parent `_id`, confidence `EXPLICIT`) and the element
reference can be materialized there by the operator's plan.

## Value-domain fingerprints (privacy)

The `_id` value-domain used for type matching is a fingerprint only: the
dominant BSON type of each collection's `_id` plus the sample size. No raw
identifier values are retained, so no data leaks through the relationship
metadata (28J.1).

## System databases

`admin`, `config` and `local` (`RelationshipInferer::SYSTEM_DATABASES`) are
excluded from import by default; `discoverProjects()` marks them
`system` / `excluded_by_default` instead of assuming anything.
