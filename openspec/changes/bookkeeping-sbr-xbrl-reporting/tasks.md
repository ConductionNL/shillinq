# Tasks — SBR / XBRL Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-sbr-xbrl-reporting` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-sbr-xbrl-reporting` capability spec already exists, no `XBRLTaxonomy`/`SBRDocumentType`/`XBRLMapping` schemas are declared, and no `lib/Service/XBRL*`, `lib/Service/SBR*`, or `lib/Service/Reporting*` PHP classes are present (per ADR-031 anti-pattern enumeration)
- [x] Task 2: Author `specs/bookkeeping-sbr-xbrl-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory reporting + compliance)` / `Depends on: bookkeeping-general-ledger, bookkeeping-chart-of-accounts` header, `REQ-SBR-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-024 + ADR-031 + ADR-022 inline
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope (XBRL GL taxonomy versions, SBR filing types, account mapping, compliance validation) / Risks (taxonomy stability, mapping coverage gaps, endpoint auth, GL balance checks) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (XBRL GL versionable register), D2 (SBR lifecycle via OR), D3 (mapping is schema-driven), D4 (compliance validation is aggregation), D5 (taxonomy metadata not computed), D6 (submission contract declared)
- [x] Task 5: Declare the `XBRLTaxonomy` schema in `lib/Settings/shillinq_register.json` with all REQ-SBR-002 fields (taxonomyId, name, taxonomyVersion, publicationDate, effectiveDate, expiryDate, status, description)
- [x] Task 6: Declare the `SBRDocumentType` schema in `lib/Settings/shillinq_register.json` with all REQ-SBR-003 fields (name, code, description, applicableEntityTypes, filingDeadline, requiredFields, submissionEndpoint, authMethod, status, administrationId)
- [x] Task 7: Declare the `XBRLMapping` schema in `lib/Settings/shillinq_register.json` with all REQ-SBR-004 fields (sourceAccountId, targetXBRLConcept, taxonomyVersion, mappingDate, status, notes) — FK to Account and XBRLTaxonomy via relations
- [x] Task 8: Add `x-openregister-lifecycle` to `SBRDocumentType` declaring every transition in REQ-SBR-005 (`draft → validated → submitted → approved` / `rejected`, with guards for GL completeness)
- [x] Task 9: Implement pre-filing validation per REQ-SBR-006 as four `x-openregister-aggregations` predicates: GL completeness (all GL entries mapped), mapping coverage (all accounts mapped), mandatory fields (FiscalYear dates, entity type), GL balance (debits = credits)
- [x] Task 10: Implement mapping validation aggregation per REQ-SBR-007 — queries active Account records, checks XBRLMapping coverage per taxonomyVersion, returns unmapped accounts list
- [x] Task 11: Implement filing deadline notifications per REQ-SBR-008 — lifecycle action or OR ScheduledWorkflow that fires notification 30 days before `SBRDocumentType.filingDeadline` for applicable entity types
- [x] Task 12: Add 3 manifest navigation entries (`XBRL Taxonomies`, `SBR Documents`, `Mapping Validation`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-SBR-009; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Load seed XBRL GL taxonomy objects (2026, 2025 versions) via `ConfigurationService::importFromApp()` repair step per design.md Seed Data (2 XBRLTaxonomy + 2 SBRDocumentType + 2 XBRLMapping examples)
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with `XBRLTaxonomy`/`SBRDocumentType`/`XBRLMapping` entries, linking to the SBR/XBRL regulatory context and Belastingdienst reference

## Deduplication Check

- Verify no overlap with `bookkeeping-chart-of-accounts` (which declares `Account`; we declare `XBRLMapping` as the relation layer).
- Verify no overlap with `bookkeeping-audit-trail` (which auto-tracks changes; we rely on that for SBR lifecycle audit).
- Verify no overlap with `bookkeeping-notifications` or OR's notification engine (used for deadline alerts).
- No custom XBRL generation service — T4 owns outbound generation.
- Document findings in implementing PR description.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g., Belastingdienst compliance checklist) confirms the SBR/XBRL filing flow matches Dutch regulatory requirements (annual reporting, XBRL GL taxonomy alignment, mapping completeness, validation enforcement). Architecture reviewer confirms ADR-024 (schema declarations), ADR-031 (declarative lifecycle + aggregations, no PHP service), and ADR-022 (no app-local dunning parallel) compliance. Manifest validation (`node tests/validate-manifest.js`) exits 0. No source code changes outside `openspec/changes/bookkeeping-sbr-xbrl-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for XBRL GL taxonomy versioning, SBR filing lifecycle transitions, pre-filing validation aggregations (GL completeness, mapping coverage, mandatory fields, GL balance), account mapping resolution, filing deadline notification trigger, and webhook parsing from Belastingdienst (pre-declared on Tasks 8–11); Playwright MCP browser tests for the 3 manifest navigation entries and the "Mapping Validation" workflow for fixing unmapped accounts (pre-declared on Task 12); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/bookkeeping/sbr-xbrl-reporting.md` per ADR-030 journeydoc convention covering:
  - Regulatory context (Belastingdienst annual filing requirement).
  - XBRL GL taxonomy setup and version selection workflow.
  - SBR document creation, mapping validation, and lifecycle state management.
  - Filing deadline notifications and submission workflow (noting T4 handles actual submission).
- Governance/architect guide: `docs/architecture/sbr-xbrl-mapping-design.md` documenting the account-to-XBRL concept mapping model, aggregation design, and T3/T4 boundary (T3 declares contract, T4 submits).
- Screenshots: `docs/images/sbr-xbrl-taxonomies-index.png`, `docs/images/sbr-document-detail-lifecycle.png`, `docs/images/mapping-validation-coverage.png`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `XBRL Taxonomies`, `SBR Documents`, `Mapping Validation`, `XBRL Taxonomy`, `SBR Document Type`, `Filing Deadline`, `Validation Status`, `Mapping Coverage`, `GL Completeness`, `Mapping Coverage`, `Account Mapping`, `Unmapped Accounts`, `Jaarverslag (Annual Report)`, `Belastingaangifte (Tax Filing)`, `Draft`, `Validated`, `Submitted`, `Approved`, `Rejected`, `Active`, `Archived`, `Belastingdienst`, `DNB`, `XBRL GL Concept`.
