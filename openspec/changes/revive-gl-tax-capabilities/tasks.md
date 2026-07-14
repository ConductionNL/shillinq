# Tasks: revive-gl-tax-capabilities

## Verification (Step 1)

- [x] 1. Re-verify all four methods against `origin/development` HEAD
  (`->method(` grep, dynamic dispatch, `register.d` handler/guard strings,
  routes, `info.xml` jobs, DI) and record the verdict table with caller
  evidence in `design.md`.

## Fixed-asset disposal (`DisposalJournalEmitter::emit`)

- [x] 2. Repair the `FixedAsset` `x-openregister-lifecycle` in
  `register.d/bookkeeping-fixed-assets-depreciation.json`: `field: status`,
  the three real `states`, explicit `from`/`to` on every transition
  (`dispose: active → retired`).
- [x] 3. Add the two properties the disposal genuinely needs to `FixedAsset`
  (`administrationId`, `disposalAccountingTreatment`, both with title +
  description) and extend `GLLine.subLedgerType` with `fixed-asset`.
- [x] 4. `DisposalJournalEmitter::emit()`: add the optional
  `?float $bookValue = null` override (D3) — no behaviour change when null.
- [x] 5. New `lib/Service/FixedAssetDisposalService.php`: normalise the asset
  (D4), resolve the posted accumulated depreciation from the latest
  `DepreciationSchedule`, call `emit()`, assert `linesBalance()`, persist the
  `GLTransaction` + `GLLine` rows via the real ObjectService API.
- [x] 6. New `lib/Listener/FixedAssetDisposalListener.php` on
  `ObjectTransitionedEvent` (`FixedAsset` → `retired`); register it in
  `Application.php`.

## Intercompany (`IntercompanyJournalService::reconcileVariance`)

- [x] 7. New `lib/Service/IntercompanyLinkService.php`: on `link`, find the
  counter-side entry by `intercompanyNumber`; create it from `buildMirror()`
  when absent; compute `reconcileVariance()` and persist `varianceAmount` on
  both sides.
- [x] 8. New `lib/Listener/IntercompanyLinkListener.php` on
  `ObjectTransitionedEvent` (`IntercompanyJournalEntry` → `gekoppeld`);
  register it in `Application.php`.
- [x] 9. New `lib/Guard/IntercompanyEliminationGuard.php`
  (`requireReconciledPair`) using `IntercompanyJournalService::isBalanced()`;
  declare it as the `eliminate` transition's `requires` in
  `register.d/bookkeeping-multi-administratie.json` and register the literal
  tag through `RegisterRequiresGuardAdapter`.

## GR/IR period-end saldo (`reconcileGRIRSaldoForPeriod`) — shillinq#424

- [x] 10. New `lib/Controller/GRIRReconciliationController::saldo()`
  (`#[NoAdminRequired]`, per-administration IDOR guard, 401/400/404/500
  envelope) + route `GET /api/gr-ir/saldo`.

## OSS VAT (`OssPaymentReconciliation::reconcileDistribution`)

- [x] 11. New `lib/Guard/OssPaymentGuard.php` (`canMarkPaid`) resolving the
  counterpart record and delegating to the unmodified
  `OssPaymentReconciliation::canMarkPaid()`; register the literal
  `OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid` tag through
  `RegisterRequiresGuardAdapter` so `OssReturn.pay` / `OssPayment.reconcile`
  stop returning HTTP 500 (D2).
- [x] 12. New `lib/Listener/OssPaymentReconciliationListener.php` on
  `ObjectCreatedEvent` (`OssPayment`): call `reconcileDistribution()` against
  the linked `OssReturn` and drive the record to `reconciled` or
  `discrepancy`, persisting the per-country differences; register it in
  `Application.php`.
- [x] 13. Add `distributionDifferences` to the `OssPayment` schema
  (title + description) so the detected discrepancy is stored, not just
  logged.

## Tests (the gate)

- [x] 14. `tests/Unit/Listener/FixedAssetDisposalListenerTest.php` — a real
  `FixedAsset` → `retired` event posts a **balanced** disposal journal:
  assert `sum(debit) === sum(credit)` **and** the per-account amounts (asset
  credit = gross cost, accumulated-depreciation debit = the posted
  accumulated depreciation, clearing debit = proceeds, gain/loss = proceeds −
  book value), plus the loss-on-scrap case (zero proceeds).
- [x] 15. `tests/Unit/Listener/IntercompanyLinkListenerTest.php` — a `link`
  transition creates the mirror when absent, and computes + persists a
  non-zero `varianceAmount` when the counter-side amount differs.
- [x] 16. `tests/Unit/Guard/IntercompanyEliminationGuardTest.php` — elimination
  denied while the pair is out of balance, allowed at zero variance,
  fail-closed when the counter-side is missing.
- [x] 17. `tests/Unit/Controller/GRIRReconciliationControllerTest.php` — saldo
  endpoint returns the reconciliation envelope; 401 anonymous; 404
  cross-tenant.
- [x] 18. `tests/Unit/Listener/OssPaymentReconciliationListenerTest.php` — a
  matching distribution reconciles; a diverging one flags `discrepancy` with
  the per-country differences.
- [x] 19. `tests/Unit/Guard/OssPaymentGuardTest.php` — the guard resolves both
  object shapes and is fail-closed without a counterpart.
- [x] 20. Full suite green in a `php:8.3` container (`phpunit-unit.xml`),
  `phpcs` + `phpstan` clean on the changed paths.
