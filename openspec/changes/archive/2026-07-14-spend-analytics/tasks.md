# Tasks — spend-analytics

## Verify-first

- [x] Task 1: Read OR `aggregation-api` (`AggregationQuery.php`,
  `AggregationRunner.php`, spec) — established single-field groupBy only
  (`getGroupByField(): ?string`, line 206); multi-field list silently ignored;
  `runAdhocByRef` is the in-process consumer entry point.
- [x] Task 2: Read shillinq AP/spend model — `APTransaction` (vendorId,
  totalAmount, invoiceDate) + `GLLine` (accountNumber, costCenterCode, periodId,
  amount, side, subLedgerType) are the groupable top-level scalar fields;
  confirmed `agedPayablesDetail`/`agedPayablesSummary` multi-field groupBy is
  inert and has zero PHP consumers.

## Route correctly

- [x] Task 3: Decision recorded in `design.md` — build single-dimension views
  consuming OR aggregation-api; cross-tab → openregister#432; do NOT build a
  cross-tab engine in shillinq (ADR-022).
- [x] Task 4: File openregister#432 (honour multi-field groupBy) citing
  `AggregationQuery.php:206` + shillinq's inert declarations.
- [x] Task 5: Document the inert AP cross-tab aggregations
  (`agedPayablesDetail`, `agedPayablesSummary`) as BLOCKED on openregister#432 in
  `bookkeeping-accounts-payable-core.json` — no declaration claims an unrun
  capability.

## Build (single-dimension, consuming OR)

- [x] Task 6: `lib/Service/SpendAnalyticsService.php` — spendBySupplier /
  spendByCategory / spendByCostCentre / spendByPeriod, each building a
  single-field `AggregationQuery` and dispatching via `runAdhocByRef`. No
  leaf-side grouping/summing.
- [x] Task 7: Lazy OR runner DI via container FQCN; unavailable runner raises
  (no silent fail-open).
- [x] Task 8: `lib/Controller/SpendAnalyticsController.php` —
  `GET /api/analytics/spend?dimension=...`, closed enum validation, ADR-005
  anonymous guard, `#[NoAdminRequired]`, no-stack-trace 500.
- [x] Task 9: Register the route in `appinfo/routes.php`.
- [x] Task 10: i18n EN + NL for the four dimension labels (`l10n/en.json`,
  `l10n/nl.json`).

## Spec + tests

- [x] Task 11: Author `specs/spend-analytics/spec.md` (REQ-SPA-001..005) with
  GIVEN/WHEN/THEN scenarios + `@e2e exclude` reasons (backend primitive).
- [x] Task 12: `tests/stubs/OpenRegister/Service/Aggregation/AggregationQuery.php`
  minimal stub so unit tests can build the query off the classpath.
- [x] Task 13: `tests/Unit/Service/SpendAnalyticsServiceTest.php` — KNOWN AP/GL
  set → assert exact spend-by-supplier / category / cost-centre / period totals
  + grand totals; assert single-field groupBy (routing guard); assert
  runner-unavailable raises. 5 tests / 27 assertions green in php:8.3-cli.

## Follow-up (not this change)

- [ ] Task 14: When openregister#432 lands, add cross-tab views + un-block the AP
  cross-tab declarations. (Deferred — tracked on the OR issue.)
- [ ] Task 15: Wire a dashboard page/chart widgets to the endpoint. (Deferred —
  endpoint is chart-widget ready.)
