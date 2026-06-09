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
- [ ] Task 2: Author `specs/bookkeeping-reconciliation-reports/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine features)` / `Depends on: bookkeeping-bank-reconciliation, bookkeeping-accounts-receivable-core, bookkeeping-accounts-payable-core` header, `REQ-REC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (GL period lock availability, matching algorithm completeness, variance tolerance thresholds) / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table, D1 (bounded workflow per account+period), D2 (matching delegated to T2), D3 (statement balance verification), D4 (unmatched items are resolution artifacts), D5 (variance as aggregation), D6 (lifecycle bounded and auditable)
- [ ] Task 5: Declare the `BankReconciliation` schema in `lib/Settings/shillinq_register.json` with all REQ-REC-001 fields (bankAccountId, statementDate, statementPeriodStart, statementPeriodEnd, openingBalance, closingBalance, expectedGLBalance, variance, reconciliationStatus, preparedBy, verifiedBy, closedAt, administrationId)
- [ ] Task 6: Declare the `ReconciliationMatch` schema in `lib/Settings/shillinq_register.json` with all REQ-REC-005 fields (reconId, glTransactionId, bankLineId, matchAlgorithm, confidenceScore, matchedAt, manualOverride, resolutionStatus, resolutionReason, arInvoiceId, apTransactionId)
- [ ] Task 7: Declare the `ReconciliationReport` schema in `lib/Settings/shillinq_register.json` with all REQ-REC-001 fields (reconId, reportDate, matchedCount, unmatchedGLCount, unmatchedBankCount, totalVariance, preparedBy, verifiedBy, signOffComment, administrationId)
- [ ] Task 8: Add `x-openregister-lifecycle` to `BankReconciliation` declaring every transition in REQ-REC-003 (`draft → in-progress → verified → closed` plus cancel + revert) with statement-balance verification guard per REQ-REC-002 (or single-method `StatementVerifyGuard` per ADR-031 exception if GL balance lookup is not declarative, documented)
- [ ] Task 9: Implement the statement-balance verification precondition per REQ-REC-002 — computes expected GL balance and surfaces variance warning; may be declarative aggregation or single-method guard per ADR-031
- [ ] Task 10: Implement unmatched-item resolution workflow per REQ-REC-004 — API endpoint accepting classification (timing/pending/adjustment/matched) + reason text, updates `ReconciliationMatch.resolutionStatus` and `resolutionReason`; audit-trailed
- [ ] Task 11: Declare variance reporting as `x-openregister-aggregations` queries per REQ-REC-007 (by account, by period, by type; exclude open reconciliations) — not a service
- [ ] Task 12: Implement T2 → T4 event consumption per REQ-REC-010 — listen for T2 transaction-match events, create `ReconciliationMatch` records within 1 second; verify event schema matches T2 contract
- [ ] Task 13: Add 3 manifest navigation entries (`Reconciliations`, `Unmatched Items`, `Variance Report`) + their `type: index` / `type: detail` / `type: report` pages to `src/manifest.json` per REQ-REC-008; `node tests/validate-manifest.js` exits 0
- [ ] Task 14: Implement Reconciliation detail page per REQ-REC-008 requirement — display bank account + statement balances, matched/unmatched counts, variance; surface lifecycle action buttons; embed unmatched-item resolution table with bulk-action support
- [ ] Task 15: Implement Unmatched Items index page per REQ-REC-008 — list all unresolved items across open reconciliations, grouped by account+recon; support bulk classification + reason input
- [ ] Task 16: Implement Variance Report page per REQ-REC-007 — render aggregation queries by account, by period, by type with filtering; link to detail reconciliations
- [ ] Task 17: Implement reconciliation closure verification per REQ-REC-006 — before `in-progress → verified`, surface summary (matched count, unmatched GL/bank counts, variance) and require operator sign-off comment; prevent transition if unresolved items remain or comment is empty
- [ ] Task 18: Update `openspec/architecture/adr-000-data-model.md` with `BankReconciliation`/`ReconciliationMatch`/`ReconciliationReport` entries, reconciling against any existing bank-reconciliation entities from T2
- [ ] Task 19: Deduplication check — verify no overlap with T2's `bookkeeping-bank-reconciliation` matching logic; document T4's role as outcome recorder (not matcher) in inline code comments + design-doc reference

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
