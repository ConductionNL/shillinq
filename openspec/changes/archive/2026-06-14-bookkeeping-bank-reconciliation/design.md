# Design — Bank Reconciliation

## Context

Dutch SMB bookkeepers reconcile bank accounts monthly (or daily for high-volume operations). Today this is manual: download statement CSV from bank, import into Excel, match transactions line-by-line against journal entries, document discrepancies. Pain points:

- **Time-intensive**: 30-60 minutes per account per month (more for multi-account corps).
- **Error-prone**: manual lookup by amount/date/reference is slow and misses matches.
- **Audit gap**: variance documentation is inconsistent, not machine-readable.
- **Integration gap**: no standard bridge from bank statements → accounting journal; every integration is custom.

Market research (21 competitors, 109-93 tender mentions) shows all competitors offer automated matching + manual exception handling. Dutch government (VNG, municipalities) also requires documented reconciliation as internal control.

Per ADR-001, all domain data lives in OpenRegister. Per ADR-031, business rules (matching algorithm, variance calculation) are declarative, not PHP services. This design locks those decisions into the spec.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline.

## Goals

- Express bank reconciliation surface as **declarative data model** — two registers (BankReconciliation, BankReconciliationMatch) + validation rules + auto-matching algorithm — per ADR-001 and ADR-031.
- Make reconciliation **auditable end-to-end**: every match decision (auto-approved, operator-approved, rejected) is timestamped and attributed.
- Support **monthly close process**: bookkeeper can reconcile all accounts in one afternoon, export variance report, sign off for audit.
- Integrate with **external accounting software** (Peppol, SAP, Rabobank API) via the integration registry; statement import is pluggable.
- Keep the data model **extensible** so future accounting specs (multi-currency, intercompany netting) can reference BankReconciliation.

## Non-Goals

- **Real-time bank feeds** — direct polling from Rabobank/ING API. T2 integration spec; this change assumes CSV/OFX import.
- **Multi-currency reconciliation** — assumes EUR base. Multi-currency roadmap item for T3.
- **Scheduled auto-reconciliation** — monthly reconciliation automation. T2 workflow enhancement; manual trigger only for T1.
- **Reconciliation correction/reversal** — once approved, reconciliation is locked for audit trail. Amendments require new session.
- **Multi-entity/intercompany reconciliation** — single-entity scope; consolidation in separate spec.
- **Bank account management UI** — assumed to exist in prior spec (bookkeeping-bank-accounts); this spec only consumes.

## Decisions

### D1 — Two registers: BankReconciliation (session) + BankReconciliationMatch (pairing)

**BankReconciliation** represents one reconciliation session: one bank account, one fiscal period (e.g., Jan 2026), one statement import. Contains:
- Opening balance (statement start)
- Closing balance (statement end)
- Reconciled balance (auto-calculated: opening + matched transactions)
- Unmatched variance (closing - reconciled)
- Status tracking (draft → in-progress → reconciled → archived)

**BankReconciliationMatch** represents one matched pair: a bank transaction paired with a journal entry (APTransaction, GLLine, or Payment). Contains:
- Reference to bank transaction (embedded or FK)
- Reference to journal entry
- Match type (auto-matched, pending-review, approved, rejected)
- Confidence/score (for auto-matching threshold tuning)
- Operator notes

**Alternative considered**: Single monolithic `BankTransaction` with embedded matches. Rejected — separate registers enable:
1. Multiple journal entries can match to one bank transaction (e.g., deposit aggregates multiple invoices).
2. Match lifecycle is independent of bank transaction lifecycle (operator can reject/re-match).
3. Clearer audit trail per decision.

### D2 — Auto-matching deterministic, operator review of exceptions

Auto-matching algorithm:
- Exact amount match + date within ±3 days + reference substring (bank memo contains invoice# or payment ref).
- Single match per bank transaction (greedy-first algorithm).
- Confidence score: +100 exact match, -10 per day past deadline, +20 if reference text found.
- Threshold: ≥70 confidence = auto-approved; <70 = pending operator review.

Operator interface:
- List unmatched bank transactions + list unmatched journal entries.
- Operator can manually pair (drag-drop or click), set confidence override, approve/reject.
- Bulk actions: reject all orphaned transactions >30 days old; approve all remaining pending if variance <€0.01.

**Alternative considered**: Fully automatic reconciliation (operator never sees). Rejected — audit controls require human sign-off. Variance exceptions (unmatched transactions) must be operator-documented.

### D3 — BankReconciliationMatch embeds transaction reference, not full object

BankReconciliationMatch contains:
```json
{
  "bankTransactionRef": "TXN-2026-05-21-001",
  "bankTransactionAmount": 150.00,
  "journalEntryId": "apl-tx-uuid",
  "matchType": "approved",
  "confidenceScore": 95,
  "operatorNotes": "Matched by invoice reference #INV-2026-0521"
}
```

Bank transaction is NOT embedded as full object (avoids duplication, eases updates). System can fetch full transaction from external API or prior import at display time.

**Alternative considered**: Embed full bank transaction object. Rejected — bank transactions are external (from CSV, from API); embedding couples reconciliation to import format.

### D4 — Statement import via CSV/OFX + smart column mapping

Statement import:
1. Operator uploads CSV or OFX file.
2. System presents field mapping dialog: "Bank provides: [Date | Amount | Reference | Description]" → "Map to: [bookingDate | amount | invoiceRef | memo]".
3. System validates: date is parseable, amount is numeric, validates sample rows.
4. System imports rows as BankTransaction objects, keyed by (accountId, date, amount, reference) for deduplication.
5. Once imported, auto-matching runs.

**Alternative considered**: Hard-coded column detection (scan first row for keywords). Rejected — column order varies by bank; operator mapping is explicit + auditable.

### D5 — Balance verification as a "soft check", not a gate

System calculates:
```
reconciled_balance = opening_balance + sum(approved_matches.amount)
variance = closing_balance - reconciled_balance
```

If variance ≠ 0, system flags it:
- Green: variance < €0.01 (rounding OK)
- Yellow: variance 0.01–10 EUR (operator reviews, likely unmatched transaction)
- Red: variance > 10 EUR or closing < opening (data error; operator must investigate)

Operator can **approve reconciliation with non-zero variance** — system documents the variance and stores it for audit. Closing the reconciliation signals "I have reviewed unmatched items and accept this variance."

**Alternative considered**: Gate reconciliation on variance = 0. Rejected — real-world bank statements often have timing differences (check clearing delays, bank fees); forcing zero variance would lock out valid reconciliations.

## Seed Data

### BankReconciliation (Example Objects)

```json
{
  "@self": {
    "register": "shillinq",
    "schema": "BankReconciliation",
    "slug": "reconciliation-2026-05-abc-checking"
  },
  "name": "ABC Checking — May 2026",
  "bankAccountId": "bank-acct-abc-checking",
  "statementStartDate": "2026-05-01",
  "statementEndDate": "2026-05-31",
  "openingBalance": 5000.00,
  "closingBalance": 6250.50,
  "reconciledBalance": 6250.49,
  "variance": 0.01,
  "matchedCount": 12,
  "unmatchedBankCount": 0,
  "unmatchedJournalCount": 1,
  "status": "reconciled",
  "approvedBy": "user-acc-001",
  "approvedAt": "2026-06-02T14:30:00Z"
}
```

```json
{
  "@self": {
    "register": "shillinq",
    "schema": "BankReconciliation",
    "slug": "reconciliation-2026-04-xyz-reserve"
  },
  "name": "XYZ Reserve — April 2026",
  "bankAccountId": "bank-acct-xyz-reserve",
  "statementStartDate": "2026-04-01",
  "statementEndDate": "2026-04-30",
  "openingBalance": 25000.00,
  "closingBalance": 24850.00,
  "reconciledBalance": 24840.00,
  "variance": 10.00,
  "matchedCount": 8,
  "unmatchedBankCount": 1,
  "unmatchedJournalCount": 0,
  "status": "in-progress",
  "approvedBy": null,
  "approvedAt": null
}
```

### BankReconciliationMatch (Example Objects)

```json
{
  "@self": {
    "register": "shillinq",
    "schema": "BankReconciliationMatch",
    "slug": "match-2026-05-001-inv2026-0521"
  },
  "reconciliationId": "reconciliation-2026-05-abc-checking",
  "bankTransactionRef": "TXN-2026-05-21-001",
  "bankTransactionAmount": 150.00,
  "journalEntryId": "apl-tx-uuidABC123",
  "journalEntryDescription": "Invoice #INV-2026-0521 — Consulting Services",
  "matchType": "approved",
  "confidenceScore": 95,
  "operatorNotes": "Matched by invoice reference in memo field",
  "createdAt": "2026-05-22T08:15:00Z",
  "createdBy": "user-acc-001"
}
```

```json
{
  "@self": {
    "register": "shillinq",
    "schema": "BankReconciliationMatch",
    "slug": "match-2026-05-002-pending-review"
  },
  "reconciliationId": "reconciliation-2026-05-abc-checking",
  "bankTransactionRef": "TXN-2026-05-25-002",
  "bankTransactionAmount": 500.00,
  "journalEntryId": null,
  "journalEntryDescription": null,
  "matchType": "pending-review",
  "confidenceScore": 45,
  "operatorNotes": "Awaiting operator decision: amount matches 3 invoices; unclear which",
  "createdAt": "2026-05-25T10:00:00Z",
  "createdBy": "system-auto-match"
}
```

## Reuse Analysis

- **OpenRegister ObjectService**: used for CRUD on BankReconciliation and BankReconciliationMatch. No custom service class.
- **ImportService**: leveraged for CSV/OFX statement import with field mapping.
- **ValidationEngine (ADR-031)**: used for amount/date validation on imports.
- **@conduction/nextcloud-vue**: CnIndexPage (reconciliation list), CnDetailPage (detail view with match pairs), CnDataTable (unmatched transactions), CnFormDialog (manual matching).
- **No overlap** with existing services; reconciliation is domain-specific to bookkeeping and does not duplicate Obligation, Payment, or APTransaction workflows.

## Deduplication Check

- **vs. APTransaction**: reconciliation consumes APTransaction (journal entries) but does not duplicate it. Reconciliation is a workflow layer; APTransaction is the data.
- **vs. Payment**: reconciliation consumes Payment records for cash-basis reconciliation but does not replace payment tracking.
- **vs. existing bank account management**: assumes BankAccount register exists (prior spec). Reconciliation only consumes it.
- **Finding**: No duplicate functionality identified. Reconciliation is a new workflow that bridges bank statements ↔ journal entries.
