# Tasks — Archiefwet Retention

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-archiefwet-retention`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `RetentionRule` schema, no per-schema retention-rule references, and no `bookkeeping-archiefwet-retention` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-archiefwet-retention/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1), consumes OR's lifecycle retention abstraction` header, `REQ-ARC-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document D1-D5 decisions (rules-as-register; per-schema reference; consumed-not-authored enforcement; audit-hash preservation; `daysUntilRetention` derivation)
- [x] Task 5: Declare the `RetentionRule` schema in `lib/Settings/shillinq_register.json` with all REQ-ARC-002 fields (selectielijstCode, description, retentionYears, retentionTrigger, disposition, legalBasis, customRetentionYears, administrationId)
- [x] Task 6: Ship `lib/Settings/seeds/selectielijst-gemeenten-2020.json` (~30 rules covering financial records, subsidie-grant records, meeting minutes, project records, etc.) with SPDX header + `_meta.source: "Archiefwet 1995 + Selectielijst-2020"` per REQ-ARC-002
- [x] Task 7: Author REQ-ARC-003's per-schema mapping table enumerating EVERY existing Shillinq schema (T1 + T2 + the 9 sibling T3 schemas) with its assigned Selectielijst code and citation
- [x] Task 8: Add `x-openregister-lifecycle.retention.rule` reference on every existing Shillinq schema per the REQ-ARC-003 mapping table
- [x] Task 9: Declare `daysUntilRetention` as `x-openregister-calculations` on every retention-bound schema per REQ-ARC-007
- [x] Task 10: Declare the `RetentionRule.customRetentionYears` validation rule rejecting values BELOW the statutory minimum per REQ-ARC-004 (operator may extend retention, never shorten below the Archiefwet floor)
- [x] Task 11: Extend the repair step under `lib/Migration/` to import the Selectielijst seed for every administration (idempotent on re-run; operator overrides preserved)
- [x] Task 12: Add `Administratie > Bewaartermijnen` navigation + pages to `src/manifest.json` with `type: index` + `type: detail` per REQ-ARC-009; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with the new `RetentionRule` entity, its `Primary spec:` reference, and a one-paragraph note on the per-schema retention-rule reference pattern
- [x] Task 14: File OR issue if `opsx-ff` discovery confirms any retention engine gap (selective anonymisation, audit-hash preservation across anonymisation) per REQ-ARC-006 expectation — filed as openregister #99 "Retention engine: disposition dispatch + selective anonymisation with audit-hash preservation" (Codeberg issue openregister#99, pre-migration, not migrated to GitHub); state OPEN as of 2026-06-08 (created 2026-06-08, last updated 2026-06-08). REQ-ARC-006 enforcement is consumed-not-authored from OR per ADR-022/ADR-024/ADR-031 — shillinq spec remains correct as authored; tracking issue stays open until OR ships the disposition dispatch + selective anonymisation + audit-hash preservation work.

## Verification

`openspec validate` must exit clean on the change folder. Archivist-persona peer review (e.g. `/test-persona-noor`) confirms the per-schema mapping table covers every record-type and the Selectielijst codes match Archiefwet + VNG Selectielijst-2020 guidance. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (retention enforcement consumed from OR not authored in shillinq; rules as register not enum; operator override above minimum only; per-schema reference via `x-openregister-lifecycle.retention.rule`). No source code changes outside `openspec/changes/add-shillinq-archiefwet-retention/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering schema-load validator enforces retention rule presence on every schema, operator override above statutory minimum succeeds, override below rejects, `daysUntilRetention` derivation correctness; integration test confirming OR retention enforcement purges/archives/anonymises per rule and audit-trail hashes survive anonymisation; Playwright MCP browser tests for the Bewaartermijnen index/detail pages; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/archiefwet-retention.md` per ADR-030 journeydoc convention and commits a Bewaartermijnen index screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Bewaartermijn`, `Vernietigen`, `Archiveren`, `Anonimiseren`, `Archiefwet`, `Selectielijst`, `Bewaartermijn verstrijkt`, `Dagen tot bewaartermijn`.
