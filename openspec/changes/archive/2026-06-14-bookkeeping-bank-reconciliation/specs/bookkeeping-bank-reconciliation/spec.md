# Spec: Bank Reconciliation

**Scope:** bookkeeping-bank-reconciliation
**Tier:** T1 — core workflow
**Status:** draft
**Applies to:** Shillinq (Nextcloud Bookkeeping)

## Overview

Bank reconciliation workflow for matching bank statement transactions against journal entries (accounts payable/receivable). Operators import bank statements (CSV/OFX), system performs automatic matching by amount + date + reference, operator reviews exceptions and approves reconciliation. Reconciliation session tracks opening/closing balance, reconciled balance, variance, and audit trail per match decision.

## Data Model

### BankReconciliation

Represents one bank account reconciliation session for a fiscal period (month, quarter, or custom range).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique reconciliation identifier (UUID) |
| name | string | Yes | Human-readable name (e.g., "Checking Account — May 2026") |
| bankAccountId | string | Yes | FK to BankAccount being reconciled |
| statementStartDate | date | Yes | First date on the bank statement |
| statementEndDate | date | Yes | Last date on the bank statement |
| openingBalance | number | Yes | Balance at statement start (from bank statement) |
| closingBalance | number | Yes | Balance at statement end (from bank statement) |
| reconciledBalance | number | No | Opening + sum of approved matches (calculated) |
| variance | number | No | closingBalance - reconciledBalance |
| matchedCount | integer | No | Total approved matches |
| unmatchedBankCount | integer | No | Bank transactions without match |
| unmatchedJournalCount | integer | No | Journal entries without match |
| status | enum | Yes | draft, in-progress, reconciled, archived |
| approvedBy | string | No | User ID of approver |
| approvedAt | datetime | No | Timestamp of approval |
| notes | string | No | Operator notes (discrepancies, variance explanation) |
| createdAt | datetime | Yes | Creation timestamp |
| updatedAt | datetime | Yes | Last modification timestamp |

**Relations:**
- → BankAccount (many-to-one)
- → BankReconciliationMatch (one-to-many)
- → Organization (many-to-one)

### BankReconciliationMatch

Represents one paired transaction: a bank statement transaction matched to a journal entry (APTransaction, GLLine, or Payment).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique match identifier (UUID) |
| reconciliationId | string | Yes | FK to parent BankReconciliation |
| bankTransactionRef | string | Yes | Bank statement transaction reference (external ID, date, amount key) |
| bankTransactionAmount | number | Yes | Amount from bank statement (cached for display) |
| journalEntryId | string | No | FK to APTransaction, GLLine, or Payment being matched |
| journalEntryDescription | string | No | Description of journal entry (cached) |
| matchType | enum | Yes | auto-matched, pending-review, approved, rejected |
| confidenceScore | integer | No | Match confidence 0–100 (for auto-matching threshold) |
| operatorNotes | string | No | Operator comments on decision |
| createdAt | datetime | Yes | Match creation timestamp |
| createdBy | string | Yes | User ID or "system-auto-match" |
| approvedAt | datetime | No | When operator approved the match |
| approvedBy | string | No | User ID of approver |

**Relations:**
- → BankReconciliation (many-to-one)
- → APTransaction (many-to-one, optional)

## Bank Transaction Reference

Bank transactions are sourced from external imports (CSV, OFX, API). They are not persisted as OpenRegister objects; instead, they are cached in BankReconciliationMatch for display. The bankTransactionRef key is:
```
{bankAccountId}#{statementDate}#{amount}#{externalId}
```

Example: `bank-acct-abc#2026-05-21#150.00#TXN-2026-05-21-001`

This ensures deduplication across multiple imports of the same statement.

## Requirements

### Requirement: REQ-BBR-001: Create bank reconciliation session

#### Scenario: Bookkeeper initiates reconciliation for a bank account

**GIVEN** a bookkeeper with access to bank accounts,
**WHEN** the bookkeeper clicks "New Reconciliation", selects a bank account, and enters:
- Statement period: May 1–31, 2026
- Opening balance: €5,000.00
- Closing balance: €6,250.50
- Statement file: download CSV from bank website

**THEN** the system creates a BankReconciliation with status `draft`. The bookkeeper is directed to the reconciliation detail page.

### Requirement: REQ-BBR-002: Import bank statement and auto-match

#### Scenario: System auto-matches bank transactions to journal entries

**GIVEN** a BankReconciliation in status `draft` with no transactions loaded,
**WHEN** the bookkeeper uploads a CSV bank statement with columns: [Date | Amount | Reference | Description],
**AND** the system maps columns to [statementDate | amount | invoiceRef | memo],
**THEN** the system:
1. Imports each row as a BankTransaction (cached in memory; not persisted yet)
2. Runs auto-matching algorithm:
   - For each bank transaction, search journal entries where amount matches ±€0.01
   - AND transaction date is within ±3 days of journal entry date
   - AND (bank memo contains journal ref OR invoice# found in memo)
   - Score: +100 exact amount, +20 if reference found, -10 per day past deadline
3. Creates BankReconciliationMatch records:
   - Exact matches (score ≥70) with matchType `auto-matched`
   - Ambiguous matches (30 ≤ score < 70) with matchType `pending-review`
   - No match (score <30) creates orphaned BankReconciliationMatch with journalEntryId = null, matchType `pending-review`
4. Displays:
   - Count of matched transactions
   - List of unmatched bank transactions (no journal entry found)
   - List of unmatched journal entries (no bank match found)

### Requirement: REQ-BBR-003: Operator reviews pending matches

#### Scenario: Bookkeeper manually resolves ambiguous matches

**GIVEN** a reconciliation with pending-review matches (score 30–70),
**WHEN** the bookkeeper clicks a pending match and sees:
- Bank transaction: €500.00, "INVOICE PAYMENT"
- Possible journal entries: [Invoice #INV-001 €500, Invoice #INV-002 €495, Payment #PAY-001 €500]

**THEN** the bookkeeper can:
1. Click to pair with Invoice #INV-001 (or any candidate)
2. Or reject (mark no match, variance to explain)
3. OR for ambiguous multi-match (€500 matches 3 invoices), note "Aggregated payment" and approve as-is

System updates match:
```json
{
  "journalEntryId": "apl-tx-uuid-INV-001",
  "matchType": "approved",
  "confidenceScore": 50,
  "operatorNotes": "Manual override: operator confirmed customer paid invoice INV-001",
  "approvedAt": "2026-05-31T16:00:00Z",
  "approvedBy": "user-jane-doe"
}
```

### Requirement: REQ-BBR-004: Reject unmatched transactions

#### Scenario: Operator documents and rejects orphaned transactions

**GIVEN** unmatched bank transactions (no journal entry found),
**WHEN** the bookkeeper reviews a bank transaction (e.g., €25.00 refund, "MISC DEPOSIT") that has no matching invoice or payment,
**AND** the bookkeeper clicks "Reject Match",

**THEN** the system marks the BankReconciliationMatch with:
```json
{
  "matchType": "rejected",
  "journalEntryId": null,
  "operatorNotes": "Unknown deposit — contacted customer on 2026-06-01; no invoice found. Holding as variance.",
  "approvedAt": "2026-05-31T17:00:00Z",
  "approvedBy": "user-jane-doe"
}
```

The bank transaction remains in variance report.

### Requirement: REQ-BBR-005: Calculate reconciliation balance and variance

#### Scenario: System calculates reconciled balance

**GIVEN** a BankReconciliation with imported transactions and all matches reviewed (all matchType values set),
**WHEN** the system calculates:

```
reconciledBalance = openingBalance + sum(approved_matches.bankTransactionAmount)
variance = closingBalance - reconciledBalance
```

**THEN** the system displays:
- Reconciled Balance: €6,250.49
- Closing Balance: €6,250.50
- Variance: €0.01 (green — acceptable rounding)

Visual indicator:
- Variance < €0.01: **Green** (✓ Acceptable)
- Variance €0.01–10: **Yellow** (⚠ Review unmatched items)
- Variance > €10 OR closing < opening: **Red** (❌ Data error; investigate)

### Requirement: REQ-BBR-006: Approve reconciliation with variance

#### Scenario: Bookkeeper approves despite non-zero variance

**GIVEN** a reconciliation with variance €5.00 (yellow flag),
**AND** the bookkeeper has reviewed all unmatched transactions and documented the variance (bank fee, timing difference),
**WHEN** the bookkeeper clicks "Approve Reconciliation" and enters:
- Approver signature (electronic confirmation)
- Variance reason: "Bank charged €5.00 monthly fee (posted 2026-06-01, not yet journaled)"

**THEN** the system:
1. Sets BankReconciliation.status = `reconciled`
2. Sets approvedBy = current user
3. Sets approvedAt = current timestamp
4. Stores variance reason in notes
5. Locks reconciliation for editing (immutable for audit trail)
6. Displays audit summary: "Reconciled by Jane Doe on 2026-05-31 | 12 matched | 0 unmatched | €0.01 variance"

### Requirement: REQ-BBR-007: Export variance report

#### Scenario: Bookkeeper exports reconciliation variance for auditor

**GIVEN** a reconciliation with status `reconciled`,
**WHEN** the bookkeeper clicks "Export Variance Report" (CSV),

**THEN** the system exports:
```csv
Reconciliation ID,Period,Opening Balance,Closing Balance,Reconciled Balance,Variance,Status
reconciliation-2026-05-abc-checking,2026-05-01 to 2026-05-31,5000.00,6250.50,6250.49,0.01,reconciled

Unmatched Bank Transactions:
Date,Amount,Reference,Bank Description,Status
[none for reconciled]

Unmatched Journal Entries:
Transaction ID,Date,Amount,Description,Account,Status
[none for reconciled]

Approval:
Approved By: Jane Doe
Approved At: 2026-05-31 16:00:00
Notes: Variance due to rounding; acceptable under materiality threshold
```

### Requirement: REQ-BBR-008: Reject/unmatch a transaction

#### Scenario: Operator decides a match was incorrect

**GIVEN** a BankReconciliationMatch with matchType `approved`,
**WHEN** the bookkeeper clicks "Unmatch" and confirms,

**THEN** the system:
1. Sets matchType back to `pending-review`
2. Adds operator note: "Unmatched on 2026-06-01 by user: originally matched incorrectly"
3. Recalculates variance
4. Requires operator to re-approve the reconciliation if variance changes materially

(Reconciliation must be `draft` or `in-progress` for unmatching; locked reconciliations cannot be unmatched without creating a new correction session.)

### Requirement: REQ-BBR-009: Archive reconciliation

#### Scenario: Bookkeeper archives completed reconciliation

**GIVEN** a reconciliation with status `reconciled` approved ≥30 days ago,
**WHEN** the bookkeeper clicks "Archive",

**THEN** the system:
1. Sets status = `archived`
2. Moves reconciliation to archive view (separate tab)
3. Reconciliation remains queryable for audit but not editable

### Requirement: REQ-BBR-010: Bulk matching for high-volume reconciliation

#### Scenario: Bookkeeper bulk-approves low-risk matches

**GIVEN** a reconciliation with 50 pending-review matches, all with confidence score ≥80,
**WHEN** the bookkeeper clicks "Approve All Pending (≥80% confidence)" with 1-click confirmation,

**THEN** the system:
1. Updates all matching records: matchType = `approved`, approvedBy = current user, approvedAt = now
2. Recalculates variance
3. Displays summary: "49 matches approved | 1 skipped (confidence 45%)"

## Workflow Sequence

```
1. Create BankReconciliation (draft)
   ↓
2. Upload Statement File (CSV/OFX)
   ↓
3. Auto-Matching Runs
   - Bank TXN: €150 [Invoice #INV-001] ✓ Match approved (score 95)
   - Bank TXN: €500 [Description unknown] ⚠ Pending review (score 45)
   - Journal Entry: Payment #PAY-001 €75 ❌ Unmatched
   ↓
4. Operator Reviews Pending & Unmatched
   - Approve €500 match: paired with Invoice #INV-002
   - Reject Payment #PAY-001: "Posting delay; will match next period"
   ↓
5. Calculate Variance
   - Opening: €5,000
   - Matches: +€650
   - Closing: €6,250.50
   - Reconciled: €6,250
   - Variance: €0.50 (yellow flag)
   ↓
6. Operator Approves with Variance Explanation
   - "Variance due to unprocessed payment; expected next month"
   - Status → reconciled
   ↓
7. Archive (30+ days later)
```

## Data Validation

### Import Validation

- **Date format**: must parse as ISO 8601 (YYYY-MM-DD) or locale format (configurable)
- **Amount format**: numeric, 2 decimal places, no currency symbol
- **Required fields**: statementDate, amount, (reference OR description)
- **Deduplication key**: (bankAccountId, statementDate, amount, externalId) — reject duplicates silently

### Match Validation

- **Amount precision**: match only if difference ≤€0.01 (float rounding tolerance)
- **Date range**: transaction date must be within ±3 days of journal entry date (configurable per organization)
- **Reference matching**: substring case-insensitive (e.g., bank memo "INV-001-ABC" matches journal ref "INV-001")
- **Single match per transaction**: if multiple journal entries qualify, flag as `pending-review` (ambiguous)

### Approval Validation

- **Approver identity**: must be authenticated user with permission on BankAccount
- **Variance materiality**: if variance >€100 (configurable), require manager approval, not just bookkeeper
- **Immutability**: once approved, reconciliation is locked; corrections require new session

## Audit Trail

Every match (auto, pending, approved, rejected) is timestamped and attributed:
```json
{
  "id": "match-uuid",
  "createdAt": "2026-05-22T08:00:00Z",
  "createdBy": "system-auto-match",
  "approvedAt": "2026-05-31T16:00:00Z",
  "approvedBy": "user-jane-doe",
  "matchType": "approved",
  "operatorNotes": "Approved by supervisor; matches well-documented payment"
}
```

System logs:
- Auto-match run timestamp, transaction count, match count
- Operator actions: match/unmatch, approve/reject, approval timestamp
- Variance calculation (opening, matched sum, closing, variance)
- Reconciliation approval (approver, timestamp, variance explanation)
