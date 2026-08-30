# Change: ar-billing-completeness

## Why

The AR/billing sweep flagged two accounts-receivable gaps. Both were verified
against HEAD before any code was written; one was a real delta in each item and
neither was already covered.

**1. multicurrency-ar-fx-gainloss.** shillinq issues AR invoices in a foreign
currency (`ARInvoice.currency`), carries a daily `FxRate` register (REQ-MC-002)
and — since `fx-period-end-revaluation` (#403) — posts the UNREALISED
period-end mark of open `FXPosition` balances (`FxRevaluationService`,
REQ-MC-006/007/008). But the REALISED leg was missing: when a foreign-currency
receivable is actually collected, `PaymentReconciliationService::settleLinked
Invoice()` flips the `ARInvoice` to `paid` and posts the settlement at book
value only — nothing captures the FX difference between the invoice-date rate
and the payment-date rate. A EUR-functional administration invoicing in USD and
collecting at a different dollar rate silently mis-states income. **Verdict:
real build.**

**2. usage-metered-billing.** shillinq has flat recurring
(`RecurringInvoiceProfile`) and retainer billing
(`invoice-from-time-and-expense`), but no meter-reading -> rated-line ->
invoice path — no consumption/usage billing schema or service exists anywhere
in `lib/`. **Verdict: real build.**

## What Changes

- **ADDED** `REQ-MC-010` (bookkeeping-multi-currency) — settlement of a
  foreign-currency `ARInvoice` posts the realised FX gain/loss as a
  self-balancing two-line `GLTransaction` (AR-control clearing vs realised
  gain/loss account) plus an append-only `RealisedFxPosting` audit record.
  New `RealisedFxSettlementService` (ADR-031 orchestration exception);
  `PaymentReconciliationService` calls it fail-open after settling the invoice.
  New `RealisedFxPosting` schema fragment.
- **ADDED** `usage-metered-billing` capability (`REQ-UMB-001..004`) —
  `UsageRatePlan` (flat/graduated price book) + `MeterReading` schemas, a pure
  `UsageRatingCalculator`, a `usage` branch on the EXISTING
  `InvoiceGenerationService` pipeline via `BillingModelEngine::calculateUsage`.
  Invoicing is reused, never forked.

## Impact

- New: `RealisedFxSettlementService`, `UsageRatingCalculator`; register
  fragments `realised-fx-settlement.json`, `usage-metered-billing.json`.
- Modified (additive): `PaymentReconciliationService` (fail-open realised-FX
  call), `InvoiceGenerationService` (usage model + `loadMeterReadings` + `4500`
  revenue account + new autowired dependency), `BillingModelEngine`
  (`calculateUsage`), `InvoiceGenerationRequest` (`usage` model +
  `meterReadingIds`/`usageRatePlanId`).
- Every GL posting is asserted balanced (debit == credit + amounts). All new
  services consume only the real OpenRegister ObjectService API (ADR-022).
- No breaking changes: all schema and request additions are additive; existing
  billing models and the settlement path are unchanged when no foreign currency
  / no meter readings are involved.
