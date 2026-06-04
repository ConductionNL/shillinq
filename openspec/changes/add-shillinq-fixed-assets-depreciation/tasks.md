# Tasks — Fixed Assets & Depreciation

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-fixed-assets-depreciation` spec — they are recorded now
> so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited
> by this change itself.

## Tasks

- [x] Task 1: Confirm no `FixedAsset` schema or `bookkeeping-fixed-assets-depreciation` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-fixed-assets-depreciation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-FA-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Decisions (declarative state machine, derived-field depreciation, parallel commercial/fiscal streams, `ScheduledWorkflow` for monthly run) and Reuse Analysis table per hydra `rules.design`
- [x] Task 5: Declare the `FixedAsset` schema in `lib/Settings/shillinq_register.json` with all REQ-FA-002 fields (assetNumber, name, assetCategory, acquisitionDate, acquisitionCost, currency, usefulLifeMonths, residualValue, depreciationMethod, degressiveRate, commercialRate, fiscalRate, assetAccountNumber, accumulatedDepAccountNumber, depreciationExpenseAccountNumber, disposalDate, disposalAccountingTreatment, lifecycleState, administrationId)
- [x] Task 6: Add `x-openregister-calculations` to `FixedAsset` declaring `monthlyDepreciation`, `currentBookValue`, `commercialBookValue`, `fiscalBookValue` as derived fields per REQ-FA-003 + REQ-FA-004
- [x] Task 7: Add `x-openregister-lifecycle` to `FixedAsset` declaring `proposed → active → disposed → archived` transitions; the `active → disposed` action emits a closing T1 `JournalEntry` per REQ-FA-006
- [x] Task 8: Register an OR `ScheduledWorkflow` for the monthly depreciation run (reads each active asset's `monthlyDepreciation`, emits a balanced `GLTransaction` per asset) per REQ-FA-005 + ADR-031 path 2; no `DepreciationJob extends TimedJob` PHP class
- [x] Task 9: Add Fixed Assets navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > Fixed Assets`, `type: index` page binding to `FixedAsset`, `type: detail` page surfacing acquisition/disposal actions) per REQ-FA-007; `node tests/validate-manifest.js` exits 0
- [x] Task 10: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note introducing `FixedAsset` and its references from `GLLine.subLedgerRef` when `subLedgerType = fixed-asset`

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB plus a domain-expert accountant) confirms the parallel commercial/fiscal stream behaviour matches real Wet IB / Wet VPB practice. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (calculated derived fields, no schedule table; declarative state machine; `ScheduledWorkflow` not TimedJob; manifest carries navigation). No source code changes outside `openspec/changes/add-shillinq-fixed-assets-depreciation/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting derived-field correctness over time, parallel stream divergence, disposal closing-journal emission (pre-declared on Tasks 5–8); Playwright MCP browser tests for the Fixed Assets index + detail pages (Task 9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/fixed-assets.md` per ADR-030 journeydoc convention and commits a fixed-asset detail-page screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Fixed Asset`, `Vast actief`, `Depreciation`, `Afschrijving`, `Commercial`, `Fiscal`, `Disposal`, `Book Value`, `Useful Life`, `Residual Value`.
