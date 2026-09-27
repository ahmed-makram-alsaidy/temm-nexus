# Type mapping (28K)

Deterministic BSON → PostgreSQL-representation mapping in `TypeMapper.php`.
Conversion is total and lossless for supported types; anything that cannot
be represented losslessly is serialized as tagged extended JSON — never
silently coerced.

## The mapping table

| BSON type | Normalized type | Value conversion (`convertValue`) |
|-----------|-----------------|-----------------------------------|
| `string` | `text` | the string |
| `boolean` | `boolean` | the bool |
| `int32` | `int4` | the int |
| `int64` | `int8` | the int |
| `double` | `float8` | the float |
| `decimal128` | `numeric` | EXACT decimal string — never float (28K.1) |
| `date` | `timestamptz` | UTC ISO-8601 with millisecond precision (28K.2) |
| `timestamp` | `timestamptz` | UTC ISO-8601 (seconds component) |
| `objectId` | `text` | 24-char lowercase hex, preserved (28M) |
| `binary` | `text` | `"bin64:"` + base64 — never routed through UTF-8 (28K.3) |
| `null` / `undefined` / `minKey` / `maxKey` | `text` (nullable) | PHP `null` |
| `regex` / `symbol` / `code` / `dbPointer` | `jsonb` | extended JSON (`{$type, $value}`) |
| `array` / `document` | `jsonb` | canonical JSON (keys sorted recursively) |

Date example: epoch millis `1735689600123` → `2025-01-01T00:00:00.000Z`.
Negative epochs are floored correctly (ms extracted before second division).

## Decimal128 — exact by construction (28K.1)

`BsonCodec` implements Decimal128's BID encoding: 1 sign bit + a 14-bit
biased exponent (**bias 6176**) + a 113-bit binary integer coefficient, all
arithmetic via **BCMath** — the value never passes through a float. The
specials `Infinity`/`-Infinity`/`NaN` are decoded and encoded through their
combination bits. Wire round-trips are exact for financial values, 34-digit
coefficients and 1e-33 magnitudes
(`ProtocolTest::test_decimal128_is_exact_never_float` includes known BSON
vectors), and `MongodbMigrationTest` asserts `'19.991'` and `'250.75'`
survive the full pipeline into SQLite as strings — no drift.

## Canonical JSON (28Q.1)

`TypeMapper::canonicalJson()` renders documents/arrays deterministically:
keys sorted recursively, tagged values reduced to their JSON
representation (`$oid`, `$date`, `$numberDecimal`, `$binary`, `$timestamp`,
`$regularExpression`, `$minKey`, `$maxKey`), `JSON_UNESCAPED_UNICODE` +
`JSON_PRESERVE_ZERO_FRACTION`. This powers the `document` jsonb column of
JSONB_DOCUMENT / HYBRID strategies, scalar-array columns, and the SHA-256
document fingerprints used for validation.

## Where types meet columns

The column's `nullable` comes from the field's observed null/missing rate
(SCHEMA_INFERENCE.md), the type from the DOMINANT observed BSON type
(`mergeObservedTypes` records the full type mix as variance evidence when
more than one type appeared). Binary values arrive as `bin64:<base64>`
text: safe through any driver/encoding, decodable back to bytes, and never
the UTF-8-corrupted mess raw bytes would become.

## Honest edges

- `regex`/`symbol`/`code`/`dbPointer` have no relational equivalent — they
  become deterministic extended-JSON strings in jsonb.
- `minKey`/`maxKey`/`undefined` convert to SQL NULL with the original BSON
  type recorded in the inference evidence.
- Unknown/forward-compatible BSON types decode as tagged values and take
  the `jsonb` fallback rather than failing the run.
