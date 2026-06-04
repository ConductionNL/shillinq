# Proposal: bookkeeping-bank-reconciliation

`kind: feature` — bank reconciliation workflow for matching bank statement transactions against accounts payable/receivable journal entries, with automatic matching, manual review, and variance resolution.

## Summary

Introduce bank reconciliation capability for matching bank transactions (from bank statements and external integrations) against recorded financial transactions (APTransaction journal entries, payments, receipts). Support automatic matching by amount + date, manual exception handling, transaction matching/unmatching, and variance reporting. Integration with external accounting software (Peppol, SAP) via the integration registry.

The capability materialises three primary workflows:
1. **Statement import** — Load bank transactions from CSV/OFX/external API.
2. **Auto-matching** — Match bank transactions to journal entries by amount, date, reference.
3. **Manual reconciliation** — Operators resolve unmatched transactions, approve/reject matches, document variance.

Conforming to ADR-001 (OpenRegister data model), ADR-015 (deduplication/reuse analysis), and ADR-031 (declarative business rules).

## Motivation

Market intelligence (tender analysis across Dutch SMB sector, 21 competitors) confirms 100% of analysed operators manage bank reconciliation manually — comparing bank statements line-by-line against journal entries. Today this is spreadsheet-based (Excel pivot tables, manual lookup) or bespoke accounting software integrations. Five features reached high demand across tenders:

1. **Reconciliation workflow with matched/unmatched transaction review** (demand: 331, 109 mentions)
2. **Bank reconciliation workflow with unmatched item resolution** (demand: 281, 93 mentions)
3. **Accounting Software Integration for Payment Reconciliation** (demand: 264, 62 mentions)
4. **Bank reconciliation workflow with automatic and manual matching** (demand: 221, 69 mentions)
5. **Bank reconciliation with statement balance verification workflow** (demand: 215, 69 mentions)

This is a core T1 capability: CFO/bookkeeper workflow, compliance-critical (reconciliation is a audit control), high-touch (monthly or daily for large organizations).

## Affected Projects

- [x] Project: shillinq (budgetq) — adds 1 capability spec (`bookkeeping-bank-reconciliation`); declares 2 new schemas (`BankReconciliation`, `BankReconciliationMatch`); adds manifest navigation entry (Bank Reconciliation).
- [x] Project: shillinq-integrations — future change; integrates with Peppol, SAP, Rabobank API for automated statement import.
- [ ] Project: openregister — no source changes; consumes existing validation + calculation engine (ADR-031).

## Scope

### In Scope

- One new capability spec (`bookkeeping-bank-reconciliation`) — see `specs/` folder.
- **BankReconciliation** register: represents a reconciliation session for a bank account, fiscal period (month/quarter). Tracks:
  - Bank account reference (FK to BankAccount)
  - Statement period (startDate, endDate)
  - Opening balance (per statement)
  - Closing balance (per statement)
  - Current reconciled balance (matches statement)
  - Total unmatched variance
  - Status (draft, in-progress, reconciled, archived)
- **BankReconciliationMatch** register: represents one matched pair (bank transaction ↔ journal entry). Tracks:
  - Bank transaction (FK or embedded reference)
  - Journal entry (FK to APTransaction, GLLine, or Payment)
  - Match status (auto-matched, pending-review, approved, rejected)
  - Match confidence/score (for auto-matching)
  - Manual notes from approver
- **Auto-matching algorithm**: deterministic matching by amount, date range (±3 days), reference number or memo text (substring). Configurable thresholds per organization.
- **Manual matching UI**: drag-drop or click-to-match interface for operator to pair unmatched transactions.
- **Variance reporting**: summary of unmatched transactions by category (amount mismatch, date mismatch, orphaned bank tx, orphaned journal entry). Export as CSV.
- **Statement import**: CSV/OFX file upload with smart field mapping (bank provides column names → system maps to BankTransaction model).
- **Balance verification**: system confirms reconciled balance + unmatched variance = statement closing balance (basic audit check).

### Out of Scope

- **Real-time bank feed**: direct API polling from bank (Rabobank, ING) — future integration spec.
- **Multi-currency reconciliation**: assumes EUR base; multi-currency roadmap item.
- **Scheduled reconciliation**: recurring monthly reconciliation automation — future workflow enhancement.
- **Reconciliation reversal/correction**: once approved, reconciliation is locked (immutable for audit). Corrections require new reconciliation session.
- **Intercompany reconciliation**: single-entity only; multi-entity consolidation is separate spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-bank-reconciliation`** — declares the two registers (BankReconciliation, BankReconciliationMatch), auto-matching algorithm with configurable thresholds, manual matching interface, variance reporting, and statement import workflow.

Each requirement is prefixed `REQ-BBR-*` for traceability.

## New Dependencies

- Consumes OpenRegister abstractions: data model (ADR-001), ObjectService CRUD, ImportService for CSV upload, validation engine (ADR-031), calculations (ADR-031).
- Requires @conduction/nextcloud-vue@^1.0.0 (existing); CnDataTable, CnDetailPage, CnFormDialog for UI.
- **No new PHP dependencies** — uses existing platform libraries.
