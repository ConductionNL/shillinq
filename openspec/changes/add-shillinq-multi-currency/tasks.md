# Tasks — Multi-Currency

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-multi-currency` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [ ] Task 1: Confirm no `FxRate` schema and no `bookkeeping-multi-currency` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`); cross-check T1 `bookkeeping-general-ledger` REQ-GL-003 unchanged so the MODIFIED block's "previously" wording stays accurate
- [ ] Task 2: Author `specs/bookkeeping-multi-currency/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-general-ledger (T1)` header — preserving the `## MODIFIED Requirements` block (supersedes T1 REQ-GL-003), the `## RENAMED Requirements` block (`GLLine.currency` → `transactionCurrency`), and the `## ADDED Requirements` block (`REQ-MC-001` through `REQ-MC-006`)
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (rounding, T2/T3 caller rename, ECB source availability) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Decisions (additive `GLLine` extension, FX orientation contract, scheduled-workflow not service, declarative realised gain/loss, `Mapping` for IAS 21) and Reuse Analysis table per hydra `rules.design`
- [ ] Task 5: Declare the `FxRate` schema in `lib/Settings/shillinq_register.json` with all REQ-MC-002 fields (transactionCurrency, baseCurrency, date, rate, source, manualOverrideReason, administrationId), schema.org annotation `schema:ExchangeRateSpecification`, uniqueness constraint on (transactionCurrency, baseCurrency, date, source), and `manualOverrideReason` precondition when `source = manual`
- [ ] Task 6: Additively patch the T1 `GLLine` schema with `baseCurrencyAmount`, `transactionCurrency` (renamed from `currency`), `baseCurrency`, `fxRate`, `fxRateSource`, `fxRateDate` fields per the MODIFIED REQ-GL-003; preserve on-the-wire `amount` property name (semantic shift only); default `fxRate = 1.0` when `transactionCurrency = baseCurrency`
- [ ] Task 7: Register an OR `ScheduledWorkflow` for daily ECB rate ingestion (calls openconnector ECB source by slug, inverts each published rate per REQ-MC-002 orientation contract, upserts `FxRate` records with `source: ecb`, emits CloudEvent on completion) per REQ-MC-003 + ADR-031 path 2; no `FxRateImportJob extends TimedJob`
- [ ] Task 8: Register an OR `ScheduledWorkflow` for period-end revaluation triggered on period close (reads each open foreign-currency position, emits balanced `GLTransaction` per position posting unrealised gain/loss) per REQ-MC-004; add `x-openregister-lifecycle` action on T2 sub-ledger records for realised gain/loss on settlement
- [ ] Task 9: Declare the IAS 21 consolidation translation as an OR `Mapping` record referencing the `FxRate` register per REQ-MC-005 + ADR-022; no `ConsolidationTranslationService` PHP class
- [ ] Task 10: Add FX Rates navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > FX Rates`, `type: index` page binding to `FxRate` with filter chips for `transactionCurrency`, `baseCurrency`, `source`, `type: detail` page) per REQ-MC-006; `node tests/validate-manifest.js` exits 0
- [ ] Task 11: Update `openspec/architecture/adr-000-data-model.md` with reconciliation notes — introduce `FxRate`; note the additive multi-currency extension on `GLLine` and the `currency` → `transactionCurrency` rename; cite the MODIFIED REQ-GL-003 in `bookkeeping-multi-currency/spec.md` as the new authoritative `GLLine` field contract

## Verification

`openspec validate` must exit clean on the change folder, including the `## MODIFIED Requirements` and `## RENAMED Requirements` blocks. Bookkeeper-persona peer review confirms the FX orientation contract is unambiguous (a USD line in a EUR administration on a day when 1 USD = 0.9259 EUR stores `fxRate: 0.9259`, joins to `FxRate.rate: 0.9259`, no reciprocation). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (ECB via openconnector; scheduled workflows not TimedJobs; IAS 21 via `Mapping`; manifest carries navigation; no `FxRevaluationService` / `ConsolidationTranslationService`). No source code changes outside `openspec/changes/add-shillinq-multi-currency/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting single-currency posting `fxRate=1`, foreign-currency posting converts correctly with no reciprocation, rounding edge cases (€ 0.005 banker's rounding), duplicate `FxRate` rejected, manual rate without reason rejected, unrealised loss posted on period close, realised gain posted on settlement, IAS 21 CTA computed correctly (pre-declared on Tasks 5–9); Playwright MCP browser tests for the FX Rates index + detail pages (Task 10); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/multi-currency.md` per ADR-030 journeydoc convention and commits an FX Rates index screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `FX Rate`, `Wisselkoers`, `ECB`, `Manual rate`, `Base Currency`, `Transaction Currency`, `Revaluation`, `Realised gain`, `Realised loss`, `Translation`.
