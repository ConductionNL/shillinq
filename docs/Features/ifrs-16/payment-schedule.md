---
sidebar_position: 3
title: Payment schedule
description: How Shillinq materialises the IFRS 16 amortisation schedule, what each row contains, and how it feeds the general ledger.
---

# Payment schedule and GL posting

## The model

For every IFRS 16-capitalised lease, Shillinq materialises one `LeasePaymentSchedule` row per payment period. The row is computed by the pure-logic `LeaseAmortizationCalculator` (cents-only arithmetic, no IEEE-754 drift) so the same numbers are reproducible from the lease contract alone.

Every row carries:

| Field | Meaning |
|---|---|
| `periodSequence` | 1, 2, 3, … |
| `periodStart` / `periodEnd` | ISO dates derived from `commencementDate` + frequency. |
| `openingLeaseLiability` | The liability at the start of the period. |
| `paymentAppliedTotal` | The contractual payment for the period. |
| `interestAccrued` | `openingLiability × periodicRate` (IBR / periodsPerYear). |
| `paymentPrincipalPortion` | `paymentAppliedTotal − interestAccrued`. |
| `closingLeaseLiability` | `openingLeaseLiability + interestAccrued − paymentAppliedTotal`. |
| `depreciationCharge` | Straight-line over the term of the RoU asset. |
| `postedToGl` | FK to the GL transaction (filled by period-close, immutable thereafter). |

The closing liability of the **final** period is forced to exactly zero — the calculator absorbs any rounding into that row's principal so the schedule lands cleanly.

## What hits the GL

For each period the period-close engine posts:

```
Dr. Interest expense              <interestAccrued>
   Cr. Lease liability               <interestAccrued>

Dr. Lease liability               <paymentPrincipalPortion>
   Cr. Bank                          <paymentPrincipalPortion>
  (payment leg goes through the bank reconciliation)

Dr. Depreciation                  <depreciationCharge>
   Cr. Accumulated depreciation      <depreciationCharge>
```

Every line carries `sourceLease=<lease-id>` so the audit trail can be walked end-to-end (ADR-022).

## Preview

The Lease Detail page shows the **next 12 months** of the schedule inline; the full set is downloadable via the **Audit pack** action (see [Disclosures](./disclosures.md#audit-pack)).

## Regeneration

If the lease's economics change (extension reassessment, indexation, modification), `LeasePaymentScheduleService::generateSchedule(leaseId, administrationId, fromSequence)` regenerates rows from `fromSequence` forward. Existing posted rows are protected by the immutability rule on `LeasePaymentSchedule` — only future periods are overwritten.

## Notes

- Schedule generation skips exempt and out-of-scope leases (no rows).
- The schedule is administration-scoped via the lease contract; cross-administration reads are blocked at the service layer (ADR-005 IDOR safety).
- The payment frequency choices map to fixed periods-per-year (`monthly=12`, `quarterly=4`, `annual=1`); irregular schedules fall back to monthly cadence and adjust per-period payments at activation.
