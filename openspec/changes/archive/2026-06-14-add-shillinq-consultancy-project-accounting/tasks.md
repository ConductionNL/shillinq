# Tasks — Consultancy Project Accounting

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-consultancy-project-accounting`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `Project`/`ProjectAssignment`/`RateCard`/`WipBalance` schema and no `bookkeeping-consultancy-project-accounting` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-consultancy-project-accounting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-receivable-core (T2)` header, `REQ-CPA-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document D2 (recognition as `calculations` not service) and D3 (rate-at-write snapshot per RJ 270 §3.2.4)
- [x] Task 5: Declare the `Project` schema in `lib/Settings/shillinq_register.json` with all REQ-CPA-002 fields (projectNumber, customerId, name, state, totalContractValue, totalEstimatedCosts, costsIncurredToDate, recognisedRevenue, currency, startDate, endDate, administrationId)
- [x] Task 6: Add `x-openregister-lifecycle` to `Project` declaring `offerte → active → on-hold → closed → archived` transitions per REQ-CPA-003; `closed` triggers final WIP + recognition adjustment
- [x] Task 7: Declare `ProjectAssignment` and `RateCard` schemas per REQ-CPA-004/005; `RateCard` carries `effectiveFrom`/`effectiveTo`
- [x] Task 8: Extend T3 `UrenRegistratie` (from sibling `add-shillinq-zzp-tax-regime`) with `recognisedRate: decimal` (snapshotted at write, immutable) and `projectAssignmentId: string` (FK) per REQ-CPA-009 / RJ 270 §3.2.4
- [x] Task 9: Declare `Project.costsIncurredToDate` as `x-openregister-aggregations` summing T1 `GLLine` cost-account postings tagged to the project FK per REQ-CPA-006
- [x] Task 10: Declare `Project.recognisedRevenue` as `x-openregister-calculations` (`totalContractValue × costsIncurredToDate / totalEstimatedCosts`, cost-to-cost RJ 270 method) per REQ-CPA-007
- [x] Task 11: Declare `WipBalance` schema and the period-end WIP snapshot as an OR `ScheduledWorkflow` triggered by T2 `PeriodClosed` event per REQ-CPA-008
- [x] Task 12: Declare `ProjectAssignment.utilization` as `x-openregister-calculations` (`billableHoursThisPeriod / capacityHoursThisPeriod`, capacity operator-supplied, default 36-hour workweek) per REQ-CPA-010
- [x] Task 13: Declare `Project.profitAndLoss` as `x-openregister-aggregations` filtering T1 `GLLine` by project FK (revenue minus costs over the project's lifetime) per REQ-CPA-011
- [x] Task 14: Ship `lib/Settings/seeds/rj-270-stages.json` (4 canonical stages: initiation, execution, closeout, complete) + `lib/Settings/seeds/rate-card-templates.json` (junior/medior/senior/partner) with SPDX headers + `_meta.source: "RJ 270 §3 + IFRS 15 §B14-B19"` per REQ-CPA-002 / REQ-CPA-005
- [x] Task 15: Extend the repair step under `lib/Repair/` to import both seeds and register the WIP snapshot `ScheduledWorkflow`; idempotent on re-run
- [x] Task 16: Add `Projecten > Overzicht`, `> Tarieven`, `> Utilisatie` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate for `consultancy`-flagged admins per REQ-CPA-012; `node tests/validate-manifest.js` exits 0
- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with the 4 new entities (`Project`, `ProjectAssignment`, `RateCard`, `WipBalance`) and the `UrenRegistratie` field extension (`recognisedRate`, `projectAssignmentId`) with their `Primary spec:` references

## Verification

`openspec validate` must exit clean on the change folder. Project-administrator-persona peer review confirms RJ 270 / IFRS 15 recognition formula, rate-at-write snapshot per §3.2.4, WIP snapshot cadence, utilisation derivation, and project P&L aggregation match accounting standards. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative calculations; no `RevenueRecognitionService` / `ProjectPlService`; WIP via `ScheduledWorkflow` not `*Job`; `BillableHour.recognisedRate` immutable at write). No source code changes outside `openspec/changes/add-shillinq-consultancy-project-accounting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering percentage-of-completion calculation correctness on seeded fixture, rate-card snapshot honours work date not invoice date, WIP snapshot fires on period close, utilisation derivation correctness across capacity edge cases; Playwright MCP browser tests for the 3 new index/detail pages; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/consultancy-project-accounting.md` per ADR-030 journeydoc convention and commits a projecten overview screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Project`, `Projectopdracht`, `Tarievenkaart`, `WIP`, `Onderhanden werk`, `Utilisatie`, `Percentage-of-completion`, `Omzetverantwoording`, `Junior`, `Medior`, `Senior`, `Partner`, `Capaciteit`, `Declarabele uren`.
