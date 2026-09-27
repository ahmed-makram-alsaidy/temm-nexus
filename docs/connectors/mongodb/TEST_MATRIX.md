# Test matrix (Phase 28)

37 tests / 326 assertions across 5 classes in
`apps/owner-console/tests/Feature/Phase28/`, all passing
(`php artisan test tests/Feature/Phase28`). Spec sections 28A-28U map to
the test methods below.

## The harness

`Concerns/fake-mongo-server.php` is a scripted MongoDB **wire-protocol
server** (OP_MSG over TCP) implementing exactly the read commands the
connector uses — discovery, cursors, `$collStats`, optional SCRAM auth
(`--auth user:pass`). `Concerns/RunsFakeMongoServer.php` spawns it on an
ephemeral loopback port with the synthetic 28V.1 `shop` dataset: 8 users
(Arabic names, nested addresses, Decimal128, scalar arrays), 6 products
(`$jsonSchema` validator), 6 orders (2-element `items[]` arrays), 10 TTL-
indexed events, `mixed_documents` (schema variance + an 11-level document),
a `photos` GridFS pair and the `admin`/`local` system databases. No Docker
or ext-mongodb needed. The **Docker path** (`mongo:7` on loopback) is the
real-server dogfood for wire behavior CI cannot reach (SELF_HOSTED.md).

## ProtocolTest (8) — 28K/28B codec, URI, wire

| Test | Spec |
|------|------|
| `test_bson_roundtrip_preserves_every_type` | 28K |
| `test_decimal128_is_exact_never_float` | 28K.1 |
| `test_deep_documents_decode_within_bounds` | 28L.2/28T |
| `test_uri_parsing_standard_srv_tls_and_redaction` | 28B.1 (SRV⇒TLS, redaction) |
| `test_write_commands_are_structurally_impossible` | read-only allowlist |
| `test_wire_client_handshake_find_and_cursor_streaming` | 28A/28H, 28V.7 (Arabic on the wire) |
| `test_scram_authentication_against_scripted_server` | 28B (code 13 unauthed) |
| `test_wrong_password_is_refused_without_leaking_material` | 28B/28T (code 18) |

## MongodbConnectorTest (12) — 28A-28N connector surface

| Test | Spec |
|------|------|
| `test_mongodb_registers_through_the_connector_sdk` | 28A/28A.2 |
| `test_connection_test_passes_against_wire_server` | 28B |
| `test_local_connection_requires_operator_opt_in` | 28B.3 |
| `test_inventory_maps_the_database_to_the_normalized_shape` | 28C/28D/28N/28O |
| `test_schema_inference_reports_types_frequency_and_variance` | 28E/28K.1/28M |
| `test_relationship_inference_classifies_confidence` | 28F |
| `test_index_and_validator_analysis` | 28G/28G.2 |
| `test_strategy_decisions_are_deterministic_and_explained` | 28I/28L.1 |
| `test_dynamic_capability_probe_and_health` | 27C probe integration |
| `test_extraction_is_batched_projected_and_deterministic` | 28H/28K.1/28K.2/28L |
| `test_child_table_extraction_splits_arrays_with_ordering` | 28L.1/28V.7 |
| `test_fingerprint_is_stable_and_validation_artifacts_report` | 28Q.2/28B.4 |

## MongodbMigrationTest (2) — 28V pipeline dogfood

| Test | Spec |
|------|------|
| `test_full_pipeline_analyze_plan_migrate_validate` | 28V.2/V.3/V.4/V.5/V.6/V.7 + 28Q — full analyze→classify→plan→run (disposable sqlite target)→validate; Decimal exactness, Arabic roundtrip, JSONB preservation, child-FK integrity, id coverage, fingerprint immutability |
| `test_dry_run_never_touches_the_target` | 28V.2 (dry run creates nothing) |

## MongodbSecurityTest (12) — 28T battery

| Test | Spec |
|------|------|
| `test_uri_never_leaks_into_logs_exceptions_or_ui_surfaces` | 28T |
| `test_analysis_artifacts_never_carry_document_values` | 28J.1 |
| `test_ssrf_guard_blocks_private_and_metadata_targets` | 28B.3 |
| `test_cross_project_secret_isolation` | 28T |
| `test_source_write_operations_are_structurally_impossible` | 28T |
| `test_sources_stay_read_only_through_the_connector` | 28T |
| `test_malicious_field_names_sanitize_deterministically` | 28T.2 |
| `test_deep_nesting_is_bounded_not_explosive` | 28L.2/28T |
| `test_oversized_bson_is_refused` | 28T (16 MiB) |
| `test_resume_tokens_reject_tampered_shapes` | 28T/28H.1 |
| `test_connector_instance_substitution_is_refused` | 28T |
| `test_connector_queries_are_programmatic_not_submitted` | 28T.1 |

## MongodbPerformanceTest (3) — 28U synthetic performance

| Test | Spec |
|------|------|
| `test_100_collections_metadata_stays_responsive` | 28U (< 30s) |
| `test_1000_inferred_fields_normalize_quickly` | 28U/28I.2 (>60 fields → JSONB) |
| `test_10k_document_extraction_is_batched_and_bounded` | 28U.1 (< 60s, < 64MB peak delta, multiple server-side batches) |

## Honest scope

The suite exercises the REAL wire protocol end to end — but against the
scripted server. Replica-set specifics, live Atlas SRV/TLS and
deployment-level authorization are covered only by the Docker dogfood path
and documented as such (ATLAS.md, SELF_HOSTED.md).
