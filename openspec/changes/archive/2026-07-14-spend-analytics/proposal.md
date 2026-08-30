# Proposal: spend-analytics

## Summary

Add **single-dimension spend analysis** over the Accounts-Payable sub-ledger:
spend-by-supplier, spend-by-category, spend-by-cost-centre and spend-by-period.
Every view is computed server-side by **consuming OpenRegister's
`aggregation-api` primitive** (`AggregationRunner::runAdhocByRef`) — a
`SELECT sum(...) ... GROUP BY <one field>` executed by the engine, with
list-RBAC and the active-organisation multi-tenant predicate enforced before
any SQL runs. The leaf does **not** re-implement grouping or summing (ADR-022).

A new read-only endpoint `GET /api/analytics/spend?dimension=<supplier|
category|costCentre|period>` returns `{ dimension, label, groups:[{key,amount}],
total, backend }`.

## Routing decision (verify-first)

The routing audit flagged this feature PARTIAL: OR's aggregation-api already does
one-dimensional aggregation but **not** cross-tab (multi-field groupBy). This was
verified against the engine before writing any code:

- `openregister/lib/Service/Aggregation/AggregationQuery.php:206` —
  `getGroupByField(): ?string` reads a **single** `groupBy['field']`.
- `AggregationRunner` native + PHP-fallback both gate grouping on
  `isset($groupBy['field'])`; a multi-field **list** is silently ignored.

Therefore:

- **Single-dimension** spend views (each groups by ONE top-level scalar field) →
  built here by consuming the honoured single-field path. Ships now.
- **Cross-tab** (supplier × period, category × cost-centre) → belongs in
  OpenRegister, **not** in a leaf. Filed as **openregister#432** (honour
  multi-field groupBy). No cross-tab engine is built in shillinq.
- The pre-existing inert multi-field declarations in
  `bookkeeping-accounts-payable-core.json` (`agedPayablesDetail`,
  `agedPayablesSummary` — `groupBy:["vendorId","dueDateBucket"]`) are documented
  as **BLOCKED on openregister#432**, so no declaration claims a capability the
  engine does not run (orphaned-capability discipline).

## Affected Projects

- **shillinq** — new `SpendAnalyticsService` + `SpendAnalyticsController` + route
  + i18n (EN/NL); documentation note on the inert AP cross-tab aggregations.
- **openregister** — issue #432 only (no code in this change).

## Scope

In scope: the four single-dimension spend views over AP data, consuming OR
aggregation-api; the REST endpoint; correct-totals tests; the blocked-cross-tab
documentation. Out of scope: cross-tab/multi-field groupBy (openregister#432);
a bespoke frontend dashboard page (the endpoint is chart-widget ready; wiring is
a follow-up).

## Risks / Rollback

- **Risk:** the two source schemas (APTransaction gross totals vs GLLine posted
  expense debits) answer different questions and need not reconcile to the cent.
  Mitigated by distinct labels + a design note. **Rollback:** remove the route,
  service, controller and i18n keys — no schema or data migration is performed.

## Open Questions

- Whether spend-by-period should switch from `GLLine.periodId` grouping to OR's
  `dateBucket` on `APTransaction.invoiceDate` once cross-tab lands (#432).
