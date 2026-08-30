# Tasks — Cost Centers & Dimensions

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-cost-centers-dimensions` spec — they are recorded now
> so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited
> by this change itself.

## Tasks

- [x] Task 1: Confirm no `CostCenter` / `KostenDrager` / `Project` / `AllocationRule` schemas or `bookkeeping-cost-centers-dimensions` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-cost-centers-dimensions/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-CC-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (driver creep, cross-line balance on split, hierarchy depth) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Decisions (dimensions as registers, allocation rules as schema metadata, segment P&L as single-schema aggregation, WBSO pre-positioning) and Reuse Analysis table per hydra `rules.design`
- [x] Task 5: Declare the `CostCenter`, `KostenDrager`, `Project` schemas in `lib/Settings/shillinq_register.json` with REQ-CC-002 fields, `x-openregister-relations` self-relation for hierarchy, RBAC role definitions; add `timeBookingEnabled` flag on `Project` per REQ-CC-007 (WBSO pre-position)
- [x] Task 6: Declare the `AllocationRule` schema with REQ-CC-004 fields (sourcePattern, driver, targets, targetDimension, cadence) and four named drivers (`fixed-percentage`, `fixed-amount`, `volume`, `headcount`); `fixed-percentage` precondition that target percentages sum to 100 declared as `x-openregister-lifecycle.requires` on `AllocationRule.save`
- [x] Task 7: Additively patch the T1 `GLLine` schema with dimension fields (`costCenterCode`, `kostenDragerCode`, `projectCode`, free-form `dimensions` map) per REQ-CC-003; the `dimensions` map validates against registered custom dimension registers via OR relations engine
- [x] Task 8: Wire allocation-rule execution: `per-posting` rules via `x-openregister-lifecycle` action on `GLTransaction.post` (reuses T1 balance constraint on the split); `monthly` / `period-close` rules via OR `ScheduledWorkflow` per REQ-CC-004 + ADR-031 path 2
- [x] Task 9: Declare segment P&L roll-up as `x-openregister-aggregations` on `GLLine` keyed by dimension per REQ-CC-005; consumed by launchpad via runtime GraphQL and by manifest detail pages
- [x] Task 10: Ship allocation-rule example seeds under `lib/Settings/seeds/allocation-rules/` (overhead-by-headcount, it-by-volume, facility-by-fixed-percentage) shipped in `lifecycleState: paused` with SPDX header + `_meta` block per `feedback_spdx-in-docblock.md`
- [x] Task 11: Extend the repair step under `lib/Repair/InitializeSettings.php` to import allocation-rule example seeds idempotently per REQ-CC-004
- [x] Task 12: Add Dimensions navigation + pages to `src/manifest.json` (entries under `Bookkeeping > Dimensions` for `CostCenter`, `KostenDrager`, `Project`, `AllocationRule` with matching `type: index` + `type: detail` pages; custom dimension registers appear automatically with no PHP/Vue edits) per REQ-CC-006; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with one-paragraph reconciliation notes introducing `CostCenter`, `KostenDrager`, `Project`, `AllocationRule` and the additive dimension fields on `GLLine`

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms segment P&L roll-up matches real practice. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (dimensions as registers; allocation rules schema-declared; segment P&L single-schema aggregation; `ScheduledWorkflow` not TimedJob; manifest carries navigation; no `AllocationService` / `SegmentReportService` PHP class). No source code changes outside `openspec/changes/add-shillinq-cost-centers-dimensions/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting relation resolution, dimension key/value validation, allocation precondition (fixed-percentage sums to 100), per-posting rule splits transaction keeping balance, segment P&L aggregation rolls up children (pre-declared on Tasks 5–9); Playwright MCP browser tests for the Dimensions navigation + pages (Task 12); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/dimensions.md` per ADR-030 journeydoc convention and commits a dimension hierarchy + allocation rule screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Cost Center`, `Kostenplaats`, `Kostendrager`, `Project`, `Allocation Rule`, `Verdelingsregel`, `Driver`, `Headcount`, `Volume`, `Fixed percentage`, `Segment P&L`.
