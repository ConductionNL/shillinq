# Tasks — BCF VAT Compensation

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-bcf-vat-compensation`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `BcfClaim` schema and no `bookkeeping-bcf-vat-compensation` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; confirm `digikoppeling-bcf` source not yet registered in openconnector)
- [x] Task 2: Author `specs/bookkeeping-bcf-vat-compensation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-vat-btw-filing (T3), bookkeeping-bbv-compliance (T3)` header, `REQ-BCF-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis and Declarative-vs-imperative decision tables; document D2 decision that flagging lives on `BbvAccountMapping`, not a parallel register
- [x] Task 5: Declare the `BcfClaim` schema in `lib/Settings/shillinq_register.json` with all REQ-BCF-002 fields (claimQuarter, totalCompensableAmount, breakdown, state, submittedOn, settledOn, attachmentUri, administrationId)
- [x] Task 6: Add `x-openregister-lifecycle` to `BcfClaim` declaring `draft → submitted → accepted → settled` transitions with arithmetic + approval-workflow preconditions on `draft → submitted` per REQ-BCF-006
- [x] Task 7: Extend `BbvAccountMapping` (from sibling `add-shillinq-bbv-compliance`) with `bcfCompensable: boolean` (default false) and `compensablePercentage: int 0-100` (default 100) per REQ-BCF-005
- [x] Task 8: Declare `BcfClaim.breakdown` as a derived field via `x-openregister-aggregations` (sum-by-account projection over T1 `GLLine` filtered by quarterly `periodId`, joining `BbvAccountMapping.bcfCompensable=true`, weighted by `compensablePercentage`) per REQ-BCF-004
- [x] Task 9: Declare the quarterly DigiKoppeling-BCF submission as an OR `ScheduledWorkflow` (cron quarterly) consuming `digikoppeling-bcf` per REQ-BCF-007
- [x] Task 10: Declare the `accepted → settled` transition as triggered by the OpenConnector `digikoppeling-bcf` webhook handler per REQ-BCF-006; no app-local glue
- [x] Task 11: Extend the repair step under `lib/Migration/` to register the BCF `ScheduledWorkflow`; idempotent on re-run
- [x] Task 12: Add `Overheid > BCF-claims` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate scoped to municipal admin types per REQ-BCF-008; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with the new `BcfClaim` entity and its `Primary spec:` reference

## Verification

`openspec validate` must exit clean on the change folder. Municipal-controller-persona peer review confirms the BCF claim shape matches Belastingdienst handreiking guidance and the compensable-percentage weighting produces accurate quarterly totals on a seeded fixture. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-019 compliance (compensable-flagging lives on `BbvAccountMapping`, not a parallel register; no app-local audit/state-machine/HTTP client; BCF via OpenConnector). No source code changes outside `openspec/changes/add-shillinq-bcf-vat-compensation/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering lifecycle transitions, compensable-VAT aggregation including only flagged accounts at correct percentages on a seeded GL+mapping fixture, settlement-webhook routing; integration test against an OpenConnector mock for `digikoppeling-bcf`; Playwright MCP browser tests for the BCF-claims index/detail pages; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/bcf-vat-compensation.md` per ADR-030 journeydoc convention and commits a BCF-claim drill-down screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `BCF-claim`, `Btw-compensatiefonds`, `Compensabele BTW`, `Compensabel percentage`, `Ingediend`, `Geaccepteerd`, `Afgewikkeld`.
