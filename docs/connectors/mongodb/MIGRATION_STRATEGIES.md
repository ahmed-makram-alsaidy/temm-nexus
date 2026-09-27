# Migration strategies (28I/28L)

Every collection gets exactly one mapping strategy, decided
deterministically by `MongodbSourceAdapter::decideStrategy()` from the
inference evidence — explainable, reproducible, no forced normalization.

## The decision matrix

Evaluated in order (array-element paths and `_id` excluded from the field
statistics):

| Condition | Strategy | strategy_reason (verbatim) |
|-----------|----------|----------------------------|
| nesting overflow (any field beyond depth 8) | `JSONB_DOCUMENT` | "nesting depth exceeds safe inference depth (28L.2)" |
| variance ratio > 50% of fields | `JSONB_DOCUMENT` | "schema variance on N% of fields (28E.3)" |
| more than 60 inferred fields | `JSONB_DOCUMENT` | "N inferred fields (28I.2)" |
| stable shape, zero object-array fields | `RELATIONAL_TABLE` | "stable shape backed by a collection validator (28G.2)" or "stable shape across the sample (28I.1)" |
| otherwise (object-array fields present) | `HYBRID` | "N array-of-object field(s): common fields as columns, variable payload as JSONB (28I.3), arrays split to child tables (28L.1)" |

The chosen `strategy` + `strategy_reason` are recorded per collection (in
`mongodb.strategies` AND on every table item), so every mapping decision is
auditable after the fact.

## Per-strategy column projection

| Strategy | Columns |
|----------|---------|
| `JSONB_DOCUMENT` | `_id` + `document jsonb NOT NULL` (the whole canonical document) |
| `RELATIONAL_TABLE` | `_id` + one column per flat field (scalar arrays → `jsonb`; nested objects flattened per EMBEDDED_DOCUMENTS.md) |
| `HYBRID` | the relational columns PLUS a `document jsonb` fallback column PLUS derived child tables for object arrays |

Derived child tables (`<collection>__<field>`, strategy
`ARRAY_CHILD_TABLE`) carry `parent_id` (FK → parent `_id`), `__idx`
(ordering) and nullable element columns — see ARRAYS.md. A child name that
would collide with a real collection is skipped (the array stays in the
JSONB payload).

Example projection for the `orders` HYBRID table:

```
columns:  _id (text, PK) · user_id (text) · total (numeric) · currency (text)
          status (text) · created_at (timestamptz) · document (jsonb)
child:    orders__items [parent_id (FK → orders._id), __idx (int4),
          product_id (text), qty (int4), price (numeric), note (text, nullable)]
```

## Worked examples (synthetic source, asserted in tests)

| Collection | Evidence | Decision |
|------------|----------|----------|
| `users` | stable shape, 8 sampled docs, no object arrays | `RELATIONAL_TABLE` — flattened `address__*`, `tags` jsonb |
| `products` | stable + `$jsonSchema` validator | `RELATIONAL_TABLE` — reason names the validator |
| `orders` | `items[]` array of objects | `HYBRID` — relational columns + `document` + `orders__items` child |
| `mixed_documents` | `value` field is string/int/object/missing/null across the sample; one 11-level document | `JSONB_DOCUMENT` — variance + overflow |
| `wide` (perf fixture, 1000 fields) | > 60 fields | `JSONB_DOCUMENT` — columns collapse to `[_id, document]` by design (`MongodbPerformanceTest`) |

`MongodbConnectorTest::test_strategy_decisions_are_deterministic_and_explained`
pins all of the above, including the `orders__items` child shape.

## Strategy vs plan

The strategy is the connector's RECOMMENDATION baked into the normalized
inventory; the plan layer and the operator can still adjust per-item
mapping before a run. What the connector guarantees is that the default is
deterministic, the reason is recorded, and the extraction projection always
matches the decided strategy.
