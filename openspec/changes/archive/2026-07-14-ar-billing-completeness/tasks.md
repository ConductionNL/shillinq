# Tasks: ar-billing-completeness

## Item 1 — realised FX on settlement (REQ-MC-010)

- [x] 1. Add `RealisedFxSettlementService` computing `foreignAmount x (paymentRate - invoiceRate)` in functional cents (ADR-031 exception, mirrors `FxRevaluationService`)
- [x] 2. Emit a self-balancing two-line `GLTransaction` (AR-control clearing vs realised gain/loss account); assert `debit == credit == |diff|` before persist, refuse otherwise
- [x] 3. Resolve invoice-date rate (booked `fxRate` -> `FxRate` register) and payment-date rate (gateway rate -> `FxRate` register, exact-or-latest-on-or-before)
- [x] 4. Write append-only `RealisedFxPosting` audit record; distinct `8022`/`8023` realised accounts, all `IAppConfig` overridable
- [x] 5. Wire into `PaymentReconciliationService::settleLinkedInvoice()` fail-open (never un-settle a paid invoice)
- [x] 6. Add `realised-fx-settlement.json` register fragment (`RealisedFxPosting` schema + gain/loss seed objects)
- [x] 7. `RealisedFxSettlementServiceTest` — gain AND loss both balanced, rate-from-register, same-currency/no-rate/zero no-ops

## Item 2 — usage-metered billing (REQ-UMB-001..004)

- [x] 8. Add `usage-metered-billing.json` register fragment: `UsageRatePlan` (flat/graduated) + `MeterReading` (unrated->rated->invoiced lifecycle) + seed objects
- [x] 9. Add pure `UsageRatingCalculator::rate()` (flat + graduated tiers, tier normalisation)
- [x] 10. Add `BillingModelEngine::calculateUsage()` emitting the shared line-draft shape
- [x] 11. Add `usage` to `InvoiceGenerationRequest::MODELS` + `meterReadingIds`/`usageRatePlanId` + validation
- [x] 12. Add `InvoiceGenerationService::loadMeterReadings()` + `usage` driveModel case + `usage -> 4500` revenue account (autowire `UsageRatingCalculator`)
- [x] 13. `UsageRatingCalculatorTest` + `MeteredInvoiceGenerationTest` (12500 calls -> €370.00 net line via real `draftInvoice()`)

## Cross-cutting

- [x] 14. SPDX EUPL-1.2 on new PHP; phpcs clean (0 errors); i18n EN+NL where surfaced (schema `titleNl` on FX schema follows existing convention)
- [x] 15. Full unit suite green (no regressions from the additive changes); spec deltas + design verdicts recorded
