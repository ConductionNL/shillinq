# Tasks: reporting-segment-results

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Line fields

- [x] 1.1 Add `signedAmount` (calculation), `accountClass` and `countsInResult` to `GLLine` in a `register.d` fragment (REQ-RSR-001). Verify: `npm run check:registers`; PHPUnit on the calculation through OpenRegister's evaluator.
- [x] 1.2 `GLLineResultStampListener` for posted and reversed, registered in `Application.php` (REQ-RSR-002). Verify: PHPUnit for post, reversal and reversal of a reversal.
- [x] 1.3 Repair step stamping existing lines (design Migration Plan). Verify: PHPUnit on a seeded register.

## 2. Aggregations and dashboard

- [x] 2.1 Rewrite the five segment aggregations with the filter, `signedAmount`, and the revenue and costs split (REQ-RSR-002). Verify: an integration test against the aggregation endpoint with the seed data.
- [x] 2.2 Revenue, costs and result columns and a period filter on `SegmentPnLDashboard.vue` (REQ-RSR-003). Verify: vitest for the row normalisation.

## 3. End to end and strings

- [ ] 3.1 (Written, not run: no live instance in this lane; the recipe is in the PR body.) Playwright `tests/e2e/reporting-segment-results.spec.ts` with the KP-300 example. Verify: passes locally.
- [x] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

## Evidence (2026-09-29)

- 1.1 `lib/Settings/register.d/reporting-segment-results.json`: `GLLine.signedAmount` (materialised calculation, credit positive), `accountClass`, `countsInResult`. `npm run check:registers` 0 (bare-ref baseline lowered 100 to 98). `GlLineResultStampsTest::testACostLineIsNegative`.
- 1.2 `lib/Service/Ledger/GlLineResultStamps.php`, `lib/Listener/GLLineResultStampListener.php` (posted, reversed, and a GLLine created under an already posted transaction, because `MaterialiseGlTransactionAction` writes the header posted and then the lines, so no transition fires), registered by `lib/AppInfo/SegmentResultRegistration.php`. Tests: `testPostingStampsEveryLineWithItsClass`, `testADraftIsNotStamped`, `testAReversalTakesBothOutAndSoDoesAReversalOfTheReversal`, `GLLineResultStampListenerTest` (real OpenRegister event classes, wiring asserted from `Application`).
- 1.3 `lib/Repair/StampGlLineResults.php`, post-migration after `InitializeSettings`, info.xml version bumped. `testTheRepairStepStampsHistory`.
- 2.1 Four segment aggregations (cost centre, its hierarchy, cost object, project) filter `accountClass: pnl`, `countsInResult: true` and return `revenue`, `costs`, `result`. `testTheSegmentResultOfKp300IsTwelveThousand` evaluates the declared aggregation over the seed: 40,000 / 28,000 / 12,000, bank line and draft left out. `byAnalyticalDimension` stays untranslated: it groups by the wildcard `dimensions.*`, which no aggregation key expresses (#1261, pinned in CostCentersDimensionsFragmentTest); that segment type shows nothing until #1261 lands.
- 2.2 `SegmentPnLDashboard.vue` over `src/utils/segmentResults.js`: reads OpenRegister's `groups` envelope (it read `buckets` and showed nothing), shows euros (it divided by 100), adds cost object, and filters on the administration's fiscal periods (GLLine.periodId holds the FiscalPeriod periodId, 2026-M09, so a month picker matched nothing). `tests/vitest/reportingSegmentResults.spec.js`.
- 3.1 `tests/e2e/reporting-segment-results.spec.ts` written for REQ-RSR-003; not run in this lane.
- 3.2 English and Dutch strings for the labels and the three schema descriptions: `npm run test:l10n` 0, `test:l10n-parity` 0, `check:schema-l10n` 0 (12210, baseline 12210).
- The in-memory object service stub now honours `patchObject`'s schema argument as OpenRegister does; before, a patch made while another schema was active landed on that schema.
