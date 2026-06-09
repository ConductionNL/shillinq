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

- [x] Task 3.1: Update `openspec/architecture/adr-000-data-model.md` — added CBSSubmission (schema:GovernmentService) and CBSLine (schema:MonetaryAmount) entries inserted alphabetically between CallOffOrder and CashAccount with required fields, lifecycle, relations, and reconciliation notes pointing to bookkeeping-cbs-bestanden-extended (REQ-CBS-001 through REQ-CBS-010). Header entity count bumped 246 → 248.

## 4. Export Service Implementation — `lib/Service/CBSExportService.php`

- [x] Task 4.1: Author `CBSExportService::generateSubmission()` (`lib/Service/CBSExportService.php`) — implements the eight-step pipeline per REQ-CBS-004: loads active Account records (loadAccounts), loads posted GL transactions + child GLLines for the period (loadGlLines), resolves per-administration mapping (getMappingFromSettings → DEFAULT_MAPPING fallback), aggregates absolute integer-cent amounts by CBS classification (aggregateLines), persists a CBSSubmission header in draft state and one CBSLine per non-zero classification via the real OR ObjectService API (`saveObject` named-arg form per OR-API memory), generates the IV3 JSON envelope, stores its URI + SHA-256 checksum on the submission, returns the persisted submission. Money is integer-cent throughout (REQ-CBS-002 / MEMORY).
- [x] Task 4.2: Author `CBSExportService::validateSubmission()` per REQ-CBS-008 — checks kvkNumber + taxIdentificationNumber regex (completeness), missing CBSLine structural error, account-range conflicts across classifications (accounting), and reporting period field presence; returns `['valid'=>bool,'errors'=>string[],'warnings'=>string[]]`. Critical errors block the validate transition.
- [x] Task 4.3: Author `CBSExportService::generateIV3Json()` per REQ-CBS-006 — produces the iv3-extended envelope with `format`/`version`/`generatedAt` (UTC ISO-8601 Z) + submission metadata + per-line items (`classification`, `lineNumber`, `accountRange`, `amount`, `currency`) + a SHA-256 `checksum` computed over the canonical JSON.
- [x] Task 4.4: Author `CBSExportService::getMappingFromSettings()` per REQ-CBS-005 — reads `cbs_account_mapping_<administrationId>` from `IAppConfig` (JSON-encoded mapping list), validates entries (each must declare start/end/classification/lineNumber), falls back to the canonical RGS 4xxx-9xxx `DEFAULT_MAPPING` when unset or invalid (logs a warning). No exceptions thrown — operators get a sensible default.

## 5. API Controller — `lib/Controller/CBSSubmissionController.php`

- [x] Task 5.1: Author `CBSSubmissionController` (`lib/Controller/CBSSubmissionController.php`) — six RESTful actions wired in `appinfo/routes.php` (static segments before `{id}` wildcards per Symfony ordering):
  - `GET /api/cbs-submissions` — `index()` lists submissions, filterable by `status` and `administrationId`
  - `GET /api/cbs-submissions/{id}` — `show()` returns `{ submission, lines }`
  - `POST /api/cbs-submissions` — `create()` requires the 6 REQ-CBS-001 fields, returns 201
  - `PUT /api/cbs-submissions/{id}` — `update()` runs `CBSExportService::validateSubmission()` on `status=validated`, returns 422 + `errors[]` on failure
  - `DELETE /api/cbs-submissions/{id}` — `destroy()` blocks non-draft with 409 (audit-trail integrity)
  - `POST /api/cbs-submissions/{id}/generate` — `generate()` runs `CBSExportService::generateSubmission()` for the stored period + organization

- [x] Task 5.2: Controller methods are thin per ADR-003 — each action delegates to `CBSExportService` or `ObjectService` via the `run()` helper that maps Throwable → 500 without leaking stack traces. Validation: `validateId()` enforces the slug pattern (400), `requireFields()` enforces required body fields (400), every action calls `requireUser()` (401) before delegating. All error responses carry `message` per ADR-002. Auth posture is explicit (`#[NoAdminRequired]` attribute + in-body `requireUser()` guard) so gate-7 / gate-9 see the same posture in attribute + code.

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
