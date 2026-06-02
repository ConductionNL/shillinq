---
kind: code
depends_on: [bookkeeping-purchase-order-3way-07-multi-po-consolidation]
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

# Proposal: bookkeeping-purchase-order-3way-08-exception-workflow

Member 8 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-07-multi-po-consolidation`.
This `kind: code` member implements the **exception resolution workflow**
(REQ-PO3W-005): out-of-tolerance matches route to the
crediteuren-administrateur with a side-by-side comparison and three
resolution actions.

## Why (carried from the giant)

REQ-PO3W-005: an invoice €750 (4.1%) over its PO exceeds tolerance and is
marked exception_price by the engine (member 06). The
crediteuren-administrateur receives a notification with a side-by-side
PO/GRN/Invoice comparison and chooses: accept-with-motivation, file
dispute (auto-generate a UBL CreditNote request), or reject-and-block.
Payment is blocked until resolved. This is the forensic-control half of
the "99% automation" promise.

## What this member does

- `ExceptionResolutionService`: `acceptWithMotivation()`, `fileDispute()`
  (auto-generate UBL CreditNote via openconnector, escalate to Inkoper),
  `rejectAndBlockPayment()` (reverse partial GR/IR, restore stock)
- Notification integration: alert crediteuren-administrateur on
  match_status = exception_*, deep-link to the panel
- `ThreeWayMatchExceptionPanel.vue` (side-by-side PO↔GRN↔Invoice,
  divergence detail, three action buttons + motivation input)
- Unit tests for each resolution path; integration test for full
  exception → resolution flow

## Scope

### In Scope
- `ExceptionResolutionService` (accept / dispute / reject)
- Notification wiring for exception alerts
- `ThreeWayMatchExceptionPanel.vue`
- Exception-resolution unit + integration tests

### Out of Scope
- Match evaluation + exception detection — member 06 (predecessor chain)
- GL settlement posting — member 09
- Vendor scoring, audit export — members 10-11

## Impact
- `lib/Service/ExceptionResolutionService.php`
- `src/components/ThreeWayMatchExceptionPanel.vue`
- `tests/` exception resolution

## Cross-Project Dependencies
- **openconnector** — UBL CreditNote request generation for disputes
