# Tasks: remove-dead-notification-orchestration-stack

## 1. Confirm zero external callers (safety check before delete)

- [x] 1.1 `grep -rln "Shillinq\\\\Service\\\\Notification" --include=*.php .`
      from the repo root — confirm every hit is inside
      `lib/Service/Notification/` or `tests/Unit/Service/Notification/`.
- [x] 1.2 `grep -rn "Service\\\\Notification" lib/AppInfo/Application.php`
      — confirm no DI registration references the dead namespace.
- [x] 1.3 `grep -rln "Service\\\\Notification" appinfo/` — confirm no route
      or background-job registration references it.

## 2. Delete the dead production code

- [x] 2.1 Delete `lib/Service/Notification/BookingNotificationService.php`.
- [x] 2.2 Delete `lib/Service/Notification/NotificationOptOutPolicy.php`.
- [x] 2.3 Delete `lib/Service/Notification/NotificationRateLimiter.php`.
- [x] 2.4 Delete `lib/Service/Notification/NotificationDeduplicator.php`.
- [x] 2.5 Delete `lib/Service/Notification/RecipientResolver.php`.
- [x] 2.6 Delete `lib/Service/Notification/RecipientConditionEvaluator.php`.
- [x] 2.7 Delete `lib/Service/Notification/NotificationTemplateRenderer.php`.
- [x] 2.8 Delete `lib/Service/Notification/NotificationAuditWriter.php`.
- [x] 2.9 Delete `lib/Service/Notification/OpenconnectorAdapterInterface.php`.
- [x] 2.10 Delete `lib/Service/Notification/LogOpenconnectorAdapter.php`.
- [x] 2.11 Delete `lib/Service/Notification/NotificationCounterStoreInterface.php`.
- [x] 2.12 Delete `lib/Service/Notification/InMemoryNotificationCounterStore.php`.
- [x] 2.13 Delete `lib/Service/Notification/NotificationSendResult.php`.
- [x] 2.14 Remove the now-empty `lib/Service/Notification/` directory.

## 3. Delete the corresponding dead tests

- [x] 3.1 Delete `tests/Unit/Service/Notification/BookingNotificationServiceTest.php`.
- [x] 3.2 Delete `tests/Unit/Service/Notification/NotificationAuditWriterTest.php`.
- [x] 3.3 Delete `tests/Unit/Service/Notification/NotificationDeduplicatorTest.php`.
- [x] 3.4 Delete `tests/Unit/Service/Notification/NotificationOptOutPolicyTest.php`.
- [x] 3.5 Delete `tests/Unit/Service/Notification/NotificationRateLimiterTest.php`.
- [x] 3.6 Delete `tests/Unit/Service/Notification/NotificationTemplateRendererTest.php`.
- [x] 3.7 Delete `tests/Unit/Service/Notification/RecipientConditionEvaluatorTest.php`.
- [x] 3.8 Delete `tests/Unit/Service/Notification/RecipientResolverTest.php`.
- [x] 3.9 Remove the now-empty `tests/Unit/Service/Notification/` directory.

## 4. Verify

- [x] 4.1 `composer dump-autoload` ran clean — the ONLY notice is the
      expected "Skipping" for the test stubs under `tests/stubs/OpenRegister/`
      (they intentionally live outside the psr-4 `tests/` rule; identical
      pre-existing behaviour for the other stubs). Zero warnings about any
      missing `OCA\Shillinq\Service\Notification\*` class.
- [x] 4.2 Ran the full `phpunit-unit.xml` suite. No test references the
      deleted classes (the 8 dead test files under
      `tests/Unit/Service/Notification/` were deleted together with the
      production code; grep-confirmed zero remaining
      `Shillinq\Service\Notification` references outside the OpenRegister
      stub). The suite's residual failures
      (`ShillinqNotificationsFragmentTest`, `TrialBalancePerformanceTest`)
      are PRE-EXISTING and unrelated — they reproduce identically on the
      untouched main checkout (3 errors + 4 failures across just those two
      classes on a clean tree; the perf test is a 2.31s-vs-2.0s timing
      flake). No NEW failure introduced by these deletions.
- [x] 4.3 `phpcs` clean on the changed surface (deletions leave nothing to
      lint; no dangling references remain). Note: full `composer check:strict`
      (Psalm/PHPStan) not run — not in the calling brief's gate set and no
      config change here would surface a new one, since deletion of
      zero-referenced code cannot add a dangling reference (grep-verified).
- [x] 4.4 Ran the app-applicable mechanical gates (phpcs custom sniffs +
      forbidden-patterns via phpcs.xml + stub-scan by inspection): no
      `var_dump`/`die`/`error_log` etc. introduced (pure deletion), and
      stub-scan has strictly less surface than before. The repo-level
      `run-hydra-gates.sh` orchestrator itself was not invoked (out of scope
      for an isolated single-app worktree).
- [x] 4.5 `openspec validate remove-dead-notification-orchestration-stack
      --strict` run from this worktree (CLI available at
      `~/.npm-global/bin/openspec`): "Change
      'remove-dead-notification-orchestration-stack' is valid", exit 0.
