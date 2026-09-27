<!-- ⚠️ Never include secrets, customer data, production refs or machine-specific paths in PRs or test fixtures. -->

## Summary

<!-- What does this PR change and why? One topic per PR. -->

## Testing

<!-- How was this tested? New/extended tests use factories and FakeAiProvider — no paid APIs, no network. -->

- [ ] Tests added or extended (`vendor/bin/phpunit` / SDK suites)
- [ ] Docs updated for user-visible changes

## Impact checklists

**Security impact**

- [ ] No new credential handling, or it is covered by encrypted-at-rest vault patterns
- [ ] Read-only guarantees preserved (connectors/migration paths stay strictly read-only)

**Migration impact**

- [ ] No change to migration semantics, or the plan/rehearsal/validation behavior is updated and tested
- [ ] Analysis/honesty guarantees kept (no pretending a capability exists)

**Connector impact**

- [ ] No connector manifest/capability changes, or they are documented and versioned
- [ ] Connector SDK contract unchanged, or docs updated

## Breaking changes

- [ ] This PR introduces no breaking changes — **or** — breaking changes are called out in the PR description and CHANGELOG
