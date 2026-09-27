# Client scan (28R)

The `client_scan` capability (declared in `connector.json`, permission
`client.repo.read`) lets the platform detect MongoDB usage inside the
client application repository. The connector contributes its patterns
through the Phase 27 `ClientScannerProvider` contract — the generic
scanner merges them; nothing MongoDB-specific reaches the core.

`MongodbClientScanner.php` implements the provider:

| Method | Value |
|--------|-------|
| `scannerLabel()` | `MongoDB` |
| `patternsFor(string $language)` | driver idioms per language (below) + common patterns |
| `secretMarkers()` | `MONGO_URI`, `MONGODB_URI`, `MONGO_URL`, `MONGODB_PASSWORD`, `MONGO_PASSWORD`, `MONGO_CONNECTION` |
| `configDirNames()` | `[]` (honest — MongoDB has no canonical config directory) |
| `hardcodedUrlRiskCode()` | `hardcoded_mongodb_uri` |

## Patterns

Common (all languages): raw `mongodb://` / `mongodb+srv://` URLs,
`MongoClient(…)` / `mongoose.connect(…)` client initialization, and
credential-bearing environment markers.

| Language | Extra patterns |
|----------|----------------|
| JavaScript / TypeScript (default) | `.collection('…')`, `mongoose.model('…')`, `.db('…')`; find/aggregate/findOneAndUpdate; insert/update/delete/replaceOne; `.watch(` change streams; GridFS (`GridFSBucket`, `GridFsStorage`); `startSession`/`withTransaction`; Prisma `provider = "mongodb"` |
| PHP | `MongoDB\Client`, `jenssegers\mongodb`, `mongodb/mongodb`; `->selectCollection('…')`; find/findOne/aggregate; insert/update/delete/bulkWrite; `->watch(`; GridFS bucket |
| Python | `pymongo`, `MongoEngine`, `motor.motor_asyncio`, `AsyncIOMotorClient`; `db["name"]` / `db.name` access; find/aggregate; insert/update/delete/replace_one; sessions/transactions |

Each pattern is categorized (`url`, `client_init`, `collection_access`,
`find`, `insert_update_delete`, `transaction`, `change_stream`, `gridfs`,
`secret`, `prisma`). The collection-access patterns capture the collection
NAME (capture group `target: 1`) so callsites map to concrete collections
— e.g. `.collection('orders')` or `->selectCollection('orders')` records
`orders`, which the analysis layer can cross-reference against the
inventoried collections to flag code that writes to collections the
migration will touch.

## How it integrates

The connector exposes the provider through `MongodbConnector`
(`scannerLabel`, `patternsFor`, `secretMarkers`, `configDirNames`,
`hardcodedUrlRiskCode`), and the generic client scanner merges the
contribution with the other connectors' patterns during repository
analysis. A repository that hardcodes
`mongodb://user:pass@10.0.0.5/shop` produces:

- a `url` match carrying the `hardcoded_mongodb_uri` risk code, and
- a `secret` match (the URI is also a credential marker),

both stored as hashed evidence — the plaintext never persists.

## Guarantees and honesty

- **Reads files only, never executes application code** (28R.2).
- **Evidence is stored HASHED** — matched values (URIs, credentials) never
  enter the database in plaintext.
- Hardcoded URIs surface under the `hardcoded_mongodb_uri` risk code so the
  connection can be moved into the platform's vault-backed configuration.
- Coverage is honest, not universal: the pattern set covers the common
  driver idioms (Node driver, Mongoose, Prisma's MongoDB provider,
  PyMongo/Motor, MongoEngine, Laravel MongoDB packages) and raw URIs;
  exotic wrappers will not be detected and the scanner does not claim to.
