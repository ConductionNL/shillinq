---
sidebar_position: 2
title: Create a lease
description: Register a lease contract in Shillinq, classify it per IFRS 16, and activate the draft to trigger RoU asset and lease-liability recognition.
---

# Create and classify a lease

## When to use this

Every contract that conveys the right to use an identified asset for more than 12 months — vehicles, real estate, IT hardware, machinery — gets a `LeaseContract` record. Short-term and low-value leases are still registered, but they post straight-line expense rather than RoU / liability (see [Exemptions](./exemptions.md)).

## Steps

1. **Lease Register → New Lease.** Pick the administration scope; the rest of the form is auto-scoped.
2. **Identification.** Fill in `lease-number` (your own scheme, e.g. `VH-2026-001`), `lessor` (counterparty from the `organisations` register), `description`.
3. **Asset and dates.** Choose `assetClass` (vehicle / real-estate / IT-hardware / machinery / other), `commencementDate`, `endDate`, `nonCancellableTermMonths`. Add `extensionOptions` with each option's months and exercise likelihood (`possible`, `reasonably-certain`, `unlikely`).
4. **Payments.** Pick `paymentFrequency` (monthly / quarterly / annual) and `paymentTiming` (in-advance / in-arrears), then enter `basePaymentAmount` and `paymentCurrency`.
5. **IBR.** Enter `ibrPercent` and the derivation method (group-policy-matrix / yield-curve-plus-spread / weighted-average-external-debt / external-quote). Attach the derivation evidence (docudesk document FKs) in `ibrEvidenceDocuments` — the audit pack will pull them.
6. **Classification.** Pick one of `IFRS16-capitalised`, `short-term-exempt`, `low-value-exempt`, `operating-pre-IFRS16`. The classification engine validates the choice against the asset class and term.
7. **Restoration obligation** (optional). Add `estimatedCost` and `discountRate` if the contract obliges you to restore the asset at end-of-lease.
8. **Activate.** Promote the lease from `draft` to `active`. The activation guard (`LeaseContractGuard::guardActivation`) verifies the classification is one of the four IFRS 16 enum values and every economic field is present (REQ-LC-004). If any check fails, the lease stays in `draft` and the missing fields are surfaced.

## What happens on activation

For IFRS 16-capitalised leases:

- `LeaseRecognitionService::recognise` computes the opening lease liability (present value of the unavoidable payments under the IBR) and the opening RoU asset (= liability + initial-direct-costs + restoration-PV − incentives − prepaid-rent + accrued-rent).
- A fixed-asset record is created with `isRouAsset=true` and `sourceLease` FK pointing back at the contract; the fixed-asset depreciation engine takes over from there.
- A balanced journal entry is staged for the period-close engine: Dr. RoU asset, Cr. lease-liability-noncurrent (plus a Cr. lease-restoration-obligation line when present).
- `LeasePaymentScheduleService::generateSchedule` materialises one `LeasePaymentSchedule` row per period (opening / closing liability, interest, principal split, depreciation).

For exempt leases, no schedule is written; the period-close engine posts a straight-line expense each period to the GL.

## Where it lives

- Lease Register page: `Bookkeeping → IFRS 16 Leases → Lease Register`.
- Lease Detail page: contract summary, IBR derivation, payment-schedule preview (next 12 months), reassessment history, GL posting totals.

## Troubleshooting

- **"Activation blocked: classification missing"** — pick one of the four IFRS 16 enum values.
- **"Activation blocked: missing economic fields"** — open the detail view, the missing fields are listed. Common omissions: `paymentTiming`, `paymentCurrency`, `ibrPercent` on exempt leases (zero is acceptable, blank is not).
- **"Schedule rows = 0"** — only IFRS16-capitalised leases produce a schedule; exempt and out-of-scope leases write nothing.
