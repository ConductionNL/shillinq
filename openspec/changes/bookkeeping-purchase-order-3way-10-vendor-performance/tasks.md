# Tasks — Member 10: Vendor Performance Scoring (code)

## VendorPerformanceAggregation

- [ ] Implement `calculateMonthlyScore(supplierId, period)` — runs at month-end
- [ ] Compute `on_time_delivery_rate` — (GRNs received ≤ expected_delivery_date) / (total GRNs)
- [ ] Compute `quantity_accuracy_rate` — (GRN lines where qty_received = qty_ordered) / (total lines)
- [ ] Compute `price_accuracy_rate` — (invoice lines within tolerance) / (total lines)
- [ ] Compute `invoice_accuracy_rate` — (invoices matched first try) / (total invoices)
- [ ] Compute `overall_score` — weighted (40% on_time + 30% qty + 20% price + 10% invoice)
- [ ] Compute `score_trend` vs prior month (improving, stable, declining)
- [ ] Implement `setAutoReviewEligible()` — TRUE if overall_score ≥ 96 (after 90-day bootstrap)
- [ ] Implement `autoRelaxToleranceProfile()` — relax supplier tolerance (or flag for controller) when eligible

## Scheduled job + Vue

- [ ] Create monthly cron BackgroundJob to run VendorPerformanceAggregation
- [ ] Create `VendorPerformanceDetail.vue`: monthly scores, overall score + trend chart, auto-review eligibility badge, links to related POs + invoices

## Tests

- [ ] Unit tests: score calculation (weighted average), eligibility (96%+ threshold), trend detection
- [ ] Integration test: run monthly aggregation → verify scores recorded + auto-review flag set
