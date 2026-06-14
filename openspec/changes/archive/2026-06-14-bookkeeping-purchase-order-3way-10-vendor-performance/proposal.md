---
kind: code
depends_on: [bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing]
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

# Proposal: bookkeeping-purchase-order-3way-10-vendor-performance

Member 10 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing`. This
`kind: code` member implements **vendor performance scoring**
(REQ-PO3W-008): a monthly aggregation over GRN + invoice history that
flags 96%+ suppliers for auto-review and optionally relaxes their
tolerance profile.

## Why (carried from the giant)

REQ-PO3W-008: a supplier with 12 months of 96%+ on-time delivery, 99%
quantity accuracy, and zero disputes should earn auto-approval. The
monthly scoring process computes overall_score (weighted 40% on-time +
30% qty + 20% price + 10% invoice), sets automated_review_eligible when
≥96, and optionally relaxes that supplier's ToleranceProfile. This closes
the feedback loop that drives the "99% automation for compliant suppliers"
promise.

## What this member does

- `VendorPerformanceAggregation`: `calculateMonthlyScore(supplierId,
  period)` computing on_time_delivery_rate, quantity_accuracy_rate,
  price_accuracy_rate, invoice_accuracy_rate, overall_score (weighted),
  score_trend; `setAutoReviewEligible()` (≥96); `autoRelaxToleranceProfile()`
- Monthly scheduled job (cron) to run the aggregation
- `VendorPerformanceDetail.vue` (scores, trend, eligibility badge, links)
- Unit tests (score calc, eligibility threshold, trend detection);
  integration test (monthly aggregation → scores recorded + flag set)

## Scope

### In Scope
- `VendorPerformanceAggregation` + monthly cron job
- Auto-review eligibility + optional tolerance relaxation
- `VendorPerformanceDetail.vue`
- Scoring unit + aggregation integration tests

### Out of Scope
- Matching, GL, exception workflow — members 06-09
- Audit trail export — member 11

## Impact
- `lib/Service/VendorPerformanceAggregation.php`
- `lib/BackgroundJob/` monthly aggregation job
- `src/components/VendorPerformanceDetail.vue`
- `tests/` scoring + aggregation
