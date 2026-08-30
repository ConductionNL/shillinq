---
kind: code
depends_on: [bookkeeping-purchase-order-3way-08-exception-workflow]
chain:
  - bookkeeping-purchase-order-3way-01-schemas-and-registers
  - bookkeeping-purchase-order-3way-02-purchase-order-core
  - bookkeeping-purchase-order-3way-03-peppol-transmission
  - bookkeeping-purchase-order-3way-04-goods-receipt-note
  - bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion
  - bookkeeping-purchase-order-3way-06-matching-engine
  - bookkeeping-purchase-order-3way-07-multi-po-consolidation
  - bookkeeping-purchase-order-3way-08-exception-workflow
  - bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing
  - bookkeeping-purchase-order-3way-10-vendor-performance
  - bookkeeping-purchase-order-3way-11-audit-trail-export
---

# Proposal: bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing

Member 9 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-08-exception-workflow`. This
`kind: code` member implements the **GR/IR clearing GL postings**
(REQ-PO3W-009): a balanced clearing posting at GRN-accept time and a
settlement posting at invoice-approval time, per IFRS goods-in-receipt.

## Why (carried from the giant)

REQ-PO3W-009: when a GRN is accepted, the system must materialise a
balanced posting DR Inventory / CR GR/IR Clearing for the line amount.
When the ThreeWayMatch is approved, a second posting settles the clearing:
DR GR/IR Clearing / CR Accounts Payable + VAT Payable. The GR/IR control
account must reconcile to zero at period-end (no dangling goods-in-transit).
This is the accounting backbone that makes "no GRN → no invoice approval"
enforceable.

## What this member does

- `GRIRClearingService`: `createGRIRPosting()` (on GRN accept — DR
  gl_account / CR GR/IR clearing account), `settleGRIRPosting()` (on
  invoice approval — DR GR/IR clearing / CR AP + VAT); both preserve
  cost_center + project_code from the PO line
- GL account configuration: GR/IR clearing account code, configurable per
  ToleranceProfile (optional override)
- Unit tests (balanced entries, cost-center preservation, settlement);
  integration tests (GRN accept → clearing, invoice approval → settlement,
  period-end GR/IR saldo reconciles to zero)

## Scope

### In Scope
- `GRIRClearingService` (clearing + settlement postings)
- GR/IR clearing account configuration
- GL posting unit + integration tests + saldo reconciliation

### Out of Scope
- GRN capture (which fires the trigger) — member 04
- Match approval (which fires settlement) — members 06-08
- Vendor scoring, audit export — members 10-11

## Impact
- `lib/Service/GRIRClearingService.php`
- GL account configuration in app config
- `tests/` GR/IR posting + settlement + reconciliation

## Cross-Project Dependencies
- **T1 general-ledger** — JournalEntry materialisation for GR/IR clearing
- **bookkeeping-accounts-payable-core (T2)** — AP liability + VAT settlement
