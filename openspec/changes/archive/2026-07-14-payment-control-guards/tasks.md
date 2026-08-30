# Tasks: payment-control-guards

## 1. Item 1 — duplicate-payment guard (REQ-PCG-001)

- [x] 1.1 Create `lib/Lifecycle/PaymentRunDuplicateGuard.php` implementing `LifecycleGuardInterface`,
  blocking export when a line settles an already-`paid` or already-batched `APTransaction`; fail-closed.
- [x] 1.2 Add `"requires": "OCA\\Shillinq\\Lifecycle\\PaymentRunDuplicateGuard"` to `PaymentRun.export`
  and bump `PaymentRun` version 0.2.0 → 0.3.0 in `bookkeeping-accounts-payable-core.json`.
- [x] 1.3 Register the guard under its FQCN tag in `lib/AppInfo/Application.php`.
- [x] 1.4 `tests/Unit/Lifecycle/PaymentRunDuplicateGuardTest.php` — already-batched + already-paid
  REJECTED (failing paths), clean allowed, self/uuid/reconciled/fail-closed cases.

## 2. Item 2 — bank-balance tie-out (covered)

- [x] 2.1 Verify `StatementVerifyGuard::verifyStatementBalance()` owns the tie-out; no new production code.
- [x] 2.2 `tests/Unit/Guard/StatementVerifyGuardVarianceTest.php` — non-tying balance flags a non-zero
  variance (evidence of the bad path); tying balance flags zero.

## 3. Item 3 — suspense ageing (REQ-PCG-002)

- [x] 3.1 Create `lib/Service/SuspenseAgeingService.php` — age `unmatched`/`routed-to-suspense`
  `BankStatementLine` per administration; safe reporting path + throwing control path.
- [x] 3.2 `PeriodCloseAssistantService::detectAgedSuspense()` + `error`-severity `flag-suspense`.
- [x] 3.3 `tests/Unit/Service/SuspenseAgeingServiceTest.php` — ages, scopes, sorts, counts.

## 4. Item 3 — block-close (REQ-PCG-003)

- [x] 4.1 `PeriodCloseGuard::suspenseAccountDrained()` (lazy `SuspenseAgeingService`, fail-closed).
- [x] 4.2 `PeriodCloseService::closePeriod()` imperative block (fail-closed) + bump `FiscalPeriod`
  version 0.2.0 → 0.3.0 and add the precondition in `bookkeeping-period-close.json`.
- [x] 4.3 `tests/Unit/Lifecycle/PeriodCloseGuardSuspenseTest.php` + new `PeriodCloseServiceTest` cases —
  non-empty suspense BLOCKS close (failing path); fragment test asserts the precondition wiring.

## 5. i18n + validation

- [x] 5.1 Guard/blocker messages added to `l10n/en.json` + `l10n/nl.json`.
- [x] 5.2 `register.d` JSON valid; run the affected unit tests in `php:8.3-cli` (fresh composer install).
- [x] 5.3 Hydra mechanical gates on changed files (spdx, forbidden-patterns, stub-scan, route-auth,
  spec-coverage, manifest-validation, orphaned-write-capability).
