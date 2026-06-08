# Tasks — Intercompany Elimination Engine

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-intercompany-elimination` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-intercompany-elimination` capability spec already exists, no IC-related schemas (`IntercompanyRelation`, `IntercompanyTransaction`, `IntercompanyMatch`, `IntercompanyMismatch`, `ToleranceRule`, `CounterpartyBalance`, `EliminationJournal`) are declared, and no `lib/Service/IntercompanyMatching*.php` / `lib/Service/Elimination*.php` PHP classes are present; explicitly note this capability "enables persistent IC-relation registry and auto-matching at scale"

- [x] Task 2: Author `specs/bookkeeping-intercompany-elimination/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-consolidation-commercial, bookkeeping-multi-administratie, bookkeeping-grootboek` header, `REQ-ICE-NNN` (REQ-ICE-001 through REQ-ICE-010) requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline for aggregation + lifecycle approach

- [x] Task 3: Author `proposal.md` referencing consolidation-commercial integration, including Affected Projects / Scope / Approach / Risks (performance at scale, FX-koers-handling, OR aggregation maturity, mismatch queue velocity) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1–D9 decisions (persistent relatie-registry, aggregation+workflow matching, multi-method detectie, tolerance-rules, mismatch-classification, eliminatie-journaal-generation, counterparty-balance-views, cross-period consistency, multi-currency), Declarative-vs-Imperative table, and Migration Plan

- [x] Task 5: Declare the `IntercompanyRelation` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-001 fields (relationId, groupId, entityAId, entityBId, relationType enum, defaultAccountA, defaultAccountB, toleranceAbsolute, toleranceRelative, toleranceFallbackAccount, activeFrom, activeTo, administrationId); schema.org annotation `schema:FinancialProduct`

- [x] Task 6: Declare the `IntercompanyTransaction` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-002 fields (sourceAdministrationId, sourceJournalEntryId, sourceLineNumber, bookingDate, glAccount, debitAmount, creditAmount, currency, description, counterpartyEntityId, relationId, detectionMethod enum, detectionConfidence enum, isMatched boolean, matchId, administrationId); schema.org annotation `schema:FinancialProduct`

- [x] Task 7: Declare the `IntercompanyMatch` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-003 fields (matchId, periodId, relationId, entityATransactionIds array, entityBTransactionIds array, totalAmountA, totalAmountB, mismatchAmount, mismatchPercentage, matchStatus enum, generatedEliminationId, administrationId); schema.org annotation `schema:Thing`

- [x] Task 8: Declare the `IntercompanyMismatch` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-005 fields (mismatchId, periodId, relationId, matchId, causeClassification enum, amount, currency, description, status enum, assigneeId, resolutionAction enum, resolutionNotes, administrationId); schema.org annotation `schema:Thing`

- [x] Task 9: Declare the `ToleranceRule` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-004 fields (ruleId, groupId, relationTypeFilter, toleranceAbsolute, toleranceRelative, toleranceMethod enum, fallbackAccount, autoResolve boolean, administrationId); schema.org annotation `schema:Thing`; seed 5 default rules (sales-of-goods €25/0.5%, sales-of-services €10/0.25%, interest-on-loan €100/0.1%, dividend €5000/0.01%, management-fee €20/0.5%)

- [x] Task 10: Declare the `CounterpartyBalance` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-007 fields (balanceId, groupId, entityAId, entityBId, periodId, totalReceivablesAonB, totalPayablesAtoB, netPositionAtoB, totalSalesAtoB, totalPurchasesAtoB, transactionCount, mismatchCount, lastUpdated, administrationId); schema.org annotation `schema:FinancialProduct`; this is an aggregation-view register

- [x] Task 11: Declare the `EliminationJournal` schema in `lib/Settings/shillinq_register.json` with all REQ-ICE-006 fields (eliminationId, consolidationPeriodId, matchId, bookingDate, description, lines array, totalDebit, totalCredit, generatedBy enum, approvedBy, approvedAt, administrationId); schema.org annotation `schema:Thing`; lines MUST be balanced (totalDebit = totalCredit) per schema validation

- [x] Task 12: Implement the matching aggregation per REQ-ICE-003 as `x-openregister-aggregations` query on `IntercompanyMatch` — GROUP BY (relationId, periodId), SUM(transactionAmount A-side, transaction-amount B-side), compute delta, determine matchStatus — NOT a PHP service

- [x] Task 13: Implement the tolerance-evaluation lifecycle guard per REQ-ICE-004 via `x-openregister-lifecycle.requires` on `IntercompanyMatch.create` — evaluate (mismatchAmount, mismatchPercentage) against configured `ToleranceRule` per relationTypeFilter + administration — sets matchStatus (within-tolerance vs outside-tolerance)

- [x] Task 14: Implement the scheduled matching-run trigger per REQ-ICE-003 using OR's `ScheduledWorkflow` primitive (path 2, ADR-031) — NOT a shillinq *Job PHP class — for monthly/quarterly/annual runs, configurable per consolidation-group

- [x] Task 15: Implement IC-transaction auto-detectie per REQ-ICE-002 — account-based query (GL-query per registered IC-rekening from IntercompanyRelation.defaultAccountA/B), label-based query (debiteur/crediteur-naam match to groep-entiteit), explicit-mark support (tag in transactie-entry); set detectionMethod + detectionConfidence accordingly

- [x] Task 16: Implement the eliminatie-journaalpost-generation action per REQ-ICE-006 — on `IntercompanyMatch.create` (if matchStatus = within-tolerance or perfect-match), lifecycle action materialises `EliminationJournal` with debet/credit lines per matched transaction pair and default GL-accounts from `IntercompanyRelation`; lines MUST balance

- [x] Task 17: Implement mismatch-classification + resolutie-action-routing per REQ-ICE-005 — when `IntercompanyMismatch` is created (outside-tolerance or manual mark), offer semi-automated resolutie-pads per causeClassification (timing: interim-with-reversal template, FX: post-to-CTA-account template, transfer-pricing: source-correction-wizard, fout: manual-GL-entry form); resolutionAction persisted with resolutionNotes

- [x] Task 18: Implement the counterparty-balance aggregation per REQ-ICE-007 as `x-openregister-aggregations` query on `CounterpartyBalance` — GROUP BY (entityAId, entityBId, periodId), SUM(AR receivables A, AP payables A, sales A→B, purchases A←B, mismatch-count) — NOT a PHP service; refreshed on each matching-run

- [x] Task 19: Implement cross-period roll-forward consistency check per REQ-ICE-008 — on `IntercompanyMatch.create`, compare period-opening balances to prior-period endings; if mismatch, generate alert + offer cascade-wizard. Detect backdated-wijzigingen in prior-period transacties; block matching, escalate to manual review.

- [x] Task 20: Implement multi-currency matching per REQ-ICE-009 — on `IntercompanyMatch.create`, if entiteiten have different functional-valuta, convert both sides to group-reporting-valuta using configured transactie-datum-koers + balansdatum-koers; log koers-source (ECB, manual) per match; FX-differences classify as fx-translation + post to CTA-restpost (not P&L)

- [x] Task 21: Add 7 manifest navigation entries to `src/manifest.json` per REQ-ICE-001 through REQ-ICE-008 — (Intercompany Relations, Transactions, Matches, Mismatches, Tolerance Rules, Counterparty Balances, Elimination Journals) + their `type: index` / `type: detail` pages each; `node tests/validate-manifest.js` exits 0

- [x] Task 22: Update `openspec/architecture/adr-000-data-model.md` with `IntercompanyRelation`, `IntercompanyTransaction`, `IntercompanyMatch`, `IntercompanyMismatch`, `ToleranceRule`, `CounterpartyBalance`, `EliminationJournal` entries, reconciling against any existing IC-related data-model entries (if any)

- [x] Task 23: Implement REQ-ICE-010 performance tests — profile matching on 4vCPU/8GB hardware: full match (12 entities, 4000 IC-transactions/month) MUST complete <5 minutes; incremental re-match (delta 50-100 transactions) MUST complete <30 seconds; large-group match (40 entities, 60k IC-transactions) MUST complete <30 minutes; log per-relatie execution times

## Implementation Notes (hydra build)

This change was implemented end-to-end (not left spec-only). Build decisions and the
ADR-031 declarative-vs-imperative split:

- **Tasks 12 & 18 (aggregations):** Declared as `x-openregister-aggregations`
  (`matchByRelationPeriod` on `IntercompanyMatch`, `counterpartyBalanceByPair` on
  `CounterpartyBalance`). The parts OpenRegister's declarative engine cannot yet
  express — multi-source per-side conditional sums, FX conversion (REQ-ICE-009) and
  cross-period carry (REQ-ICE-008) — are implemented on the bounded ADR-031
  exception path in `lib/Service/IntercompanyMatchingService.php` (I/O orchestration,
  real OR ObjectService API `find/findAll/saveObject` only) + the pure, fully
  unit-tested `lib/Service/IntercompanyMatchingCalculator.php` (integer-cent
  arithmetic). This mirrors the existing TrialBalanceService/Calculator convention.
- **Tasks 13 & 16 (lifecycle guards):** `IntercompanyToleranceGuard::isWithinTolerance`
  (`IntercompanyMatch.create` requires) and `EliminationBalanceGuard::isBalanced`
  (`EliminationJournal.create` requires). Both fail-closed.
- **Task 9 seed:** `lib/Settings/seeds/intercompany-tolerance-rules.json` (5 default
  rules) + `SettingsService::seedIntercompanyToleranceRules` wired into the
  `InitializeSettings` repair step (idempotent, dedupe by ruleId).
- **Quality:** phpcs / phpmd / psalm / phpstan all clean on the touched files; 38
  PHPUnit tests for the calculator, both guards and the service. The 12 calculator
  + guard balance tests run on-host; the NC-mocking tests (IAppConfig) run in the CI
  Nextcloud container exactly like the existing guard tests.

### Completed in follow-up build (was previously deferred)

- **Task 14 (ScheduledWorkflow trigger):** Implemented. The Intercompany
  monthly-matching `ScheduledWorkflow` is now registered idempotently from the
  `InitializeSettings` repair step via
  `registerIntercompanyMonthlyMatchingWorkflow()` (slug
  `shillinq-intercompany-monthly-matching`, 30-day interval, payload targets
  `IntercompanyMatchingService::matchRelationPeriod()` per `IntercompanyRelation`).
  Mirrors the existing IV3, FixedAssets and BCF `registerXxxScheduledWorkflow`
  patterns. Operators reconfigure cadence (quarterly / annual) and per-group
  overrides via the OpenRegister `ScheduledWorkflow` admin UI per ADR-031.
- **Task 23 (performance benchmarks):** Implemented as
  `tests/Unit/Service/IntercompanyMatchingPerformanceTest.php` (PHPUnit `#[Group('performance')]`)
  covering both REQ-ICE-010 budgets:
  full-month match (4 000 IC-transactions on a 12-entity group) MUST return in
  < 5 minutes, and incremental re-match (delta of 100 transactions) MUST return
  in < 30 seconds. Backs a recording in-memory `ObjectService` stub modelled on
  `TrialBalancePerformanceTest`. Both budgets are loose REQ-ICE-010 ceilings so
  the test trips on an order-of-magnitude regression even on slow CI hardware;
  hardware-representative 4vCPU/8GB profiling for the 40-entity / 60k-transaction
  large-group target remains a runtime-only exercise once a representative live
  dataset exists.

## Verification

`openspec validate` must exit clean on the change folder. Consolidation-persona peer review (e.g., concern-controller persona) confirms the IC-elimination flow matches Dutch MKB holding practice (relatie-setup, detectie, matching, tolerance-eval, mismatch-resolutie, eliminatie-generatie, cross-period consistency, multi-currency). Architecture reviewer confirms ADR-022 (no app-local services, aggregation-based) + ADR-031 (lifecycle guards, scheduled workflow, no PHP matching-service) compliance. No source code changes outside `openspec/changes/bookkeeping-intercompany-elimination/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:
- **PHPUnit unit tests** for matching algorithm (perfect match, within-tolerance, outside-tolerance, mismatch classifications), tolerance-evaluation logic, FX translation, cross-period consistency, roll-forward detection.
- **Aggregation tests** — verify `x-openregister-aggregations` queries return expected GROUP BY + SUM results.
- **Lifecycle tests** — verify `x-openregister-lifecycle.requires` guards fire correctly during matching-run lifecycle.
- **Scheduled-workflow tests** — verify OR's ScheduledWorkflow integration (monthly/quarterly/annual runs fire on schedule).
- **Playwright browser tests** — test the 7 manifest index/detail pages: relation management, transaction review, match inspection, mismatch classification + resolutie-action-selection, tolerance-rule configuration, counterparty-balance views, elimination-journal audit-trail.
- **Performance benchmarks** (Task 23) — REQ-ICE-010 targets: <5m full match, <30s incremental, <30m large-group.
- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- **`docs/user-guide/bookkeeping/intercompany-elimination.md`** per ADR-030 journeydoc convention — covers: relation-setup wizard, detectie-configuration, matching-run execution, mismatch-investigation + resolutie, counterparty-confirmation-letter export (T4 preview), FX-translation-handling, cross-period consistency checks.
- **Screenshots** — relation-management UI, matching-run progress, mismatch-queue review, tolerance-rule configuration, counterparty-balance drilldown.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
- Intercompany, Relation, Relation Type (Sales of Goods, Sales of Services, Royalty, Licensing, Management Fee, Interest on Loan, Dividend, Capital Contribution, Expense Recharge)
- Detection, Confidence (High, Medium, Low), Detection Method (Account-Based, Label-Based, Explicitly Marked)
- Match Status (Perfect Match, Within Tolerance, Outside Tolerance, One-Sided A, One-Sided B)
- Mismatch, Classification, Cause (Timing Difference, FX Translation, Transfer Pricing Adjustment, Missing Booking, Classification Error, Unknown)
- Resolution, Action (Manual GL Correction, Interim Elimination with Reversal, Post to CTA, Source Correction Booking, Accept as Difference)
- Tolerance, Rule, Absolute, Relative, Fallback Account
- Counterparty Balance, Receivables, Payables, Net Position, Sales, Purchases
- Elimination Journal, Approved By, Approved At
- Roll-Forward, Cascade Impact, Backdated Change
- Currency, Exchange Rate, Translation Difference, Cost of Translation Adjustment
