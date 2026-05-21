# Tasks: Bank Reconciliation

Implementation checklist for the `bookkeeping-bank-reconciliation` capability.

## Data Model & Schema

- [ ] Task 1: Create `BankReconciliation` schema in `lib/Settings/shillinq_register.json` with all properties per spec (name, bankAccountId, statement dates, opening/closing balance, reconciled balance, variance, match counts, status, approval tracking, notes).
- [ ] Task 2: Create `BankReconciliationMatch` schema in `lib/Settings/shillinq_register.json` with all properties (reconciliationId, bankTransactionRef, bankTransactionAmount, journalEntryId, journalEntryDescription, matchType enum, confidenceScore, operatorNotes, audit timestamps).
- [ ] Task 3: Define status enum for BankReconciliation: `draft | in-progress | reconciled | archived` with default `draft`.
- [ ] Task 4: Define matchType enum for BankReconciliationMatch: `auto-matched | pending-review | approved | rejected`.
- [ ] Task 5: Add OpenRegister relations:
  - BankReconciliation → BankAccount (many-to-one)
  - BankReconciliation → BankReconciliationMatch (one-to-many)
  - BankReconciliation → Organization (many-to-one)
  - BankReconciliationMatch → APTransaction (many-to-one, optional for journal entries)

## Manifest & Navigation

- [ ] Task 6: Add manifest entry `bank-reconciliations` to `src/manifest.json`:
  - type: index (list all reconciliations)
  - icon: account-balance
  - label: "Bank Reconciliation"
- [ ] Task 7: Add manifest entry `bank-reconciliation-detail` (detail view for single reconciliation).

## Backend API Endpoints

- [ ] Task 8: `POST /api/bank-reconciliations` — Create new BankReconciliation session (takes bankAccountId, statementStartDate, statementEndDate, openingBalance, closingBalance, name).
- [ ] Task 9: `GET /api/bank-reconciliations` — List all reconciliations (paginated, filterable by status, bank account).
- [ ] Task 10: `GET /api/bank-reconciliations/{id}` — Fetch single reconciliation with all related matches.
- [ ] Task 11: `PUT /api/bank-reconciliations/{id}` — Update reconciliation (name, notes, status changes).
- [ ] Task 12: `POST /api/bank-reconciliations/{id}/import-statement` — Upload statement file (CSV/OFX); returns parsed transactions and auto-match results.
- [ ] Task 13: `POST /api/bank-reconciliations/{id}/auto-match` — Run or re-run auto-matching algorithm; takes optional threshold override for confidence score.
- [ ] Task 14: `POST /api/bank-reconciliations/{id}/matches/{matchId}/approve` — Approve a pending-review match (operator overrides, adds notes).
- [ ] Task 15: `POST /api/bank-reconciliations/{id}/matches/{matchId}/reject` — Reject a match (sets to `rejected`, clears journalEntryId).
- [ ] Task 16: `POST /api/bank-reconciliations/{id}/matches/{matchId}/unmatch` — Unmatch an approved match (revert to `pending-review`).
- [ ] Task 17: `POST /api/bank-reconciliations/{id}/approve` — Approve entire reconciliation (sets status `reconciled`, records approver, locks for editing).
- [ ] Task 18: `POST /api/bank-reconciliations/{id}/archive` — Archive completed reconciliation (status `archived`).
- [ ] Task 19: `GET /api/bank-reconciliations/{id}/export-variance` — Export CSV variance report (unmatched transactions, variance summary, approval details).

## Statement Import & Parsing

- [ ] Task 20: Implement CSV import handler:
  - Accepts uploaded file, presents column mapping dialog to operator
  - Parses rows with configurable date/amount/reference/description columns
  - Validates row data: date format, amount numeric, presence of required fields
  - Deduplicates by (bankAccountId, date, amount, externalId) — rejects silently
  - Returns list of parsed transactions ready for matching
- [ ] Task 21: Implement OFX parser (optional for T1; can defer to T2):
  - Parses OFX/OFX2 format from bank exports
  - Extracts transaction list (date, amount, reference, description)
  - Maps to same internal format as CSV for uniform matching
- [ ] Task 22: Implement field mapping validator:
  - Ensures required columns (date, amount) are mapped
  - Validates sample rows (first 3 rows) before full import
  - Shows preview of parsed data (first 5 rows) to operator for confirmation

## Auto-Matching Algorithm

- [ ] Task 23: Implement auto-matching engine:
  - For each imported bank transaction:
    - Search APTransaction register where amount matches ±€0.01
    - AND transaction date within ±3 days (configurable per org)
    - AND (bank memo contains journal ref OR invoice# substring found)
  - Score calculation: +100 exact amount, +20 reference match, -10 per day past deadline
  - Create BankReconciliationMatch for each:
    - score ≥70: matchType `auto-matched`
    - 30 ≤ score <70: matchType `pending-review`
    - score <30: no match (journalEntryId = null)
- [ ] Task 24: Implement configurable thresholds per organization:
  - Store OrgSettings: auto_match_confidence_threshold (default 70), date_range_days (default 3), amount_tolerance (default 0.01)
  - Apply thresholds at match-time
- [ ] Task 25: Add algorithm metrics:
  - Log match run: timestamp, bank transaction count, auto-matched count, pending count, unmatched count
  - Store in audit trail (or return in API response)

## Manual Matching Interface

- [ ] Task 26: Implement bulk match approval for low-risk matches:
  - Add button "Approve All Pending (≥80% confidence)"
  - Filters pending matches where confidenceScore ≥80
  - Updates all matches: matchType `approved`, approvedBy = current user, approvedAt = now
  - Displays summary: "N matches approved, M skipped"

## Balance Calculation & Variance

- [ ] Task 27: Implement balance calculation:
  - reconciledBalance = openingBalance + sum(approved_matches.bankTransactionAmount)
  - variance = closingBalance - reconciledBalance
  - Store reconciledBalance, variance, matchedCount, unmatchedBankCount, unmatchedJournalCount in BankReconciliation
- [ ] Task 28: Implement variance severity indicator:
  - Green: variance < €0.01
  - Yellow: variance €0.01–10 (configurable threshold)
  - Red: variance > 10 OR closing < opening
  - Return severity in API response

## Reconciliation Approval & Lock

- [ ] Task 29: Implement approval flow:
  - POST /approve endpoint: validates all pending-review matches have been resolved (either approved or rejected)
  - Checks variance materiality (if >€100, may require manager approval — configurable)
  - Sets status `reconciled`, approvedBy, approvedAt
  - Stores variance explanation in notes
  - Locks reconciliation (prevent further edits; unmatching disabled)
- [ ] Task 30: Implement immutability enforcement:
  - Block PUT/PATCH on locked (reconciled) reconciliations
  - Block match updates (unmatch, reject) on locked reconciliations
  - Return 403 Forbidden with message "Reconciliation is locked; create new session to correct"

## Export & Reporting

- [ ] Task 31: Implement variance report export (CSV):
  - Header: reconciliation ID, period, opening/closing/reconciled balances, variance, status
  - Section: Unmatched Bank Transactions (date, amount, ref, description)
  - Section: Unmatched Journal Entries (transaction ID, date, amount, description, account)
  - Footer: approval summary (approver, timestamp, variance reason)
- [ ] Task 32: Implement audit export:
  - List all matches with creation/approval timestamps, operator notes
  - Show match type progression (auto-matched → approved, or pending-review → approved → rejected)

## Vue Frontend Components

- [ ] Task 33: Create `src/views/BankReconciliationIndex.vue`:
  - Use CnIndexPage + useListView
  - Display list of reconciliations (name, bank account, period, status, variance)
  - Add filter by status (draft, in-progress, reconciled, archived)
  - Add "New Reconciliation" button
  - Row click → BankReconciliationDetail
- [ ] Task 34: Create `src/views/BankReconciliationDetail.vue`:
  - Use CnDetailPage + CnDetailCard sections
  - Sections: Basic Info | Statement Import | Matched Transactions | Unmatched Transactions | Approval
  - Show reconciliation name, bank account, statement period, balances, variance indicator
  - Statement import card: upload button, show import status/counts
  - Matched transactions card: table of approved/auto-matched with operator notes
  - Unmatched card: two sub-tables (bank txns without match, journal entries without match)
  - Approval card (if status `draft|in-progress`): approve button with variance confirmation
  - Sidebar: CnObjectSidebar with files/notes/audit tabs
- [ ] Task 35: Create `src/modals/StatementImportDialog.vue`:
  - File upload input (CSV/OFX drag-drop)
  - Column mapping: present bank file columns, operator maps to system fields
  - Preview: show first 5 rows after mapping
  - Confirm button triggers import + auto-matching
  - Show import progress and results (X matched, Y pending, Z unmatched)
- [ ] Task 36: Create `src/modals/MatchDetailModal.vue`:
  - Show bank transaction (amount, date, reference, description)
  - Show candidate journal entries (list with amount, date, description)
  - Allow operator to click candidate to pair, or set to null (reject)
  - Text field for operator notes
  - Save button updates BankReconciliationMatch
- [ ] Task 37: Create `src/components/VarianceIndicator.vue`:
  - Display variance amount (EUR)
  - Color background: green (<€0.01), yellow (€0.01–10), red (>10)
  - Tooltip on hover: explanation of variance materiality
- [ ] Task 38: Create `src/components/MatchTypeTag.vue`:
  - Visual tag for matchType: `auto-matched` (blue), `pending-review` (orange), `approved` (green), `rejected` (red)

## Seed Data

- [ ] Task 39: Create 2–3 example BankReconciliation objects in `lib/Settings/shillinq_register.json`:
  - Reconciliation 1: Checking Account, May 2026, status `reconciled` (completed example)
  - Reconciliation 2: Reserve Account, April 2026, status `in-progress` (active example with unmatched items)
  - Use realistic Dutch amounts (€5,000–25,000 range)
- [ ] Task 40: Create 5–8 example BankReconciliationMatch objects:
  - Mix of `auto-matched` (score 95), `pending-review` (score 45), `approved`, `rejected` types
  - Link to example APTransaction records (invoices, payments)
  - Use realistic operator notes (e.g., "Matched by invoice reference in memo")

## Deduplication Check

- [ ] Task 41: Verify no duplicate functionality:
  - Reconciliation CONSUMES APTransaction, Payment, BankAccount (does not redefine)
  - Auto-matching algorithm is domain-specific; no overlap with ImportService or existing services
  - Report findings: "No overlap found. Reconciliation is new workflow layer; existing entities (APTransaction, Payment, BankAccount) are consumed but not duplicated."

## Testing & Validation

- [ ] Task 42: Unit tests for auto-matching algorithm:
  - Test exact amount match (€150 txn matches €150 invoice)
  - Test amount tolerance (€150.00 bank matches €150.01 invoice, score reduced)
  - Test date range (transaction 2026-05-22 matches invoice 2026-05-21, within ±3 days)
  - Test date out-of-range (transaction 2026-05-22, invoice 2026-05-10, score penalized or no match)
  - Test reference matching (bank memo "INV-2026-0521-ABC" matches journal ref "INV-2026-0521")
  - Test no reference (amount matches but date off by 5 days, score <70, pending-review)
- [ ] Task 43: Integration tests for balance calculation:
  - Test reconciledBalance = opening + matched sum
  - Test variance = closing - reconciled
  - Test variance indicators (green, yellow, red thresholds)
- [ ] Task 44: Browser tests (Playwright or Cypress):
  - REQ-BBR-001: Create reconciliation session; verify status `draft`
  - REQ-BBR-002: Import CSV; verify auto-matching results
  - REQ-BBR-003: Operator manually approves pending match
  - REQ-BBR-006: Approve reconciliation with non-zero variance; verify locked status
  - REQ-BBR-009: Archive reconciliation; verify moved to archive view

## API Documentation & Validation

- [ ] Task 45: Generate OpenAPI spec for all bank-reconciliation endpoints (POST /create, GET /list, POST /import-statement, POST /auto-match, POST /approve, POST /archive, GET /export-variance).
- [ ] Task 46: Input validation on all endpoints:
  - bankAccountId: required, must exist
  - statementStartDate, statementEndDate: valid date, start < end
  - openingBalance, closingBalance: numeric, ≥0
  - bankTransactionRef, bankTransactionAmount: non-null in match creation
  - operatorNotes: optional string, max 500 chars
- [ ] Task 47: Error responses:
  - 400 Bad Request: invalid date format, missing required field, amount < 0
  - 403 Forbidden: user lacks permission on bank account, reconciliation is locked
  - 404 Not Found: reconciliation ID or match ID not found
  - 409 Conflict: cannot approve reconciliation with unresolved pending matches

## Smoke Testing (Pre-PR Checklist)

- [ ] Verify new BankReconciliation can be created via API
- [ ] Verify CSV statement import works and creates matches
- [ ] Verify auto-matching confidence score is calculated correctly
- [ ] Verify operator can approve pending-review matches
- [ ] Verify reconciliation can be approved and locked
- [ ] Verify locked reconciliation rejects further edits (403)
- [ ] Verify variance report exports with correct format
- [ ] Verify seed data loads on app install (if applicable)
