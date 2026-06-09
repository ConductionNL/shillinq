# Tasks — CBS Bestanden Extended

## 0. Deduplication Check

- [x] Task 0.1: Confirm no CBS export capability exists — scanned `openspec/specs/`, `openregister/lib/Service/`, and `shillinq/lib/` for CBS-related classes; confirmed `financial-reporting-accountability` is T2 (FinancialReport), distinct from this T3+ CBS aggregation + validation capability. Result: **No duplication found.**

## 1. Spec Foundation (This Change)

- [x] Task 1.1: Author `specs/cbs-bestanden-extended/specs.md` with `Status: proposed` / `Scope: shillinq (budgetq)` / `Tier: T3+extended` / `Depends on: bookkeeping-chart-of-accounts, bookkeeping-general-ledger` header; declares REQ-CBS-001 through REQ-CBS-010 using RFC 2119 keywords and GIVEN/WHEN/THEN scenario blocks
- [x] Task 1.2: Author `proposal.md` — introduces IV3 CBS reporting for Dutch statistical compliance; references Motivation (Verordening Statistieken Bedrijven), Affected Projects (shillinq, openregister), Scope (in/out), Approach (data models + export workflow + lifecycle), Risks (CBS format drift, mapping complexity, validation strictness) with mitigations
- [x] Task 1.3: Author `design.md` — includes Goals, Non-Goals, Decisions (D1 header/line split, D2 declarative mapping, D3 JSON→XML format, D4 four-state lifecycle), Reuse Analysis table (ADR-022 compliance), Seed Data section (3 example CBSSubmission + CBSLine records with realistic Dutch data), Data Model Integration (schema fields + rationale), Export Service Logic pseudo-code, File Attachment Format

---

## 2. Register Declarations — `lib/Settings/shillinq_register.json`

- [x] Task 2.1: Declare the `CBSSubmission` schema — declared in `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json` per ADR-037 modular fragment convention. Carries all REQ-CBS-001 fields (`submissionNumber`, `reportingPeriodStartDate`, `reportingPeriodEndDate`, `organizationLegalName`, `kvkNumber`, `taxIdentificationNumber`, `administrationId`, `status`, `submissionDate`, `iv3FileUri`, `iv3Checksum`, `validationErrors`, `description`, `currency`) with kvkNumber `^[0-9]{8}$` and taxIdentificationNumber `^NL[0-9]{10}B[0-9]{2}$` regex patterns. Declares `x-openregister-lifecycle` (initialState=draft) with the four transitions (validate, submit, accept, reject) per REQ-CBS-003 and `x-openregister-relations.administration` FK. RBAC (finance-manager/accountant/auditor) + audit-trail per fleet rule.

- [x] Task 2.2: Declare the `CBSLine` schema — declared in `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json`. Carries all REQ-CBS-002 fields (`cbsSubmissionId`, `cbsLineClassification`, `cbsLineNumber`, `accountRangeStart`, `accountRangeEnd`, `aggregatedAmount`, `glLineCount`, `currency`, `description`) with `cbsLineClassification` enum (Revenue, OperatingCosts, Depreciation, Interest, Taxes, OtherIncome, OtherExpenses) per REQ-CBS-002 and `x-openregister-relations.cbsSubmission` FK. RBAC + audit-trail per fleet rule.

## 3. Data Model Update

- [ ] Task 3.1: Update `openspec/architecture/adr-000-data-model.md` — add two new entity entries for `CBSSubmission` and `CBSLine` with schema.org types (Event, Thing), required fields, and relations; include reconciliation note linking this spec to the entries

## 4. Export Service Implementation — `lib/Service/CBSExportService.php`

- [ ] Task 4.1: Author `CBSExportService` class with method `generateSubmission(string $administrationId, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): CBSSubmission` per REQ-CBS-004; the service:
  1. Queries Account records with active status
  2. Loads GL transactions + GL lines for the period
  3. Loads account → CBS mapping from admin settings
  4. Aggregates GL amounts by CBS classification
  5. Creates CBSLine records via `ObjectService::saveObject()`
  6. Generates IV3 JSON per REQ-CBS-006
  7. Stores JSON file via `FileService::createFile()`
  8. Returns CBSSubmission in draft state

- [ ] Task 4.2: Author `CBSExportService::validateSubmission(CBSSubmission $submission): ValidationResult` per REQ-CBS-008; checks structural/balancing/accounting/completeness rules; returns `ValidationResult` with errors/warnings; blocks state transition if critical errors

- [ ] Task 4.3: Author `CBSExportService::generateIV3Json(CBSSubmission $submission): array` — produces JSON structure per REQ-CBS-006; includes format version, generation timestamp, submission metadata, line items, checksum

- [ ] Task 4.4: Author `CBSExportService::getMappingFromSettings(string $administrationId): array` — retrieves account → CBS line mapping from app settings (configurable per ADR-031); returns mapping table or throws exception if missing; allows per-administration override

## 5. API Controller — `lib/Controller/CBSSubmissionController.php`

- [ ] Task 5.1: Author `CBSSubmissionController` with RESTful routes per ADR-002:
  - `GET /api/cbs-submissions` — list submissions with filters (status, period, organization)
  - `GET /api/cbs-submissions/{id}` — retrieve single submission + lines
  - `POST /api/cbs-submissions` — create new submission
  - `PUT /api/cbs-submissions/{id}` — update submission (transition state)
  - `DELETE /api/cbs-submissions/{id}` — delete draft submission
  - `POST /api/cbs-submissions/{id}/generate` — trigger `CBSExportService::generateSubmission()` to compute lines

- [ ] Task 5.2: Controller methods are thin (<10 lines per ADR-003); call `CBSExportService` for business logic; validate input; return appropriate HTTP status (200, 400, 409, 422); include `message` field in error responses per ADR-002

## 6. Seed Data — `lib/Settings/seeds/`

- [ ] Task 6.1: Include 3 example `CBSSubmission` records in `openspec/changes/bookkeeping-cbs-bestanden-extended/design.md` Seed Data section (already authored); when landing in register file, ensure:
  - Each record has `@self` envelope with register, schema, slug
  - Field values use realistic Dutch data (KVK numbers, legal names, periods)
  - Submission statuses vary (draft, validated, submitted) to show lifecycle
  - Idempotent slug matching per ADR-031 guidance

- [ ] Task 6.2: Include 2-3 example `CBSLine` records in design.md (already authored); linked to example submissions; demonstrate aggregation logic (Revenue, OperatingCosts classifications with realistic amounts)

- [ ] Task 6.3: Seed data is loaded via `ConfigurationService::importFromApp()` during repair step or first-run (same mechanism as T1-T2 seeds)

## 7. Manifest Navigation — `src/manifest.json`

- [ ] Task 7.1: Add CBS Submissions navigation + pages per REQ-CBS-009:
  - Menu entry: `Bookkeeping > CBS Submissions` (or top-level, per UX review)
  - Index page: `type: index`, bound to `CBSSubmission` register, displays submission list with columns: submissionNumber, reportingPeriod, organizationLegalName, status, submissionDate
  - Detail page: `type: detail`, shows CBSSubmission header fields + CBSLine table + Files/Audit tabs
  - Filters: status (draft/validated/submitted/accepted/rejected), period (date range), organization (dropdown)
  - Actions: Validate (draft→validated), Submit (validated→submitted), Accept/Reject (submitted→accepted/rejected)

- [ ] Task 7.2: Validate manifest structure — run `node tests/validate-manifest.js`; confirm no errors

## 8. Permissions & Authorization

- [ ] Task 8.1: Define RBAC rules for CBS Submissions per ADR-023:
  - `Read` — Finance Manager, Accountant, Auditor roles
  - `Create` — Finance Manager role
  - `Validate` — Auditor role
  - `Submit` — Finance Manager role
  - `Accept/Reject` — (workflow external; recorded via manual update or webhook)

- [ ] Task 8.2: Implement via `AuthorizationService` (no custom code required; configured in register schema via `x-openregister-rbac` extensions)

## 9. Migration / Repair Step

- [ ] Task 9.1: Create repair step `lib/Migration/Load_CBS_Seeds_Step.php` implementing `IRepairStep` per ADR-003 lifecycle guidance; calls `ConfigurationService::importFromApp('shillinq', $cbsSeedsData, version: '1.0', force: false)` to idempotently load seed data; repair step runs on install and upgrade

## 10. Tests

### Unit Tests — `tests/Unit/Service/`

- [ ] Task 10.1: `CBSExportServiceTest` — tests for:
  - `generateSubmission()` computes correct CBSLine aggregations
  - `validateSubmission()` detects unbalanced amounts
  - `validateSubmission()` detects account mapping conflicts
  - `generateIV3Json()` produces valid JSON structure with checksum

### API Tests — Postman/Newman

- [ ] Task 10.2: `tests/api/cbs-submissions.postman_collection.json` — integration tests for:
  - `POST /api/cbs-submissions` — create new submission
  - `POST /api/cbs-submissions/{id}/generate` — generate export
  - `PUT /api/cbs-submissions/{id}` — validate transition
  - `GET /api/cbs-submissions` — list with filters
  - Error cases (validation failure, conflict)

### Browser Tests — Playwright

- [ ] Task 10.3: `tests/e2e/cbs-submissions.spec.ts` — UI workflow tests:
  - Navigate to CBS Submissions index
  - Create new submission
  - Trigger generate export
  - Transition states (draft → validated → submitted)
  - Download IV3 file
  - View audit trail

## 11. Documentation

### User Guide — `docs/user-guide/bookkeeping/`

- [ ] Task 11.1: Create `docs/user-guide/bookkeeping/cbs-submissions/` directory with:
  - `index.md` — overview of CBS reporting, who must submit, compliance requirements
  - `create-submission.md` — step-by-step to create and validate a CBS submission
  - `export-format.md` — explanation of IV3 JSON format, line classifications, mapping
  - `troubleshooting.md` — common validation errors, mapping conflicts, CBS rejection handling

### Screenshots

- [ ] Task 11.2: Capture 3 screenshots to `docs/images/cbs-submissions/`:
  - CBS Submissions index page (list of submissions)
  - Submission detail page (header + lines + files/audit tabs)
  - Generate export dialog / validation results

## 12. Internationalization (i18n)

- [ ] Task 12.1: Add Dutch (`nl_NL`) translation strings to `l10n/nl.json` and English (`en_US`) to `l10n/en.json`:
  - `CBS Submissions` (menu label)
  - `Submission Number`, `Reporting Period`, `Status`, `Organization`, `Submission Date`
  - `Draft`, `Validated`, `Submitted`, `Accepted`, `Rejected`
  - `Revenue`, `Operating Costs`, `Depreciation`, `Interest`, `Taxes`, `Other Income`, `Other Expenses` (line classifications)
  - `Generate Export`, `Validate`, `Submit`, `Accept`, `Reject`
  - Validation error messages: "GL total {amount} does not match CBS total {amount}", "Account mapping conflict", etc.

- [ ] Task 12.2: Ensure all user-visible strings in controller responses and service validation use `t(appName, 'text')` function per ADR-004

## Verification

- [ ] All Section 1 tasks (spec foundation) checked off ✓
- [ ] `openspec validate` exits clean on the change folder
- [ ] Peer review by Dutch bookkeeper persona confirms IV3 format matches CBS documentation
- [ ] Architecture reviewer confirms ADR-022 (no reimplementation of audit/RBAC), ADR-024 (manifest-driven nav), ADR-031 (declarative mapping) compliance
- [ ] Deduplication check (Task 0.1) documented and passed
- [ ] No source code changes outside `openspec/changes/bookkeeping-cbs-bestanden-extended/` and implementation files in standard locations (`lib/`, `tests/`, `docs/`, `l10n/`)

## Open Questions for Implementation

1. **Account Mapping Configuration UI** — should the account → CBS line mapping be editable via admin settings UI, or only via register file/API?
2. **XML Export Format** — should IV3 XML transformation be included in this change, or deferred to a follow-up spec?
3. **CBS Portal Integration** — are there plans for automated submission to CBS portal, or is download + manual upload the final pattern?
4. **Multi-Organization Consolidation** — should a future spec support consolidated submissions across multiple Shillinq entities, or keep single-entity only?
5. **Historical Submissions** — should operators be able to resubmit a previous period (generating new submission number), or edit an existing one?

## Dependencies & Sequencing

- **Blocks:** None — this is an independent feature extending T3-T4 bookkeeping
- **Blocked by:** None — depends on T1-T2, which are already shipped
- **Related changes:** `financial-reporting-accountability` (T2), `cost-accounting-allocation` (T4-advanced) — future specs may build on CBS data

## Handoff Notes

After spec approval:
1. Implementation lands via separate `opsx-apply` cycle on this spec
2. Generated code follows ADR-003 (controller → service → mapper), ADR-022 (reuse OpenRegister), ADR-031 (declarative configuration)
3. CI gates enforce: `composer test`, `openspec validate`, manifest validation, all tests passing
4. Merged to `main` branch; tag release with version bump
