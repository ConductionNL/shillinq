# Design: ar-billing-completeness

## Per-item verify verdicts (against HEAD)

| Item | Verdict | Evidence at HEAD |
|------|---------|------------------|
| multicurrency-ar-fx-gainloss | **BUILD (realised leg)** | Foreign-currency AR issuance EXISTS (`ARInvoice.currency` in `add-shillinq-bookkeeping-compliance.json`); `FxRate` register + `GLLine` FX overlay EXIST (`add-shillinq-multi-currency-t4.json`); UNREALISED period-end reval EXISTS (`FxRevaluationService`, #403). REALISED-on-settlement posting ABSENT: `PaymentReconciliationService::settleLinkedInvoice()` flips `ARInvoice.state -> paid` at book value and `grep -rniE 'realis\|realiz' lib/**/*.php` on settlement/payment code returns nothing. Built. |
| usage-metered-billing | **BUILD** | No `meter`/`usage`/`metered`/`rated` schema or service anywhere in `lib/` (`find lib -iname '*meter*' -o -iname '*usage*'` = empty). Recurring + retainer EXIST but neither is consumption-metered. Built minimal meter->rate->line feeding the existing `InvoiceGenerationService`. |

## Item 1 — realised FX on settlement

**Model.** A foreign-currency `ARInvoice` for `foreignAmount` is booked at
invoice-date rate `R0` (functional value `foreignAmount x R0`). At collection
the same amount is worth `foreignAmount x R1` at payment-date rate `R1`. The
realised difference is `foreignAmount x (R1 - R0)` in functional currency:
positive = gain (foreign currency strengthened), negative = loss.

**Balanced GL invariant.** The realised-FX entry stands alone as a
self-balancing two-line `GLTransaction` (same `postings[]` shape
`InvoiceGenerationService::postInvoice()` emits):
- GAIN: `Dr AR-control 1130 |diff|` / `Cr realised-gain 8022 |diff|`
- LOSS: `Dr realised-loss 8023 |diff|` / `Cr AR-control 1130 |diff|`

`debit == credit == |diff|` is asserted in the service before persistence; an
unbalanced entry is refused and logged, never written. The realised accounts
(`8022`/`8023`) are deliberately distinct from the unrealised `8020`/`8021`
pair so the two FX legs never conflate. All three accounts are `IAppConfig`
overridable (`fx_realised_gain_account`, `fx_realised_loss_account`,
`fx_ar_control_account`).

**Rate resolution.** `R0` = the invoice's booked `fxRate` when present, else the
`FxRate` register at the invoice date. `R1` = the gateway-reported
`settlementFxRate` when present, else the `FxRate` register at the settlement
date. `FxRate` lookup prefers an exact-date row, else the most recent effective
row on or before the target date (REQ-MC-002 orientation contract
`baseCurrencyAmount = transactionAmount x rate`).

**Wiring & fail-open.** `PaymentReconciliationService::settleLinkedInvoice()`
lazily resolves `RealisedFxSettlementService` from the container and calls it
after the `ARInvoice` is saved `paid`. Any resolution gap (same currency,
missing rate, zero movement) or exception posts nothing and returns a reason —
it never un-settles a paid invoice. This mirrors the fail-open posture of the
whole reconciliation path.

**ADR-031.** The service is the imperative-orchestration exception: a
cross-schema read (`Administration.functionalCurrency`), an external `FxRate`
lookup at two dates, and a balanced GL emission — none expressible as a single
OR calculation formula. Structure and DI mirror the already-shipped
`FxRevaluationService` exactly.

## Item 2 — usage-metered billing (feeds, does not fork, invoicing)

`UsageRatePlan` (flat or graduated price book) + `MeterReading` (a metered
quantity over a period). `UsageRatingCalculator` is a pure, dependency-free
engine: flat = `quantity x unitPriceCents`; graduated = the quantity split
across ascending tiers, each slice priced at its tier rate, the null-`upTo`
final tier catching all remaining volume.

The `usage` billing model is added to the EXISTING pipeline, not a parallel
one: `BillingModelEngine::calculateUsage()` formats rated readings into the
identical line-draft shape every other model emits; `InvoiceGenerationService::
loadMeterReadings()` loads readings, resolves each reading's `UsageRatePlan`
and rates it; the existing `draftInvoice()` steps (VAT clamping/totalling,
`BillableInvoice` + `BillableInvoiceLine` persistence, numbering) and the
existing `postInvoice()` GL path are reused unchanged. `revenueAccountFor()`
gains `usage -> 4500`. `MeterReading` carries an `unrated -> rated -> invoiced`
lifecycle so a reading is never double-billed.

## Seed Data

- `realised-fx-settlement.json` seeds two `RealisedFxPosting` records — one
  `gain` (USD 100000, 0.90 -> 0.93, +300000 cents, `8022`) and one `loss`
  (0.93 -> 0.90, -300000 cents, `8023`) — covering both directions.
- `usage-metered-billing.json` seeds one graduated `UsageRatePlan`
  (`api_calls`, tiers `[1000@5, 10000@3, ∞@2]`) and one 12500-call
  `MeterReading` in `unrated` — the exact fixture the REQ-UMB-003 test rates to
  €370.00.

## Testing

- `RealisedFxSettlementServiceTest` — realised gain AND loss both post balanced
  entries (`debit == credit == 300000`), rate-from-register, same-currency /
  no-rate / zero-movement no-ops.
- `UsageRatingCalculatorTest` — flat, graduated (12500 -> 37000 cents), tier
  normalisation, zero.
- `MeteredInvoiceGenerationTest` — a 12500-call reading rates to €370.00 net and
  lands as one `sourceType: "usage"` `BillableInvoiceLine` through the real
  `draftInvoice()` pipeline.

All GL numbers are asserted as real integer cents; no fabricated figures.
