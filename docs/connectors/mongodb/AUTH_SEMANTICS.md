# Auth semantics (28O) — MongoDB users are NOT application users

The platform's `auth` domain means APPLICATION identities (Supabase
`auth.users`, password hashes, providers). MongoDB has no such domain:

- MongoDB **database users** (`db.system.users`, SCRAM principals) are
  database ADMINISTRATION credentials — they authenticate the wire
  connection, they are not end-user accounts.
- Therefore the normalized inventory reports, honestly:

```json
"auth": {
    "present": false,
    "users_count": 0,
    "identities_count": 0,
    "providers": [],
    "hash_strategy": "no_auth_domain"
}
```

- `streamAuthUsers()` returns 0 — the `BaseSourceAdapter` default is made
  explicit in `MongodbSourceAdapter` with the comment "MongoDB has no auth
  domain (28O) — nothing to stream".
- `auth_metadata` is NOT declared as a capability (28A.2 honesty; asserted
  in `MongodbConnectorTest::test_mongodb_registers_through_the_connector_sdk`).

## System databases are excluded

`admin`, `config` and `local` (`RelationshipInferer::SYSTEM_DATABASES`) are
excluded from discovery by default — they hold deployment internals
(`system.version`, the oplog), not application data. `discoverProjects()`
still LISTS them (a `read`-scoped user often sees limited output) but marks
each as `system: true` / `excluded_by_default: true` instead of assuming
anything. `system.*` collections are likewise skipped by `listCollections`
handling (28C).

## Application-user collections are domain data

If the application stores its users in a MongoDB collection — `users`,
`accounts`, `members`, whatever the schema uses — that collection IS
application domain data, and the connector treats it exactly like any other
collection: inferred, classified, extracted as a table. This is the correct
semantics, and it differs from Supabase where `auth.users` is a RESERVED
system domain: in MongoDB there is no reserved auth namespace to special-
case. The synthetic source's `users` collection is imported as an ordinary
relational table precisely to demonstrate this.

## Password hashes never reach the AI

Application password hashes (if the app-level collections contain them) are
document VALUES, and analysis artifacts — the only thing that feeds AI
contexts — carry STRUCTURE only: field paths, types, counts, strategies.
`MongodbSecurityTest::test_analysis_artifacts_never_carry_document_values`
plants value markers (emails, names, Arabic strings) in the synthetic
source and asserts NONE of them appear in any analysis item attribute; the
AI mapping pack (`AiContextBuilder::analysisPack`, focus `mapping`) reduces
attributes to shapes/counts by construction (28J.1).

## The honest summary

The inventory's auth domain for a MongoDB source is `not present` — never
faked, never approximated with DB-user counts. If a migration needs
application identities, they live in the domain collections the operator
selects.
