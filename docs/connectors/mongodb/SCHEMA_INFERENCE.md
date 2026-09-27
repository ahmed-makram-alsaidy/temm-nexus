# Schema inference (28E)

MongoDB is schemaless. The connector's inference (`SchemaInferer.php`) is
**probabilistic and never authoritative**: inferred fields are EVIDENCE
attached to the analysis, not hard schema. Sample values are never stored —
only structural metadata (28J.1 privacy, asserted by
`MongodbSecurityTest::test_analysis_artifacts_never_carry_document_values`).

## Sampling (28E.1 — bounded by design)

Per collection, the adapter streams the FIRST N documents ordered by `_id`
ascending (`find` with `sort: {_id: 1}`, batched) where N = `sample_size`
(default `100`, `SchemaInferer::DEFAULT_SAMPLE_SIZE`; configurable per
source). Sampling stops at N documents or the hard safety bound
(100,000), whichever comes first; a `truncated` flag records when field
limits (`MAX_FIELDS_PER_COLLECTION = 400`) cut inference short. Memory stays
proportional to DISTINCT paths, never to collection size.

## Per-field evidence

Every dot-path (`address.city`, `items[].product_id`) accumulates:

| Attribute | Meaning |
|-----------|---------|
| `normalized_type` | dominant observed BSON type mapped through TypeMapper |
| `bson_types` | observed BSON types + counts |
| `frequency_percent` | dominant type frequency (e.g. `string 99.8%`) |
| `missing_or_null_percent` | absent + explicit null rate over the sample |
| `nullable` | true when any null or missing observation |
| `missing` | documents where the path is absent |
| `array_shape` | `null` / `scalar` / `document` / `mixed` |
| `array_item_types` | observed element types of an array |
| `max_depth` | deepest observation of the path |
| `overflow` | path breached the safe inference depth |

## Variance (28E.3) and depth (28L.2)

A field observed with more than one BSON type (EXCLUDING missing-ness, which
alone is not variance) is flagged `variance: true` — the platform's
SCHEMA_VARIANCE evidence. The dominant type wins for the column; the mix is
recorded honestly. Nesting is capped at `MAX_DEPTH = 8`: documents deeper
than that are not walked further, their ancestors carry `overflow: true`,
and overflow forces the JSONB_DOCUMENT strategy
(MIGRATION_STRATEGIES.md) instead of a deep, fragile column set.

## Validators are stronger evidence (28G.2)

A collection's `$jsonSchema` validator (from `listCollections` options) is
inventoried alongside the inference — canonical JSON plus
`validation_level`/`validation_action`. Where a validator exists, the
strategy reason says so ("stable shape backed by a collection validator"),
because the validator is authoritative about the intended shape where
sampling is merely observed.

## Where the output lands

- Per collection: `mongodb.collections.<name>` carries `documents`,
  `avg_obj_size_bytes`, `capped`, `validator`,
  `schema_variance_fields`.
- Per table: `columns[]` (strategy-projected, see MIGRATION_STRATEGIES.md),
  `sanitized_field_map` (column → source dot-path) and the
  `migration_strategy` / `strategy_reason` pair.
- Rare fields absent from the sample are simply not inferred — re-run
  analysis (optionally with a larger `sample_size`) to refresh. This is the
  documented limitation of probabilistic inference, stated in the analysis
  rather than hidden.
