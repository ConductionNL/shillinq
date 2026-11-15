---
kind: code
depends_on: []
---

# Proposal: billing-inherited-defects

## Summary

Five defects the round 2 and 3 lanes found in shillinq's billing code and left as
inherited: the portal payment looks an invoice up in a way OpenRegister never
answers, the recurring generator writes and guards on fields ARInvoice does not
declare and omits two it requires, three writers lose a field OpenRegister drops,
and the dunning template registry has no caller. This change fixes each one, each
with a test that fails first.

## Motivation

Recorded in `sq-fees/LANE-LOG-r3.md` (change 2, INHERITED noted) and confirmed on
`development` at af077af86, 2026-09-28:

1. `PortalPaymentSessionService::findOwnedPayableInvoice()` runs `findAll()` with
   a filter on `id`. OpenRegister's `filters` address JSON properties and `id` is
   the entity column, so the filter matches nothing (see the note on
   `ObjectIdentifier::findOne()`). An invoice addressed by its uuid, which is how
   the portal pay action addresses it, could never be paid. The unit test's stub
   matched `id` like any property, so it stayed green.
2. `RecurringInvoiceGenerator` writes `recurringProfileId` and `billingPeriod`,
   which ARInvoice does not declare, and its double-billing guard filters on
   them. Live, OpenRegister drops both, the guard never finds the earlier invoice
   and a second run bills the period again. It also sends no `invoiceNumber` and
   no `periodId`, which ARInvoice lists as required.
3. The quick draft writes `customerReference`, the payment request leaf API writes
   `requestedBy`, and neither schema declares them, so both are dropped. The quick
   draft's GL account never reaches a line because `invoiceLines` items declare no
   account (it was left out on purpose in `arinvoice-lines-and-portal-amounts`).
4. `DunningTemplateRegistry` holds the default template id per stage with an app
   config override, but nothing calls it. The voluntary reminder letter (D28) only
   covers the voluntary contribution, so an ordinary run whose ladder stage names
   no template still records an empty template id.

## Affected Projects

- shillinq only. portaliq's pay action keeps its contract (the invoice id).

## Scope

In: the invoice lookup, the ARInvoice and PaymentRequest declarations (version
bumps), the recurring payload, the quick draft line account, the registry wiring.
Out: mapping `customerReference` to UBL BT-10, back-filling invoices written before
this change (the dropped values are gone).

## Approach

- Read the invoice with `find()` by uuid; on a miss fall back to the `slug`
  property; check ownership and state on the record.
- Declare `recurringProfileId`, `billingPeriod` and `customerReference` on
  ARInvoice and `glAccount` on its lines (ARInvoice 0.16.0); declare `requestedBy`
  on PaymentRequest (0.6.0). Keeping the guard on the provenance fields is the
  smaller change once they are declared.
- The generator writes `periodId` (the billing period) and a deterministic
  `invoiceNumber` `REC-<yyyymm>-<profile 8>-<nn>`, where `nn` counts earlier
  invoices of that profile and period so a regenerated invoice after a
  cancellation gets its own number.
- Wire the registry: `DunningRunService::executeStage()` falls back to the
  registry's template id for the stage when neither the caller, the ladder stage
  nor the voluntary letter named one.

## New Dependencies

None.

## Impact

Schema versions move; OpenRegister re-imports on the fragment signature. No data
migration.

## Cross-Project Dependencies

None.

## Risks

- A deterministic invoice number is not the final sequential number; the draft
  flow already treats provisional numbers this way (the quick draft's `DRAFT-`).

## Rollback Strategy

Revert the PR; the added properties are nullable and additive.
