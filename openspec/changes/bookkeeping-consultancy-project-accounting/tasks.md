# Tasks — Consultancy and Departmental Project Accounting

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against this spec — they are recorded
> now so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited by
> this change itself.

## Tasks

- [x] Task 1: Confirm no existing `CostProject`, `CostCenter`, or `ProjectBudget` schemas in `lib/Settings/shillinq_register.json`; reconcile with `adr-000-data-model.md` entries for `CostProject`, `CostCenter` (search for naming variants: Project, Department, Budget) — reconciled 2026-06-09: `CostCenter` already exists (T4 dimensions slice) + `Project` already exists (RJ 270 / IFRS 15 consultancy revenue-recognition variant); this change ADDS the analytical `CostProject` (management-accounting / budget-vs-actual view) + `ProjectBudget` (period allocation) + additive `CostCenter` budget fields. ADR-000 reconciliation note appended on Task 19.
- [x] Task 2: Author `bookkeeping-consultancy-project-accounting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (project accounting)` / `Depends on: bookkeeping-chart-of-accounts, bookkeeping-general-ledger` header, `REQ-CPA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (minimum 8 requirements covering project lifecycle, budget roll-up, project P&L, time entry integration, cost tracking, utilization) — delta spec at openspec/changes/.../specs/.../spec.md adds REQ-CPA-101..115 covering all 8 named domains.
- [x] Task 3: Declare the `CostProject` schema in `lib/Settings/shillinq_register.json` with all fields: projectNumber (string, required), name (string, required), description (string, optional), startDate (date, optional), endDate (date, optional), totalBudget (number, required), totalEstimatedCosts (number, required), costsIncurredToDate (number, calculated), administrationId (string, optional), lifecycleState (enum: draft/active/on-hold/closed/archived, required) — schema added (integer cents per ADR money rule) with organizationId + costCenterCode + 7-year retention.
- [x] Task 4: Declare the `CostCenter` schema with all fields: code (string, required, unique), name (string, required), description (string, optional), status (enum: active/inactive, required), budget (number, required), spentToDate (number, calculated), parentCode (string, optional, FK to self), organizationId (string, required) — CostCenter (already declared in T4 dimensions slice) additively extended with description / status (alias) / budget / spentToDate / allocatedBudget / organizationId; existing lifecycleState retained as authoritative state.
- [x] Task 5: Declare the `ProjectBudget` schema for period-level budget allocations: allocationNumber (string, required), amount (number, required), status (enum: pending/approved/allocated/spent, required), projectId (string, required, FK to CostProject), fiscalPeriod (string, required) — schema added with x-openregister-lifecycle pending → approved → allocated → spent and 7-year retention.
- [x] Task 6: Extend `TimeEntry` (or equivalent time-tracking register) with optional projectId (string, FK to CostProject) and taskId (string) fields for project-level time tracking per REQ-CPA-004 — UrenRegistratie already had projectId; added costProjectId (FK to CostProject) + taskId (operator-defined task tag).
- [x] Task 7: Add `x-openregister-lifecycle` block to `CostProject` declaring `draft → active` / `active → on-hold` / `on-hold → active` / `active → closed` / `closed → archived` transitions per REQ-CPA-002 — full state machine + transitions (activate / putOnHold / reactivate / close / archive) + retention block declared.
- [x] Task 8: Add `x-openregister-relations` self-relation on `CostCenter.parentCode → CostCenter.code` for hierarchical navigation per REQ-CPA-003 — already present on CostCenter (parentCostCenter relation declared by T4 dimensions slice); confirmed and reused.
- [x] Task 9: Add `x-openregister-aggregations` on `CostProject.costsIncurredToDate` summing GL lines tagged to this project from expense accounts per REQ-CPA-005 — added: sum amount where subLedgerType='cost-project' AND subLedgerRef=@self.id AND account.accountType='expenses'.
- [x] Task 10: Add `x-openregister-aggregations` on `CostCenter.spentToDate` summing GL lines tagged to this cost center per REQ-CPA-006 — added: sum amount where costCenterCode=@self.code AND side='debit', recursive over parentCode self-relation.
- [x] Task 11: Add `x-openregister-calculations` on `CostCenter.allocatedBudget` rolling up children's budgets (sum of child allocations + direct allocation) per REQ-CPA-007 — added: (@self.budget ?? 0) + sum(@children(parentCode).allocatedBudget ?? 0) recursive.
- [x] Task 12: Add `x-openregister-calculations` on `CostProject.profitAndLoss` filtering GL by project FK: sum(revenue accounts) − sum(expense accounts) per REQ-CPA-008 — added as x-openregister-aggregations.profitAndLoss (derived: revenue / expense / profitAndLoss=revenue-expense). Also added x-openregister-calculations.budgetVariance.
- [x] Task 13: Add `x-openregister-calculations` on time entries for utilization: `utilizationPercent = billableHoursThisPeriod / availableHoursThisPeriod` per REQ-CPA-009 — added on UrenRegistratie grouped by (personId, fiscalPeriod), clamped to [0,100].
- [x] Task 14: Ship `lib/Settings/seeds/project-templates.json` with 3 seed projects (service engagement, product development, internal optimization) including `@self` envelope, SPDX header, and `_meta` block with `source: "Consultancy defaults"` per REQ-CPA-010 — 3 CostProject seeds (CP-TEMPLATE-SVC-001, CP-TEMPLATE-PROD-001, CP-TEMPLATE-INT-001).
- [x] Task 15: Ship `lib/Settings/seeds/cost-center-templates.json` with hierarchical cost center structure (Sales, Engineering, Operations, Finance, Administration, and key sub-departments) including `@self` envelope, SPDX header, and `_meta` block per REQ-CPA-011 — 14 CostCenter seeds (5 top-level: CC-001..CC-005 + 9 sub-departments) with parentCode hierarchy.
- [x] Task 16: Extend the repair step under `lib/Migration/` to import both seed files idempotently (operator edits persist across re-runs; the repair step does not re-overwrite seeded records) per REQ-CPA-012 — added `seedConsultancyProjectAccountingTemplates()` (and helpers) to `lib/Repair/InitializeSettings.php`; dedupes on (projectNumber, administrationId) + (code, administrationId); skips on C2 (no admin id configured); php -l clean.
- [x] Task 17: Add Projects navigation + pages to `src/manifest.json` (menu entry `Projects > Overview`, `type: index` page binding to `CostProject` register, `type: detail` page for individual projects) per REQ-CPA-013; `node tests/validate-manifest.js` exits 0 — added CostProjects index + CostProjectDetail (binding to `CostProject` register, columns: projectNumber/name/totalBudget/costsIncurredToDate/lifecycleState, related: ProjectBudget allocations, profitAndLoss aggregation, lifecycleActions enabled); plus existing Projects (Project register) untouched. Manifest validator: 0 issues, 197 pages. Bumped manifest version 1.3.12→1.3.13 + app version 0.7.4→0.7.5.
- [x] Task 18: Add Cost Centers navigation + pages to `src/manifest.json` (menu entry `Cost Centers`, `type: index` page binding to `CostCenter` register, `type: detail` page for individual cost centers) per REQ-CPA-014 — Cost Centers nav and pages were already declared by the T4 dimensions slice (id `CostCenters` index + `CostCenterDetail`); confirmed present and consistent with the additively-extended CostCenter schema. No regression.
- [x] Task 19: Update `openspec/architecture/adr-000-data-model.md` with a reconciliation note confirming that this change realizes the `CostProject` and `CostCenter` entries already present in the ADR but not yet declared in registers — rewrote the `CostProject` entry on the bookkeeping-consultancy-project-accounting primary spec (integer cents, draft/active/on-hold/closed/archived lifecycle, derived costsIncurredToDate + profitAndLoss aggregations), appended an additive-extension note to the `CostCenter` entry covering the description/status/budget/spentToDate/allocatedBudget/organizationId additions, and added a new `CostProjectBudget` (a.k.a. ProjectBudget) entry.

## Verification

`openspec validate` must exit clean on the change folder. Finance/
project-manager persona peer review (e.g. `/test-persona-janwillem`)
confirms the schema shape matches real consultancy accounting with
hierarchical cost centers. Architecture reviewer confirms ADR-022 +
ADR-024 + ADR-031 compliance (no app-local aggregate service; no
service-class state machines; manifest carries the navigation). No
source code changes outside `openspec/changes/bookkeeping-consultancy-project-accounting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for:

- PHPUnit unit tests covering schema load + lifecycle transitions +
  hierarchical aggregation accuracy + seed file import + idempotent
  repair re-run (pre-declared on Tasks 3–19 above)
- Playwright MCP browser tests for the Projects index + detail pages
  (include cost tracking, timeline, P&L display)
- Playwright MCP browser tests for Cost Centers index + detail pages
  (include hierarchy visualization, budget allocation, variance display)
- `composer test` green at the implementing PR's CI gate
- No new REST endpoints (OR exposes register CRUD generically), so no
  Newman/Postman additions needed

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors:

- `docs/user-guide/bookkeeping/projects.md` covering project creation,
  budget setup, cost tracking, and P&L reporting per ADR-030 journeydoc
  convention
- `docs/user-guide/bookkeeping/cost-centers.md` covering departmental
  structure, hierarchy setup, and budget roll-up
- Commit screenshots of the Projects and Cost Centers index/detail
  pages to `docs/images/`

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Projects`, `Cost Centers`, `Project Number`, `Project Name`,
  `Budget`, `Estimated Costs`, `Costs to Date`, `Project P&L`,
  `Cost Center`, `Cost Center Code`, `Department`, `Allocated Budget`,
  `Spent to Date`, `Variance`, `Utilization`, `Time Entry`, `Project`
  (on time entry), `Task` (on time entry), and lifecycle states
  (`Draft`, `Active`, `On Hold`, `Closed`, `Archived`)

## Reuse Analysis (Deduplication Check)

This change does not duplicate existing functionality:

- Time tracking (`TimeEntry` / `UrenRegistratie`) exists but lacks
  project tagging; this change extends it (non-breaking additive fields)
- Project cost tracking exists implicitly via GL filtering; this change
  formalizes it as calculated fields per ADR-031 (not a new service)
- Budget aggregation already exists in some spreadsheet workflows;
  this change brings it into the system as declarative aggregation
  per ADR-031 (not a new service)
- Cost center hierarchy is common in accounting systems; this change
  declares it as schema + relation per ADR-031 (not a new service)

No scope overlap with existing OpenRegister services. Architecture review
required to confirm the aggregation declarations (GL filtering logic)
align with OR's standard projection patterns.

## Seed Data Generation

This task is included in the `opsx-apply` cycle but is explicitly
tracked:

- Generate realistic example `project-templates.json` with 3–5 projects
  covering service, product, and internal work types; use Dutch
  organization names (e.g., "Onderhoud Informatiesystemen")
- Generate `cost-center-templates.json` with a realistic Dutch SMB
  structure (Sales, Engineering/Development, Operations, Finance,
  Administration); include typical sub-departments; ensure codes follow
  a pattern (CC-001, CC-001-01, CC-001-02, CC-002, etc.) for hierarchy
  clarity
- Both files MUST include valid `@self` envelopes with unique slugs and
  `_meta` blocks with `source` attribution per the seed-data pattern
