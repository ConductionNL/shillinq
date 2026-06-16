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
  call for the `APTransaction` schema → `/apps/shillinq/bookkeeping/accounts-payable/{uuid}`,
  reading the configured register slug (L3) like the existing `account` registration.
- [x] 2.2 Add `@spec` tags referencing this change to the class + `handle()` docblocks.

## 3. Validation

- [x] 3.1 `php -r` JSON-parse `bookkeeping-accounts-payable-core.json` — must parse clean.
- [x] 3.2 `php -l lib/Listener/DeepLinkRegistrationListener.php` — no syntax errors.
- [x] 3.3 Confirm the register fragment still uses tab indentation (no leading spaces).
- [x] 3.4 `openspec validate "shillinq-ap-push-notifications" --type change --strict` passes.
