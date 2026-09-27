# GridFS (28N)

GridFS buckets are FILE STORAGE, not ordinary relational collections.
`GridFsInspector.php` detects them and the adapter reports them under the
normalized inventory's `storage` domain — never as tables.

## Bucket detection

A bucket is a `<prefix>.files` + `<prefix>.chunks` collection pair. The
detection rules:

- every collection ending in `.files` is checked for its matching
  `<prefix>.chunks` sibling;
- a `.files` collection WITHOUT a `.chunks` sibling is an ordinary
  collection (not a bucket);
- the bucket name is the prefix (`fs` for the default bucket); the pair is
  excluded from `tables[]` and appears as:

```json
"storage": {
    "present": true,
    "buckets": [
        {"name": "photos", "files_collection": "photos.files",
         "chunks_collection": "photos.chunks", "files": 1, "bytes": 1024,
         "kind": "gridfs"}
    ],
    "object_counts": {"photos": {"objects": 1, "bytes": 1024}}
}
```

`files` is the exact `count` of the `.files` collection; `bytes` comes from
a best-effort `$collStats` (`storageStats.size`) that degrades to honest
empty when permissions/topology refuse. The prefix is preserved — the
synthetic source's `photos` bucket is reported as `photos`, not `fs`
(asserted in `MongodbConnectorTest::test_inventory_maps_the_database_to_the_normalized_shape`).

## Strategy: PRESERVE_METADATA (28N.1)

`GridFsInspector::strategyFor()` returns the per-bucket recommendation:

```json
{
    "strategy": "PRESERVE_METADATA",
    "content_migration": "DEFERRED",
    "reason": "Files metadata (name, length, md5, uploadDate) maps to a relational table; binary content copy to platform storage is deferred and reported honestly (28V.8).",
    "validation": ["files_count", "total_bytes", "md5_metadata_present"]
}
```

The files metadata (filename, length, md5, uploadDate) is ordinary BSON and
maps cleanly to a relational table; the chunks' binary content copy into
platform object storage is DEFERRED — Phase 28 does not stream file
contents, and says so instead of faking a content capability
(`storage_metadata` content is not declared; 28V.8 honesty).

## What the operator sees

- GridFS pairs appear as storage buckets in the generic UI (same rendering
  path as Supabase storage buckets — `kind: gridfs`).
- The pair collections do NOT appear in the table list, the plan, or
  extraction; nothing duplicates them as fake tables.
- Validation counts (`validateSource`) include `gridfs_buckets` so the
  post-migration report states how many buckets were inventoried.

## Honest limits

Chunk-level integrity (n-index continuity, md5 recomputation from chunk
data) is out of scope for metadata-only mode; the recorded `md5` is source
metadata, not a recomputed digest. Content migration will arrive as a
separate declared capability, at which point the strategy flips from
`DEFERRED` — until then the inventory's word is "deferred", everywhere.
