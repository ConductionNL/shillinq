# grir-accrual-wiring Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- grir-accrual-wiring (2026-07-13, archived)

## Purpose

Wires the missing GR/IR accrual-posting trigger into shillinq's
`bookkeeping-purchase-order-3way` chain. `GRIRClearingService::createGRIRPosting()`
and `settleGRIRPosting()` (member 09, REQ-PO3W-009) already correctly
materialise the balanced clearing and settlement GL postings, but nothing
ever called either method — goods/service receipts accepted and matched
invoices approved without a single GR/IR entry reaching the ledger. This
capability closes that gap: a `GoodsReceiptNote`/`SvcReceipt` accept posts
the clearing entry, and a `SupplierInvoice` reaching `matched` posts the
settlement. No posting logic is duplicated — this capability is purely the
missing trigger, fulfilling REQ-PO3W-009's own scenario as originally
specified.

## Requirements

### Requirement: REQ-001: An accepted GoodsReceiptNote MUST post the GR/IR clearing entry for every accepted line

When a `GoodsReceiptNote` transitions to `accepted`, the system MUST call
`GRIRClearingService::createGRIRPosting()` for every `GoodsReceiptLine`
belonging to that GRN with `quantityAccepted > 0`, materialising a balanced
GLTransaction (Dr PO-line `glAccount` / Cr GR/IR clearing account) per
line. Lines with `quantityAccepted <= 0` MUST be skipped without error.

#### Scenario: Accepting a GRN with one accepted line posts the clearing entry

- **GIVEN** a `GoodsReceiptNote` in `quality_checked` with one
  `GoodsReceiptLine` (`quantityAccepted: 180`, PO line `glAccount: "1200"`,
  `unitPrice: 10278` cents)
- **WHEN** the GRN transitions to `accepted`
- **THEN** exactly one balanced `GLTransaction` MUST be materialised: Dr
  `1200` / Cr the configured GR/IR clearing account, both for 1850040
  cents.

@e2e exclude pure backend/lifecycle-listener GL posting — not
browser-testable; proven by `GRIRClearingListenerTest`.

### Requirement: REQ-002: An accepted SvcReceipt MUST post the GR/IR clearing entry for every accepted line

When a `SvcReceipt` transitions `confirmed → accepted`, the system MUST
call `GRIRClearingService::createGRIRPosting()` for every `SvcReceiptLine`
belonging to that receipt with `quantityAccepted > 0`, using the same
posting mechanics as REQ-001 (SvcReceiptLine reuses GoodsReceiptLine's
field names by design).

#### Scenario: Accepting a SvcReceipt with one accepted line posts the clearing entry

- **GIVEN** a `SvcReceipt` in `confirmed` with one `SvcReceiptLine`
  (`quantityAccepted` derived from `percentageComplete`)
- **WHEN** the receipt transitions to `accepted`
- **THEN** exactly one balanced `GLTransaction` MUST be materialised: Dr
  the PO service line's `glAccount` / Cr the configured GR/IR clearing
  account for the line's accepted value.

@e2e exclude pure backend/lifecycle-listener GL posting — not
browser-testable; proven by `GRIRClearingListenerTest`.

### Requirement: REQ-003: A SupplierInvoice reaching `matched` MUST post the GR/IR settlement entry

The system MUST post the GR/IR settlement entry when a `SupplierInvoice`
reaches `matched`. When a `SupplierInvoice` transitions
`matching → matched` (the existing `ThreeWayMatchingEngine::evaluateMatch()`
trigger for an `auto_approved` or `within_tolerance` match), the system
resolves the driving `ThreeWayMatch` record and calls
`GRIRClearingService::settleGRIRPosting()`, materialising a balanced
settlement `GLTransaction` (Dr GR/IR clearing + Dr VAT Payable / Cr
Accounts Payable). When no `auto_approved`/`within_tolerance`
`ThreeWayMatch` is found for the invoice, no posting is attempted and no
error is raised.

#### Scenario: A matched invoice settles the GR/IR clearing balance

- **GIVEN** a `SupplierInvoice` with an `auto_approved` `ThreeWayMatch`
  referencing it, and a prior GR/IR clearing posting from REQ-001 for the
  same value
- **WHEN** the invoice transitions to `matched`
- **THEN** a balanced settlement `GLTransaction` MUST post (Dr GR/IR
  clearing / Cr Accounts Payable + VAT Payable), and the GR/IR clearing
  account's net balance (clearing credit + settlement debit) MUST equal
  zero.

@e2e exclude pure backend/lifecycle-listener GL posting — not
browser-testable; proven by `GRIRClearingListenerTest`.

### Requirement: REQ-004: GR/IR posting failures MUST NOT block the receipt-accept or invoice-match transition

The system MUST NOT let a GR/IR posting failure block the triggering
transition. Any exception raised while resolving or posting the GR/IR
clearing or settlement entry (missing GL account configuration, a
transient `ObjectService` failure, etc.) is logged and does not prevent
the triggering `GoodsReceiptNote`/`SvcReceipt`/`SupplierInvoice`
transition from completing, matching the fail-soft contract already
established by `DeliveryDispatchListener` and
`StockMoveTransitionedListener`.

#### Scenario: A downstream posting failure does not block GRN acceptance

- **GIVEN** `GRIRClearingService::createGRIRPosting()` throws (e.g. the PO
  line cannot be resolved)
- **WHEN** the GRN accept transition fires
- **THEN** the GRN transition itself still succeeds, and the failure is
  logged via the listener's fail-soft `catch`.

@e2e exclude pure backend/lifecycle-listener fail-soft contract — not
browser-testable; proven by `GRIRClearingListenerTest`.
