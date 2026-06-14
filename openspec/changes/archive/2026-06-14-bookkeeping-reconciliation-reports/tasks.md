# Tasks — Bank Reconciliation Reports

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-reconciliation-reports` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-reconciliation-reports` capability spec already exists, no `BankReconciliation`/`ReconciliationMatch`/`ReconciliationReport` schemas are declared, and no `lib/Service/Reconcil*` / `lib/Service/Variance*` PHP classes are present (per ADR-031 anti-pattern enumeration)
- [x] Task 2: Author `specs/bookkeeping-reconciliation-reports/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine features)` / `Depends on: bookkeeping-bank-reconciliation, bookkeeping-accounts-receivable-core, bookkeeping-accounts-payable-core` header, `REQ-REC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (GL period lock availability, matching algorithm completeness, variance tolerance thresholds) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (bounded workflow per account+period), D2 (matching delegated to T2), D3 (statement balance verification), D4 (unmatched items are resolution artifacts), D5 (variance as aggregation), D6 (lifecycle bounded and auditable)
- [x] Task 5: Declare the `BankReconciliation` schema in `lib/Settings/shillinq_register.json` with all REQ-REC-001 fields (bankAccountId, statementDate, statementPeriodStart, statementPeriodEnd, openingBalance, closingBalance, expectedGLBalance, variance, reconciliationStatus, preparedBy, verifiedBy, closedAt, administrationId) — declared in `lib/Settings/register.d/bookkeeping-reconciliation-reports.json` per ADR-037 (fragment overlay, not monolith edit)
- [x] Task 6: Declare the `ReconciliationMatch` schema in `lib/Settings/shillinq_register.json` with all REQ-REC-005 fields (reconId, glTransactionId, bankLineId, matchAlgorithm, confidenceScore, matchedAt, manualOverride, resolutionStatus, resolutionReason, arInvoiceId, apTransactionId) — extended via deep-merge from the same fragment; pre-existing T2 fields preserved
- [x] Task 7: Declare the `ReconciliationReport` schema in `lib/Settings/shillinq_register.json` with all REQ-REC-001 fields (reconId, reportDate, matchedCount, unmatchedGLCount, unmatchedBankCount, totalVariance, preparedBy, verifiedBy, signOffComment, administrationId) — declared in same fragment
- [x] Task 8: Add `x-openregister-lifecycle` to `BankReconciliation` declaring every transition in REQ-REC-003 (`draft → in-progress → verified → closed` plus cancel + revert) with statement-balance verification guard per REQ-REC-002 (or single-method `StatementVerifyGuard` per ADR-031 exception if GL balance lookup is not declarative, documented) — `initiate`/`verify`/`close`/`cancel`/`revert` all wired; guards reference `OCA\\Shillinq\\Guard\\StatementVerifyGuard::verifyStatementBalance` (initiate) and `::requireResolvedAndSignedOff` (verify), see Task 9
- [x] Task 9: Implement the statement-balance verification precondition per REQ-REC-002 — computes expected GL balance and surfaces variance warning; may be declarative aggregation or single-method guard per ADR-031 — implemented as `OCA\\Shillinq\\Guard\\StatementVerifyGuard` (lib/Guard/StatementVerifyGuard.php) with two methods: `verifyStatementBalance` (REQ-REC-002 — integer-cents net activity sum, persists expectedGLBalance + variance, allow-proceed on non-zero variance) and `requireResolvedAndSignedOff` (REQ-REC-004 + REQ-REC-006 — rejects verify transition when matches unclassified or signOffComment empty). Single ADR-031 §exception (cross-object GL aggregation).
- [x] Task 10: Implement unmatched-item resolution workflow per REQ-REC-004 — API endpoint accepting classification (timing/pending/adjustment/matched) + reason text, updates `ReconciliationMatch.resolutionStatus` and `resolutionReason`; audit-trailed — `ReconciliationResolutionController` (POST /api/reconciliations/{reconId}/matches/{matchId}/resolve + bulk-resolve) + `ReconciliationResolutionService` (lib/Service/). #[NoAdminRequired] + IDOR-guarded (match.reconId must equal path reconId). Logs to OR audit trail per ADR-022.
- [x] Task 11: Declare variance reporting as `x-openregister-aggregations` queries per REQ-REC-007 (by account, by period, by type; exclude open reconciliations) — not a service — declared on `BankReconciliation` (`varianceByAccount` by `bankAccountId`, `varianceByPeriod` by `(bankAccountId, statementPeriodEnd)`, `reconciliationCount`), on `ReconciliationMatch` (`varianceByType` by `(reconId, resolutionStatus)`, `unresolvedByRecon`, `matchesByRecon`), and on `ReconciliationReport` (`totalVarianceByAdmin`). All three top-level aggregations filter on `reconciliationStatus = closed` per REQ-REC-007 scenario "Variance aggregation excludes open reconciliations". No `VarianceReportService` exists.
- [x] Task 12: Implement T2 → T4 event consumption per REQ-REC-010 — listen for T2 transaction-match events, create `ReconciliationMatch` records within 1 second; verify event schema matches T2 contract — `ReconciliationMatchToReportListener` wired in `Application.php` against `ObjectTransitionedEvent` + `ObjectCreatedEvent`. On a T2 confirm it stamps reconId (looked up via BankStatementLine→BankStatement→open BankReconciliation match on (bankAccountId, statementPeriodEnd)), matchAlgorithm (auto→exact, manual→manual per REQ-REC-005), matchedAt, manualOverride, confidenceScoreT4, and the polymorphic FK shortcuts (arInvoiceId/apTransactionId/glTransactionId derived from T2 matchType). Idempotent (skips if matchAlgorithm already set). Fail-soft per REQ-REC-010 + ADR-022.
- [x] Task 13: Add 3 manifest navigation entries (`Reconciliations`, `Unmatched Items`, `Variance Report`) + their `type: index` / `type: detail` / `type: report` pages to `src/manifest.json` per REQ-REC-008; `node tests/validate-manifest.js` exits 0 — added 3 menu children under Bookkeeping (orders 28/29/45) + 5 pages: `Reconciliations` (index), `ReconciliationDetail` (detail+lifecycle), `UnmatchedItems` (index w/ bulk classify), `VarianceReport` (report w/ KPIs+table), `ReconciliationReportDetail` (sealed-report detail). `validate-manifest.js` passes (0 issues).
- [x] Task 14: Implement Reconciliation detail page per REQ-REC-008 requirement — display bank account + statement balances, matched/unmatched counts, variance; surface lifecycle action buttons; embed unmatched-item resolution table with bulk-action support — `ReconciliationDetail` page shows all REQ-REC-001 fields + 5 lifecycle action buttons (initiate/verify/close/revert/cancel) wired to schema transitions + sidebar tabs: Matches (filtered ReconciliationMatch table), Unresolved Items (with bulkActions=timing/pending/adjustment), Closure Summary (renders matchedCount/unmatchedGLCount/unmatchedBankCount/variance/signOffComment per REQ-REC-006), Audit Trail.
- [x] Task 15: Implement Unmatched Items index page per REQ-REC-008 — list all unresolved items across open reconciliations, grouped by account+recon; support bulk classification + reason input — `UnmatchedItems` page filters ReconciliationMatch to `resolutionStatus=null`, groupBy=reconId, with three bulkActions calling `/api/reconciliations/:reconId/matches/bulk-resolve` for timing/pending/adjustment.
- [x] Task 16: Implement Variance Report page per REQ-REC-007 — render aggregation queries by account, by period, by type with filtering; link to detail reconciliations — `VarianceReport` (type=report) renders 3 KPIs (total variance/closed count/unresolved count) wired to the declared aggregations, plus a varianceByPeriod table linking to `ReconciliationDetail`.
- [x] Task 17: Implement reconciliation closure verification per REQ-REC-006 — before `in-progress → verified`, surface summary (matched count, unmatched GL/bank counts, variance) and require operator sign-off comment; prevent transition if unresolved items remain or comment is empty — UI surface is the `closure-summary` sidebar tab on `ReconciliationDetail` (renders matchedCount/unmatchedGLCount/unmatchedBankCount/variance/signOffComment); server-side guard is `StatementVerifyGuard::requireResolvedAndSignedOff` wired to the `verify` transition. The guard rejects (returns false) when any ReconciliationMatch.resolutionStatus is null OR when signOffComment is empty — matches REQ-REC-006 scenario "Verification requires unmatched-item review".
- [x] Task 18: Update `openspec/architecture/adr-000-data-model.md` with `BankReconciliation`/`ReconciliationMatch`/`ReconciliationReport` entries, reconciling against any existing bank-reconciliation entities from T2 — added full `BankReconciliation` + `ReconciliationReport` sections (T4 primary spec) and extended the existing `ReconciliationMatch` section with all T4 fields (reconId, matchAlgorithm, confidenceScoreT4, matchedAt, manualOverride, resolutionStatus, resolutionReason, arInvoiceId, apTransactionId) plus the new aggregations. Header entity count bumped 248 → 250.
- [x] Task 19: Deduplication check — verify no overlap with T2's `bookkeeping-bank-reconciliation` matching logic; document T4's role as outcome recorder (not matcher) in inline code comments + design-doc reference — verified 2026-06-09 (`lib/Service/*` has no Reconcil*Engine / Variance*Service / BankMatch* mapper; only `ReconciliationResolutionService` (T10 thin write seam), `DepositReconciliationService` + `TaxPaymentReconciliationService` (unrelated domains)). Inline dedup notes added to (a) `lib/Settings/register.d/bookkeeping-reconciliation-reports.json` `_meta.dedup-note` and (b) `lib/Listener/ReconciliationMatchToReportListener.php` class docblock — both reference design.md §D2.

## Verification

`openspec validate` must exit clean on the change folder. Accountant-persona
peer review (e.g. `/test-persona-henk` for corporate) confirms the reconciliation
workflow matches Dutch bookkeeping practice (statement load → balance verification
→ transaction matching → unmatched resolution → variance reporting → period close).
Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local
matching service; lifecycle declarative or ADR-031-exception-annotated guard; manifest
carries navigation). No source code changes outside `openspec/changes/bookkeeping-reconciliation-reports/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for: PHPUnit unit tests for reconciliation
lifecycle, statement-balance verification, unmatched-item resolution, variance
aggregation rejection (pre-declared on Tasks 5–7); Playwright MCP browser tests
for the 3 manifest navigation entries + reconciliation detail + unmatched-items page
(pre-declared on Task 13); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors
`docs/user-guide/bookkeeping/reconciliation.md` per ADR-030 journeydoc convention
and commits reconciliation detail + variance report screenshots to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
`Bank Reconciliation`, `Reconciliation`, `Unmatched Items`, `Variance Report`,
`Matched`, `Unmatched`, `Timing`, `Pending`, `Adjustment`, `Manual Match`,
`Statement Balance`, `GL Balance`, `Variance`, `Reconcile`, `Verify`, `Close`,
`Sign Off`, `Preparer`, `Verifier`.
