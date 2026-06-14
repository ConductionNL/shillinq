# Design — Member 10: Vendor Performance Scoring (code)

## Context

`kind: code` member implementing the monthly vendor scoring aggregation
over the GRN (member 04) and SupplierInvoice/ThreeWayMatch (members 05-08)
history, writing the `VendorPerformance` register (member 01).

## Decisions

### D5 — Vendor performance scoring unlocks auto-review for 96%+ performers

Carried from the giant's D5. Monthly rolling-window metrics:
- on_time_delivery_rate = (GRNs received by expected date) / (GRNs received)
- quantity_accuracy_rate = (received = ordered within tolerance) / (lines received)
- price_accuracy_rate = (invoiced within tolerance) / (lines invoiced)
- invoice_accuracy_rate = (invoices matched first try) / (invoices received)

overall_score = weighted avg (40% on-time, 30% qty, 20% price, 10% invoice).
Score ≥96 sets automated_review_eligible=TRUE and optionally relaxes the
supplier's ToleranceProfile (or presents relaxation to the controller).

### Bootstrap

A 90-day bootstrap period applies before auto-review eligibility kicks in
(carried from the giant's Risk 2 mitigation), avoiding premature elevation
on thin history.

## Security (ADR-005)

- Scoring runs server-side as a scheduled job; eligibility + any tolerance
  relaxation are server-authoritative and audit-logged.

## Reuse
- OR `x-openregister-aggregations` for the monthly rolling-window metrics
- `VendorPerformance` + `ToleranceProfile` registers (member 01)
- NC BackgroundJob for the monthly cron
- nextcloud-vue for the detail view
