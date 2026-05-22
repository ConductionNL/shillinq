# Tasks — IV3 Quarterly Reporting to CBS

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-iv3-reporting` spec.
> They are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files are
> edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `bookkeeping-iv3-reporting` capability spec already exists,
  no `IV3Report`/`IV3ReportLine` schemas are declared, and no `lib/Service/IV3*` PHP
  classes are present (per ADR-031 anti-pattern enumeration); note this capability
  "enables Dutch SMBs to submit CBS IV3 quarterly financial reports"
- [ ] Task 2: Author `specs/bookkeeping-iv3-reporting/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger,
  bookkeeping-chart-of-accounts` header, `REQ-IV3-NNN` requirements using RFC 2119 keywords,
  and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-031 + ADR-022 inline
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (CBS spec version drift, GL aggregation alignment, gateway timing) /
  Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table, D1 (IV3 as GL view),
  D2 (OR lifecycle for submission), D3 (declarative GL aggregation), D4 (mandatory field validation),
  D5 (iv3FieldCode-driven mapping), D6 (gateway scoped externally)
- [ ] Task 5: Declare the `IV3Report` schema in `lib/Settings/shillinq_register.json` with all
  REQ-IV3-001 fields (reportNumber, administrationId, fiscalYear, quarter, status,
  reportDate, submissionDate, filedDate, notes)
- [ ] Task 6: Declare the `IV3ReportLine` schema in `lib/Settings/shillinq_register.json` with all
  REQ-IV3-002 fields (reportId, iv3FieldCode, accountNumber, debitAmount, creditAmount, netAmount, sequence)
- [ ] Task 7: Extend the `Account` schema (T1) in `lib/Settings/shillinq_register.json` with optional
  `iv3FieldCode` property per REQ-IV3-003 (mapping GL accounts to CBS IV3 fields)
- [ ] Task 8: Add `x-openregister-lifecycle` to `IV3Report` declaring every transition in REQ-IV3-004
  (`draft → validated → submitted → filed`) with preconditions (mandatory field mapping, GL balance validation)
  and audit-trailed actions
- [ ] Task 9: Implement GL aggregation as `x-openregister-aggregations` per REQ-IV3-007 —
  quarterly SUM(GLEntry.amount) grouped by Account.iv3FieldCode, excluding inter-company eliminations,
  materialising IV3ReportLine items; NOT a PHP service
- [ ] Task 10: Implement mandatory GL account validation as `x-openregister-aggregations` precondition
  per REQ-IV3-005 — before transitioning to validated, verify all mandatory CBS IV3 fields (K1000, K1100, K2000,
  K2100, K3000, K4000, K5000, etc.) have ≥1 mapped GL account; reject with error if incomplete
- [ ] Task 11: Implement GL inter-company elimination exclusion per REQ-IV3-006 — when aggregating GL for IV3,
  exclude entries where eliminationFlag=true (coordinate with bookkeeping-consolidation spec, T3)
- [ ] Task 12: Implement IV3 submission gateway call per REQ-IV3-009 — on `IV3Report.submitted` transition,
  POST report metadata + all IV3ReportLine items to CBS gateway endpoint (`/api/iv3/submit`); record receipt
  number and timestamp; await `filed` transition on receipt confirmation (gateway integration via separate cbs-gateway app)
- [ ] Task 13: Add 2 manifest navigation entries (`IV3 Reports`, `IV3 Report Detail`) + their `type: index` /
  `type: detail` pages to `src/manifest.json` per REQ-IV3-008; `node tests/validate-manifest.js` exits 0
- [ ] Task 14: Update `openspec/architecture/adr-000-data-model.md` with `IV3Report`/`IV3ReportLine` entries,
  reconciling against any existing `Report` / `FinancialReport` data-model entries; extend `Account` entry with
  iv3FieldCode property documentation
- [ ] Task 15: Seed data generation — in `lib/Settings/shillinq_register.json` `components.objects[]`, add
  3–5 example IV3Report + IV3ReportLine objects per design.md (Q1–Q4 2026 reports in various statuses:
  draft, validated, submitted, filed); use @self envelope with realistic EUR amounts, valid Dutch postcodes,
  and dates aligned to CBS calendar quarters
- [ ] Task 16: Deduplication check — grep openspec/specs/ and openregister/lib/Service/ for overlap with
  ObjectService, RegisterService, SchemaService, ConfigurationService (no IV3 capability duplicates expected);
  verify no `IV3Formatter` or parallel report-generation service exists elsewhere; document findings

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review
(e.g. `/test-persona-janwillem` for SMB) confirms the IV3 workflow matches Dutch quarterly
filing process (GL close → aggregation → validation → CBS submission → filing). Architecture
reviewer confirms ADR-022 + ADR-031 compliance (no app-local dunning table; lifecycle declarative
or ADR-031-exception-annotated guard; manifest carries the navigation; no PHP report service).
No source code changes outside `openspec/changes/bookkeeping-iv3-reporting/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`)
is responsible for:

- **PHPUnit unit tests** for IV3 report lifecycle, GL aggregation, mandatory field validation,
  inter-company elimination exclusion, CBS submission gateway call (pre-declared on Tasks 8–12)
- **Playwright MCP browser tests** for the 2 manifest navigation entries, IV3 list and detail views
  (pre-declared on Task 13)
- **Integration tests** for GL-to-IV3 aggregation end-to-end: create GL entries → create IV3Report
  → verify IV3ReportLine line items match GL sum by account
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/iv3-reporting.md` per ADR-030 journeydoc convention
  (1. User context: "I'm a Dutch SMB with Q2 ending June 30" 2. Task flow: GL close →
  IV3 report generation → validation → CBS submission 3. Screenshots from running app)
- Screenshots of IV3 list page, detail page, validation error, submission confirmation
- Commit screenshots to `docs/images/iv3-*.png`

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch
(`nl_NL`) and English (`en_US`) translation strings for:

- "IV3 Reports"
- "IV3 Report"
- "Quarterly Report"
- "Generate IV3 Report"
- "Validate"
- "Submit to CBS"
- "Filed"
- "Q1", "Q2", "Q3", "Q4"
- "Draft", "Validated", "Submitted", "Filed"
- "Cannot validate report: CBS field {code} is unmapped. Add a GL account mapped to {code} in chart of accounts and try again."
- "IV3 Report submitted to CBS. Receipt: {receiptNumber}"
- "IV3 Report filed by CBS on {filedDate}"
- All other user-visible labels, error messages, and placeholder text in the IV3 workflow

Both `l10n/en.json` and `l10n/nl.json` must contain exactly the same keys with zero gaps.
