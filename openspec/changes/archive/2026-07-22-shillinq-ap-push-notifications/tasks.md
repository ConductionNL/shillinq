# Tasks: shillinq-ap-push-notifications

## 1. Register fragment (config overlay)

- [x] 1.1 Add `x-openregister-notifications` block to the `APTransaction` schema in
  `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`.
- [x] 1.2 `approvalNeeded` rule — `updated` trigger, condition `state == received`; channels
  `[nc-notification, web-push]`; `originApp: shillinq`; recipients `shillinq-finance` group;
  bilingual `subject` (title) + `message` (body); one primary `object-detail` action
  "Open invoice" / "Factuur openen".
- [x] 1.3 `overdue` rule — `scheduled` `intervalSec 86400` with `filter.all`
  (`state notIn [paid, written-off, voided]` + `dueDate before now`), mirroring the AR overdue
  filter; same channels / originApp / recipients / action; bilingual `subject` + `message`.
- [x] 1.4 Do not clobber any existing rule (the schema had none before this change).

## 2. Deeplink registration

- [x] 2.1 In `lib/Listener/DeepLinkRegistrationListener.php`, add a `$event->register(...)`
  call for the `APTransaction` schema → `/apps/shillinq/bookkeeping/ap-transactions/{uuid}`
  (corrected 2026-07-22: the route originally read `/bookkeeping/accounts-payable/{uuid}`,
  which does not exist — the real `APTransactionDetail` manifest route is
  `/bookkeeping/ap-transactions/:id`), reading the configured register slug (L3) like the
  existing `account` registration.
- [x] 2.2 Add `@spec` tags referencing this change to the class + `handle()` docblocks.
- [x] 2.3 Add `tests/Unit/Listener/DeepLinkRegistrationListenerTest.php`, which cross-checks
  every registered deeplink's urlTemplate against the manifest's real declared `detail` page
  routes (fixed the `tests/stubs/OpenRegister/Event/DeepLinkRegistrationEvent.php` stub's
  `register()` signature to match the real OpenRegister event so the listener can be
  exercised at all — it previously took a single array param, silently making the listener
  untestable and letting the broken route ship undetected).

## 3. Validation

- [x] 3.1 `php -r` JSON-parse `bookkeeping-accounts-payable-core.json` — must parse clean.
- [x] 3.2 `php -l lib/Listener/DeepLinkRegistrationListener.php` — no syntax errors.
- [x] 3.3 Confirm the register fragment still uses tab indentation (no leading spaces).
- [x] 3.4 `openspec validate "shillinq-ap-push-notifications" --type change --strict` passes.

## 4. Regression note (orphaned-capability fix, 2026-07-22)

- [x] 4.1 A prior version of task 2.1 pointed the `APTransaction` deeplink at
  `/apps/shillinq/bookkeeping/accounts-payable/{uuid}`, which does not exist — the real
  `APTransactionDetail` manifest route (`src/manifest.d/bookkeeping-accounts-payable-core.json`)
  is `/bookkeeping/ap-transactions/:id`. The "Open invoice" web-push action therefore 404'd.
  Corrected to `/apps/shillinq/bookkeeping/ap-transactions/{uuid}`.
- [x] 4.2 Added `tests/Unit/Listener/DeepLinkRegistrationListenerTest.php` (4 tests), which
  cross-checks every registered deeplink's urlTemplate against the manifest's real declared
  `detail` page routes so this class of drift fails the suite instead of 404ing in the
  browser. Sanity-checked: reverting the fix makes the new test fail with the exact wrong-path
  diff; re-applying the fix makes it pass again.
- [x] 4.3 Full `phpunit-unit.xml` suite still green (3819 baseline + 4 new = 3823 for this
  change alone; 3829 combined with the sibling shillinq-signing-via-events fix in the same
  worktree).
