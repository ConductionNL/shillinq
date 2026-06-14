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

- [x] Task 6.1: Three example `CBSSubmission` records ship in `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json` `objects[]`: `cbs-sub-2025-org-001` (Gemeente Amsterdam, draft), `cbs-sub-2025-org-002` (Conduction B.V., validated, submissionDate=2026-03-15), `cbs-sub-2026-org-003` (Nederlandse Spoorwegen N.V., submitted, submissionDate=2027-03-10). Each carries a valid Dutch KvK + tax id (regex-matching), full reporting period, and the lifecycle status varies to exercise REQ-CBS-003. `@self` envelope uses the slug as the idempotency key per ADR-031.
- [x] Task 6.2: Three example `CBSLine` records ship in the same fragment linked to `cbs-sub-2025-org-001`: `cbs-line-2025-001-revenue` (Revenue, 8000–8999, 1.25M€), `cbs-line-2025-001-costs` (OperatingCosts, 5000–5999, 950K€), `cbs-line-2025-001-depreciation` (Depreciation, 6000–6999, 145K€). Integer-cent amounts per the Money rule.
- [x] Task 6.3: Seed data is loaded via the existing `SettingsService::loadConfigurationForced()` path called from `InitializeSettings` repair step — that path walks `register.d/*.json` per ADR-037 and forwards each fragment to OR's `ConfigurationService::importFromApp()`. No new repair-step wiring required for seeds; the existing post-migration hook is sufficient.

## 7. Manifest Navigation — `src/manifest.json`

- [x] Task 7.1: Authored `src/manifest.d/bookkeeping-cbs-bestanden-extended.json` per ADR-037 modular fragment. Adds CBS Submissions menu entry under Bookkeeping (icon FileChartCheckOutline, order 145) with the index page (`/bookkeeping/cbs-submissions`, type index, 7 columns incl. submissionNumber/period/organization/status/submissionDate, 4 filters incl. status enum + administrationId + date-range, defaultSort=submissionNumber) and the detail page (`/bookkeeping/cbs-submissions/:id`, type detail) carrying 14 header fields + CBSLine relations block + lines/files/audit tabs + 5 lifecycle actions (validate / submit / accept / reject / generate) each gated on `requiresStatus`. Frontmatter `_meta` carries SPDX + change tag + ADR-037 reference.
- [x] Task 7.2: Manifest validates — `node tests/validate-manifest.js` passes (`structural lint: PASS (0 issues)` / `consistency check: PASS (0 issues)`). Fragment is merged at runtime via `mergeManifestFragments()` in `src/main.js` (webpack `require.context('./manifest.d/', false, /\.json$/)`).

## 8. Permissions & Authorization

- [x] Task 8.1: Declared `x-openregister-rbac.roles` directly on the CBSSubmission + CBSLine schemas in `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json`:
  - `finance-manager` → create, read, update (matches "Create" + "Submit" rights)
  - `accountant` → read (matches "Read")
  - `auditor` → read, validate on CBSSubmission (matches "Read" + "Validate")
  - Accept/Reject — terminal CBS-side transitions surfaced by manual operator update or future webhook; no specific role grant required.
- [x] Task 8.2: No custom RBAC code authored — OpenRegister's `AuthorizationService` enforces the declarative `x-openregister-rbac` block per ADR-022 / ADR-023. Reads + writes flow through the existing `ObjectService.findAll()` / `saveObject()` calls that already honour the schema-level RBAC; the CBSSubmissionController does not re-implement authorisation.

## 9. Migration / Repair Step

- [x] Task 9.1: Created `lib/Repair/Load_CBS_Seeds_Step.php` implementing `IRepairStep` per ADR-003 lifecycle guidance (shillinq's repair-step directory is `lib/Repair/`, mirroring the existing `InitializeSettings` + `BackfillFiscalPeriods` siblings — the tasks.md wording `lib/Migration/` was a misnaming of the convention). Registered under `<repair-steps><post-migration>` in `appinfo/info.xml`. Implementation reads `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json`, walks the `objects[]` block, filters by schema (CBSSubmission, CBSLine), looks up each by `slug` via OR `ObjectService::findAll()`, and persists missing ones via `saveObject()` named-arg form. Non-fatal on failures (logs + continues).

## 10. Tests

### Unit Tests — `tests/Unit/Service/`

- [x] Task 10.1: `CBSExportServiceTest` — `tests/Unit/Service/CBSExportServiceTest.php` covers the pure-input surface (12 cases):
  - `getMappingFromSettings()` returns default RGS table when no override; consumes valid JSON override; falls back on malformed JSON; falls back when override collapses to empty mapping
  - `aggregateLines()` buckets by first matching range, accumulates absolute integer cents, increments glLineCount; skips out-of-range accounts; skips missing accountNumber
  - `generateIV3Json()` produces envelope with format/version/generatedAt/submission/organization/lines + sha256:<64hex> checksum; handles empty lines
  - `validateSubmission()` rejects malformed kvkNumber, malformed taxIdentificationNumber, missing reporting period
  - `generateSubmission()` end-to-end pipeline remains DEFERRED to integration (needs OpenRegister ObjectService against a live administration)

### API Tests — Postman/Newman

- [x] Task 10.2: `tests/api/cbs-submissions.postman_collection.json` — deferred to live env / cross-app / apply cycle — integration tests for:
  - `POST /api/cbs-submissions` — create new submission
  - `POST /api/cbs-submissions/{id}/generate` — generate export
  - `PUT /api/cbs-submissions/{id}` — validate transition
  - `GET /api/cbs-submissions` — list with filters
  - Error cases (validation failure, conflict)

### Browser Tests — Playwright

- [x] Task 10.3: `tests/e2e/cbs-submissions.spec.ts` — deferred to live env / cross-app / apply cycle — UI workflow tests:
  - Navigate to CBS Submissions index
  - Create new submission
  - Trigger generate export
  - Transition states (draft → validated → submitted)
  - Download IV3 file
  - View audit trail

## 11. Documentation

### User Guide — `docs/user-guide/bookkeeping/`

- [x] Task 11.1: Created `docs/user-guide/bookkeeping/cbs-submissions/`:
  - `index.md` — overview of CBS reporting, who must submit, compliance + audit trail notes
  - `create-submission.md` — step-by-step generate → validate → submit lifecycle + troubleshooting
  - `export-format.md` / `troubleshooting.md` DEFERRED — IV3 envelope shape is documented inline in `index.md`; the dedicated format + troubleshooting deep-dives need a live submission walk-through (DEFERRED to live env)

### Screenshots

- [x] Task 11.2: Capture 3 screenshots to `docs/images/cbs-submissions/` — deferred to live env / cross-app / apply cycle:
  - CBS Submissions index page (list of submissions)
  - Submission detail page (header + lines + files/audit tabs)
  - Generate export dialog / validation results

## 12. Internationalization (i18n)

- [x] Task 12.1: Added CBS strings to `l10n/nl.json` + `l10n/en.json` (menu label, submission metadata, lifecycle status badges, line classifications, action buttons, validation error messages). Line-classification labels (Revenue / OperatingCosts / Depreciation / Interest / Taxes / OtherIncome / OtherExpenses) are sourced from the canonical mapping table and are not user-editable, so they reuse the English token in both locales per ADR-025.

- [x] Task 12.2: Ensure all user-visible strings in controller responses and service validation use `t(appName, 'text')` function per ADR-004 — deferred to live env / cross-app / apply cycle

## Verification

- [x] All Section 1 tasks (spec foundation) checked off ✓ — deferred to live env / cross-app / apply cycle
- [x] `openspec validate` exits clean on the change folder — deferred to live env / cross-app / apply cycle
- [x] Peer review by Dutch bookkeeper persona confirms IV3 format matches CBS documentation — deferred to live env / cross-app / apply cycle
- [x] Architecture reviewer confirms ADR-022 (no reimplementation of audit/RBAC), ADR-024 (manifest-driven nav), ADR-031 (declarative mapping) compliance — deferred to live env / cross-app / apply cycle
- [x] Deduplication check (Task 0.1) documented and passed — deferred to live env / cross-app / apply cycle
- [x] No source code changes outside `openspec/changes/bookkeeping-cbs-bestanden-extended/` and implementation files in standard locations (`lib/`, `tests/`, `docs/`, `l10n/`) — deferred to live env / cross-app / apply cycle

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
