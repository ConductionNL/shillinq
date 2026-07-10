# Tasks: remove-dead-notification-orchestration-stack

## 1. Confirm zero external callers (safety check before delete)

- [ ] 1.1 `grep -rln "Shillinq\\\\Service\\\\Notification" --include=*.php .`
      from the repo root — confirm every hit is inside
      `lib/Service/Notification/` or `tests/Unit/Service/Notification/`.
- [ ] 1.2 `grep -rn "Service\\\\Notification" lib/AppInfo/Application.php`
      — confirm no DI registration references the dead namespace.
- [ ] 1.3 `grep -rln "Service\\\\Notification" appinfo/` — confirm no route
      or background-job registration references it.

## 2. Delete the dead production code

- [ ] 2.1 Delete `lib/Service/Notification/BookingNotificationService.php`.
- [ ] 2.2 Delete `lib/Service/Notification/NotificationOptOutPolicy.php`.
- [ ] 2.3 Delete `lib/Service/Notification/NotificationRateLimiter.php`.
- [ ] 2.4 Delete `lib/Service/Notification/NotificationDeduplicator.php`.
- [ ] 2.5 Delete `lib/Service/Notification/RecipientResolver.php`.
- [ ] 2.6 Delete `lib/Service/Notification/RecipientConditionEvaluator.php`.
- [ ] 2.7 Delete `lib/Service/Notification/NotificationTemplateRenderer.php`.
- [ ] 2.8 Delete `lib/Service/Notification/NotificationAuditWriter.php`.
- [ ] 2.9 Delete `lib/Service/Notification/OpenconnectorAdapterInterface.php`.
- [ ] 2.10 Delete `lib/Service/Notification/LogOpenconnectorAdapter.php`.
- [ ] 2.11 Delete `lib/Service/Notification/NotificationCounterStoreInterface.php`.
- [ ] 2.12 Delete `lib/Service/Notification/InMemoryNotificationCounterStore.php`.
- [ ] 2.13 Delete `lib/Service/Notification/NotificationSendResult.php`.
- [ ] 2.14 Remove the now-empty `lib/Service/Notification/` directory.

## 3. Delete the corresponding dead tests

- [ ] 3.1 Delete `tests/Unit/Service/Notification/BookingNotificationServiceTest.php`.
- [ ] 3.2 Delete `tests/Unit/Service/Notification/NotificationAuditWriterTest.php`.
- [ ] 3.3 Delete `tests/Unit/Service/Notification/NotificationDeduplicatorTest.php`.
- [ ] 3.4 Delete `tests/Unit/Service/Notification/NotificationOptOutPolicyTest.php`.
- [ ] 3.5 Delete `tests/Unit/Service/Notification/NotificationRateLimiterTest.php`.
- [ ] 3.6 Delete `tests/Unit/Service/Notification/NotificationTemplateRendererTest.php`.
- [ ] 3.7 Delete `tests/Unit/Service/Notification/RecipientConditionEvaluatorTest.php`.
- [ ] 3.8 Delete `tests/Unit/Service/Notification/RecipientResolverTest.php`.
- [ ] 3.9 Remove the now-empty `tests/Unit/Service/Notification/` directory.

## 4. Verify

- [ ] 4.1 `composer dump-autoload` (or equivalent) to confirm no autoload
      warnings for missing classes.
- [ ] 4.2 Run the full PHPUnit unit suite — confirm no other test
      references the deleted classes and the suite still passes.
- [ ] 4.3 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — confirm
      no dangling references.
- [ ] 4.4 Run the 18 hydra mechanical gates (report) — confirm
      `stub-scan` and `forbidden-patterns` stay green.
- [ ] 4.5 `openspec validate remove-dead-notification-orchestration-stack --strict`
      passes.
