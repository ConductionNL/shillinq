# Spec: bookkeeping-reconciliation-reports

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine features)
**Depends on:** `../bookkeeping-bank-reconciliation/spec.md` (T2 transaction matching),
`../bookkeeping-accounts-receivable-core/spec.md` (AR invoices),
`../bookkeeping-accounts-payable-core/spec.md` (AP invoices)

## ADDED Requirements

### Requirement: REQ-REC-001: Bank reconciliation SHALL be declared as `BankReconciliation` + `ReconciliationMatch` + `ReconciliationReport` registers

Bank reconciliation MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `BankReconciliation` — reconciliation state per bank account + statement
  date (account reference, statement period, opening balance, closing balance,
  expected GL balance, variance, reconciliation status).
- `ReconciliationMatch` — individual matched transactions (GL transaction UUID,
  bank statement line UUID, match algorithm, confidence score, manual override
  flag, resolution status for unmatched items).
- `ReconciliationReport` — reconciliation outcomes for audit trails (reconciliation
  date, matched count, unmatched GL count, unmatched bank count, variance amount,
  preparer, verifier, signed-off date).

Schema.org annotation: `schema:Report` for all three (reconciliation is a
financial control artifact).

#### Scenario: Reviewer confirms no parallel reconciliation table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `reconciliation_*`,
  `bank_match_*`, `statement_*`, or `variance_*`
- **THEN** no such classes SHALL exist.

#### Scenario: Reconciliation matches reference both GL and bank line

- **GIVEN** T4 is live and a `BankReconciliation` for account EUR-0001
  on 2026-05-31 exists
- **WHEN** a GL transaction (UUID `tx-gl-001`) is matched to bank
  statement line (UUID `line-bank-0042`)
- **THEN** a `ReconciliationMatch` MUST record both UUIDs with
  `matchAlgorithm: "exact"` and `confidenceScore: 1.0`.

### Requirement: REQ-REC-002: Statement balance verification SHALL be a precondition on `draft → in-progress` transition

When a reconciliation is initiated (`draft → in-progress`), the system
MUST verify that the statement closing balance equals the expected GL
balance (computed as GL balance at period start + net GL activity for
the statement period).

**Expected GL balance formula:**
```
expectedGLBalance = 
  Account.balanceAtPeriodStart + 
  SUM(GLLine.debit - GLLine.credit) 
  where GLLine.entryDate between statement.periodStart and statement.periodEnd
```

If `|statement.closingBalance - expectedGLBalance| > 0`, the transition
MUST succeed with a warning surfaced to the operator; the operator MAY
proceed or investigate. The variance is captured in `BankReconciliation.variance`.

#### Scenario: Statement closing balance matches GL balance

- **GIVEN** account EUR-0001 has GL opening balance €100,000 and net
  GL activity of €25,000 (credit) for the period
- **WHEN** the statement shows closing balance €75,000
- **THEN** the `draft → in-progress` transition MUST succeed with no warning;
  `variance` MUST be 0.

#### Scenario: Variance surfaces warning but allows proceed

- **GIVEN** the GL balance is €75,000 but statement shows €75,001.52
- **WHEN** the operator attempts `draft → in-progress`
- **THEN** the transition MUST succeed; **AND** a variance warning
  MUST surface naming the amount (€1.52) and directing the operator
  to resolve via matching or unmatched-item classification.

### Requirement: REQ-REC-003: `BankReconciliation` SHALL declare the reconciliation workflow lifecycle

`BankReconciliation` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `in-progress` | operator initiate | statement balance verification per REQ-REC-002 |
| `in-progress` | `verified` | operator confirm | all unmatched items resolved per REQ-REC-004 |
| `verified` | `closed` | period close or operator finalize | no further matching allowed |
| `draft` | `cancelled` | operator abandon | none |
| `in-progress` → `draft` | revert for investigation | operator action | audit-trailed |

All transitions MUST be audit-trailed with actor, timestamp, and reason (if provided).

#### Scenario: Reconciliation progresses through lifecycle

- **GIVEN** a `draft` BankReconciliation for EUR-0001 on 2026-05-31
- **WHEN** the operator initiates (`draft → in-progress`) and the
  balance verification passes
- **THEN** the status MUST become `in-progress`; **AND** the operator
  MUST be able to review and match transactions.

#### Scenario: Cannot close reconciliation with unresolved items

- **GIVEN** an `in-progress` reconciliation with 5 unmatched bank lines
- **WHEN** the operator attempts `in-progress → verified`
- **THEN** the transition MUST fail with a message listing unresolved items
  and directing the operator to REQ-REC-004 resolution workflow.

### Requirement: REQ-REC-004: Unmatched items SHALL be resolved by classification or manual matching

When a bank statement line has no corresponding GL transaction (or vice versa),
the operator MUST classify the unmatched item as one of:

- **timing**: item is expected to match in the next period (bank-side pending,
  GL entry not yet posted, etc.)
- **pending**: awaiting bank confirmation or GL investigation
- **adjustment**: non-transaction difference (bank fee, accrual adjustment,
  correction)
- **matched**: operator manually matched the item to a specific GL transaction

Each classification MUST include an audit-trailed reason text (operator-supplied).

#### Scenario: Unmatched bank line classified as timing

- **GIVEN** a bank statement line for €500 with no GL match
- **WHEN** the operator classifies it as `timing` with reason
  "customer check, pending post"
- **THEN** the `ReconciliationMatch` MUST record
  `resolutionStatus: "timing"` and `resolutionReason: "..."`;
  **AND** the match MUST be excluded from the unmatched count for
  reconciliation verification (REQ-REC-003).

#### Scenario: Operator manually matches GL transaction to bank line

- **GIVEN** a GL transaction (journal entry €500, ref "CHQ-001") and
  a bank line (€500, memo "CHQ-001")
- **WHEN** the operator manually matches them
- **THEN** a `ReconciliationMatch` MUST record both UUIDs with
  `matchAlgorithm: "manual"`, `confidenceScore: 1.0`, and
  `manualOverride: true`.

### Requirement: REQ-REC-005: `ReconciliationMatch` SHALL record automatic and manual matches

`ReconciliationMatch` MUST capture:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reconId` | string | Yes | FK to `BankReconciliation` UUID |
| `glTransactionId` | string | No | FK to `GLTransaction` UUID (null if bank-only match) |
| `bankLineId` | string | No | FK to `BankStatementLine` UUID (null if GL-only match) |
| `matchAlgorithm` | enum | Yes | One of `exact`, `fuzzy`, `manual` (T4 supports `exact` + `manual` only) |
| `confidenceScore` | number | Yes | 0.0–1.0 confidence (1.0 = certain, <0.9 = low confidence) |
| `matchedAt` | datetime | Yes | When match was recorded |
| `manualOverride` | boolean | No | True if manually matched by operator |
| `resolutionStatus` | enum | No | One of `matched`, `timing`, `pending`, `adjustment` (for unmatched items) |
| `resolutionReason` | string | No | Operator-supplied reason for classification |
| `arInvoiceId` | string | No | FK to `ARInvoice` UUID if match is AR-based |
| `apTransactionId` | string | No | FK to `APTransaction` UUID if match is AP-based |
| `reconId` (for audit) | string | Yes | Back-reference to parent reconciliation |

#### Scenario: Automatic match records exact-match algorithm

- **GIVEN** T2's transaction-matching engine matches GL transaction
  `tx-001` (€1,000) to bank line `line-042` (€1,000) by amount + reference
- **WHEN** the match event is received by T4
- **THEN** a `ReconciliationMatch` MUST be recorded with `matchAlgorithm: "exact"`,
  `confidenceScore: 1.0`, and `manualOverride: false`.

### Requirement: REQ-REC-006: Reconciliation closure SHALL require operator verification of all unmatched items

Before transitioning `in-progress → verified` (REQ-REC-003), the operator
MUST confirm that all unmatched items have been classified per REQ-REC-004.
The system MUST surface a summary:

- Count of matched transactions
- Count of unmatched GL items (with details)
- Count of unmatched bank items (with details)
- Total variance (statement - GL)

The operator MUST provide a sign-off comment before closing.

#### Scenario: Verification requires unmatched-item review

- **GIVEN** a reconciliation with 3 matched items, 1 unmatched GL line,
  and 2 unmatched bank lines
- **WHEN** the operator prepares to close
- **THEN** the system MUST display: "Matched: 3 | Unmatched GL: 1 | Unmatched Bank: 2 | Variance: €5.67"
  **AND** prevent transition unless operator provides sign-off comment.

### Requirement: REQ-REC-007: Variance reporting SHALL be declared as `x-openregister-aggregations` queries

Variance reporting MUST be expressed as aggregations, NOT PHP report services:

**Variance by Account:**
```
GROUP BY bankAccountId
SELECT 
  accountId, 
  SUM(|statement.closingBalance - expectedGLBalance|) as totalVariance,
  COUNT(*) as reconCount
WHERE status = 'closed'
```

**Variance by Period:**
```
GROUP BY (bankAccountId, statementPeriod)
SELECT 
  accountId, 
  period, 
  variance,
  COUNT(unmatchedGLItems) as unmatchedGL,
  COUNT(unmatchedBankItems) as unmatchedBank
WHERE status = 'closed'
```

**Variance by Type:**
```
GROUP BY (bankAccountId, resolutionStatus)
SELECT 
  accountId,
  resolutionStatus,
  COUNT(*) as itemCount,
  SUM(amount) as totalAmount
WHERE status = 'closed'
```

NO `VarianceReportService.php`. Same ADR-031 anti-pattern prohibition as AR/AP.

#### Scenario: Reviewer confirms no variance-report service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Variance*.php`,
  `lib/Service/*Report*.php`
- **THEN** no such files SHALL exist (other than any unrelated report services).

#### Scenario: Variance aggregation excludes open reconciliations

- **GIVEN** 3 closed reconciliations with variances €10, €5, €0
  and 1 open reconciliation with variance €20
- **WHEN** the variance aggregation runs
- **THEN** the result MUST report total variance €15; the open
  reconciliation MUST be excluded.

### Requirement: REQ-REC-008: Bank reconciliation SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Bookkeeping > Reconciliations` — `type: index` + `type: detail` on
  `BankReconciliation`.
- `Bookkeeping > Unmatched Items` — `type: index` filtered to
  `ReconciliationMatch` where `resolutionStatus IS NULL` (unresolved);
  provides bulk resolution workflow.
- `Bookkeeping > Variance Report` — `type: report` (or `type: index`
  fallback) bound to the variance aggregations per REQ-REC-007.

Rendering MUST use `@conduction/nextcloud-vue` generic components
per ADR-024 Tier-4 — no bespoke Vue files.

#### Scenario: Reconciliation detail page shows matches and unmatched items

- **GIVEN** the manifest declares the Reconciliations pages
- **WHEN** an operator opens a reconciliation detail
- **THEN** the page MUST display:
  - Bank account + statement period + opening/closing balances
  - Summary: matched count, unmatched GL count, unmatched bank count, variance
  - Table of `ReconciliationMatch` records (with GL ref, bank ref, match algorithm)
  - Table of unresolved items (with resolution workflow buttons: timing/pending/adjustment/manual-match)
  - Lifecycle action buttons (initiate, verify, close) per reconciliation status

#### Scenario: Unmatched Items page provides bulk resolution

- **GIVEN** the Unmatched Items page
- **WHEN** an operator opens it
- **THEN** the page MUST show all unmatched items across all open
  reconciliations, grouped by account + reconciliation; **AND** the operator
  MUST be able to bulk-classify items (select multiple, apply resolution type,
  add reason comment).

### Requirement: REQ-REC-009: Bank reconciliation lifecycle transitions SHALL be audit-trailed with signed-off timestamp

Every lifecycle transition (REQ-REC-003) MUST record:
- Transition type (draft → in-progress, etc.)
- Actor (user ID)
- Timestamp
- Reason/comment (if provided)
- Preparer and verifier signatures (for verified → closed)

These records MUST be queryable via the `BankReconciliation` audit trail
(automatic via T2 `bookkeeping-audit-trail`).

#### Scenario: Reconciliation close captures verifier sign-off

- **GIVEN** a reconciliation transitioning `verified → closed`
- **WHEN** the verifier confirms the close
- **THEN** the audit trail MUST record:
  - `actor: "verifier-user-id"`
  - `action: "verified → closed"`
  - `timestamp: <UTC datetime>`
  - `comment: "<verifier's sign-off reason>"`
  - `signature: <digital signature or approval marker>`

### Requirement: REQ-REC-010: T2 bank-reconciliation transaction-matching events SHALL drive T4 reconciliation matches

When `bookkeeping-bank-reconciliation` (T2) emits a transaction-matching event
(per REQ-BR-006), T4's reconciliation workflow MUST consume it and create a
`ReconciliationMatch` record. The match event MUST include:
- GL transaction UUID
- Bank statement line UUID
- Match algorithm (exact, fuzzy, etc.)
- Confidence score

T4 does not compute matches; T2 does. T4 records outcomes.

#### Scenario: T2 match event creates T4 reconciliation match

- **GIVEN** T2 matches GL transaction `tx-001` to bank line `line-42`
- **WHEN** the match event is emitted
- **THEN** T4 MUST create a `ReconciliationMatch` record with both UUIDs
  and `matchAlgorithm: "exact"` within 1 second of the event.
