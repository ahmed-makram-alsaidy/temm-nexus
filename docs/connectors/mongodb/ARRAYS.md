# Arrays (28L)

MongoDB arrays come in two shapes, and the connector maps them differently:
scalar arrays become a JSONB column; arrays of objects become derived child
tables.

## Scalar arrays → JSONB column

An array whose elements are scalars (or a mix of scalars, `array_shape`
`scalar`/`mixed` without document elements) becomes ONE `jsonb` column
holding the canonical JSON — key order of the original array preserved as
element order, Unicode untouched:

```
users.tags = ["vip", "الإسكندرية"]  →  tags jsonb
```

Extraction (`valueAtPath`) renders array nodes with
`TypeMapper::canonicalJson()`, so the column is deterministic and byte-
stable between runs (asserted in `MongodbConnectorTest`).

## Arrays of objects → derived child tables (28L.1)

When `array_shape` is `document` (or `mixed` with document elements), the
strategy layer derives a child table named `<collection>__<field>`:

```
orders.items[]  →  orders__items
```

Child table shape:

| Column | Type | Meaning |
|--------|------|---------|
| `parent_id` | parent `_id` type | FK to `<parent>._id` (confidence `EXPLICIT`) |
| `__idx` | `int4` | element position — array ORDERING preserved |
| element fields | per inference | exploded element paths (`qty`, `price`, `note`, …) |

Deliberate properties (all asserted in tests):

- **No primary key** — child rows are parent-relative; `strategy` is
  `ARRAY_CHILD_TABLE` and the `strategy_reason` names the source array.
- **Element columns are NULLABLE even when the parent field looked
  stable** — document-level sampling cannot prove element-level NOT NULL;
  an element field may be absent from some array items (28E.3 honesty).
- **Ordering survives migration**: `__idx` is the array index, and
  `MongodbMigrationTest` verifies 12 child rows from 6 orders with
  `__idx` 0/1 and zero orphans against `orders`.
- **Element-level Decimal128 stays exact** (`price` = `'12.34'`).

## Collision rule

If `<collection>__<field>` collides with a REAL collection name in the same
database, the child table is skipped and the array stays inside the
document payload (JSONB / HYBRID `document` column). The connector never
overwrites or merges a real collection's identity.

## Extraction semantics

`streamRows()` on a `__`-named table explodes the parent's array on the fly
(`streamChildTable`): each document yields one row per document-shaped
element, projected to the child columns. `countRows()` on a child table
returns the exploded element count, so validation parity (28Q) compares
like with like. Elements that are not documents (scalars inside a `mixed`
array) are skipped rather than guessed into columns, and nested arrays
inside elements (paths containing another `[]`) stay in the element JSON
rather than being recursively exploded.
