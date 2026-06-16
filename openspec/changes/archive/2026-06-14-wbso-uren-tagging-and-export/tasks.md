# Tasks — WBSO Hours Tagging & RVO Export

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-wbso-hours-tagging-and-export` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-wbso-hours-tagging-and-export` capability spec already exists, no `WBSOTag`/`WBSOActivityCode` schemas are declared, no `TimeEntry.wbsoTagId`/`activityCodeId` fields exist, and no `lib/Service/WBSO*` / `lib/Service/Export*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "enables WBSO subsidy compliance for Dutch R&D teams" and is unique to Shillinq in the SMB market

- [x] Task 2: Confirm dependency on `billable-categories-and-tags` is available and `Project` schema carries `wbsoTagId` + `activityCodeId` fields; if not, file a blocker issue and defer Task 3+ pending implementation

- [x] Task 3: Author `specs/bookkeeping-wbso-hours-tagging-and-export/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: billable-categories-and-tags, add-shillinq-general-ledger` header; `REQ-WBSO-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-024 + ADR-031 inline

- [x] Task 4: Author `proposal.md` referencing shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (RVO format change mid-year, WBSO code taxonomy stability, auto-tagging over-assignment) / Rollback / Open Questions (RVO API credentials, admin activity-code customization, multi-year portfolio scope)

- [x] Task 5: Author `design.md` with Reuse Analysis table, D1 (WBSO tags are first-class registers), D2 (auto-tagging via aggregation), D3 (TimeEntry lifecycle extends to tag-aware states), D4 (admin-configurable activity codes), D5 (export is lifecycle workflow not batch job), D6 (RVO format declared not computed); include baseline seed data for SO/TWO/SMART codes and A/B activity codes

- [x] Task 6: Declare the `WBSOTag` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-002 fields (`wbsoCode`, `displayName`, `description`, `rvoCertificationUrl`, `administrationId`, `lifecycleState`); baseline codes SO/TWO/SMART pre-populated per WBSO legislation

- [x] Task 7: Declare the `WBSOActivityCode` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-003 fields (`activityCode`, `description`, `category`, `isAllowed`, `parentActivityCode`, `administrationId`, `lifecycleState`); baseline A001–A999 and B001 codes pre-populated with Dutch descriptions per RVO guidelines

- [x] Task 8: Extend `TimeEntry` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-004 fields (`wbsoTagId`, `activityCodeId`, `wbsoTaggedAt`, `tagSource`); ensure both FKs are null-safe (optional)

- [x] Task 9: Implement TimeEntry auto-tagging precondition per REQ-WBSO-004 — `x-openregister-aggregations` rule: IF parent Project.wbsoTagId + Project.activityCodeId both non-null THEN auto-assign to TimeEntry + set `tagSource: "auto"` ELSE entry enters `untagged` state + operator warning required; document exception path if OR aggregations not yet fully capable per ADR-031

- [x] Task 10: Declare the `WBSOExportLog` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-005 fields (`exportId`, `periodStart`, `periodEnd`, `exportFormat`, `status`, `recordCount`, `totalHours`, `totalHoursIneligible`, `generatedAt`, `validatedAt`, `validationErrors`, `submittedAt`, `fileUri`, `administrationId`)

- [x] Task 11: Add `x-openregister-lifecycle` to `WBSOExportLog` declaring every transition in REQ-WBSO-006 (`draft → generated → validated → submitted → archived`, with `generated → rejected` fallback); validation guards: all TimeEntry records must have non-null `wbsoTagId` + `activityCodeId`, aggregation predicate on `generated → validated`

- [x] Task 12: Implement RVO validation logic on `generated → validated` transition per REQ-WBSO-006 — check all included entries carry both tags (aggregation precondition); check `isAllowed` matches entry scope; if XML format, verify checksum; populate `validationErrors` array on failure; not a PHP service (aggregation + lifecycle guards only)

- [x] Task 13: Declare export format schemas (CSV, PDF, XML) as metadata per REQ-WBSO-007; document field names, column order, section structure, element names matching RVO submission requirements dated 2026-05-21; do NOT compute or emit the files (T4 responsibility)

- [x] Task 14: Implement WBSO-tagged `TimeEntry` query filters per REQ-WBSO-008 — support filtering by `wbsoTagId`, `activityCodeId`, `isAllowed`, employee, date range; filters applied on export generation; test with multi-filter combinations

- [x] Task 15: Document administration-configurable activity codes per REQ-WBSO-009 — support creation of custom code variants with `parentActivityCode` FK; custom codes override description per subsidy agreement but inherit `isAllowed` status from parent unless explicitly overridden; admin UI for code management

- [x] Task 16: Add 2 manifest navigation entries (`WBSO Tags Dashboard`, `WBSO Export Dashboard`) + their `type: index` / `type: detail` / `type: generator` pages to `src/manifest.json` per REQ-WBSO-010; WBSO Tags page includes bulk re-tagging UI; Export page includes date range selector, format picker, filter panel, past-export list with download/submit actions; `node tests/validate-manifest.js` exits 0

- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with `WBSOTag`/`WBSOActivityCode`/`WBSOExportLog` entries; reconcile against any existing `Tag`, `Category`, `ActivityCode`, or `ExportLog` entries; add relations for `TimeEntry → WBSOTag/WBSOActivityCode`, `WBSOExportLog → TimeEntry` (one-to-many)

- [x] Task 18: Add Dutch (`nl_NL`) + English (`en_US`) i18n strings per ADR-007: "WBSO Tags", "Activity Code", "WBSO Code", "Stand-alone Project", "TechnoWise Open", "SME Collaboration", "Software development for R&D", "Testing", "Documentation", "Project overhead", "Eligible for subsidy", "Excluded from subsidy", "WBSO Export Dashboard", "Generate Export", "Validate for RVO", "Submit to RVO", "Export Status", "Validation Errors", "Tagged", "Untagged", "Auto-tagged", "Manually tagged"

## Verification

`openspec validate` must exit clean on the change folder.

Dutch WBSO compliance officer peer review (via `/test-persona-annemarie` or external auditor) confirms the export format matches RVO 2026-05-21 submission requirements (CSV columns, PDF sections, XML elements).

Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local export service; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries the navigation; aggregations drive auto-tagging).

Operator walkthrough: create a Project with WBSO code + activity code → create TimeEntry under that project → entry auto-tags → generate export → validate → submit → verify RVO format matches spec.

No source code changes outside `openspec/changes/wbso-uren-tagging-and-export/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

- PHPUnit unit tests:
  - TimeEntry auto-tagging on creation (success case + missing-metadata cases)
  - Export generation with filtering
  - RVO validation (all entries tagged, checksums, field presence)
  - Admin activity-code creation (baseline + custom variants)
  - Lifecycle transitions (draft → generated → validated → submitted)
  - Filter combinations (tag + code + eligibility)

- Playwright MCP browser tests:
  - WBSO Tags dashboard: list, add, archive, bulk re-tag
  - Export Dashboard: generate, validate, download, mark submitted
  - TimeEntry form: auto-tag display + manual override
  - Manifest navigation: both pages load, ACL respected

- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/wbso-uren.md` — WBSO subsidy eligibility, code taxonomy (SO/TWO/SMART), activity codes (A/B), auto-tagging workflow, export for RVO submission.
- `docs/guides/wbso-compliance-checklist.md` — pre-export validation, RVO format checklist, troubleshooting validation errors.
- Screenshots: WBSO Tags dashboard, TimeEntry auto-tag highlight, Export Dashboard with filters, validation-success confirmation.

Per ADR-030 journeydoc convention.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings (see Task 18 for full list).

## Data Migration

For existing Shillinq deployments with pre-existing `TimeEntry` records:

- No destructive changes: `wbsoTagId` + `activityCodeId` are nullable, backward-compatible.
- Operator action: bulk re-tag existing entries via WBSO Tags dashboard (filter by project, assign tags in batch).
- New entries: auto-tag on creation if project metadata present.
- Audit trail preserved for all tag assignments (manual + auto).
