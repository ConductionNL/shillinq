# Tasks: payment-run-four-eyes

## 1. Server-side guard

- [x] 1.1 Create `lib/Lifecycle/FourEyesPaymentRunGuard.php` implementing
  `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface::check($object, $action, $userId)` with
  SPDX/EUPL-1.2 header and `@spec` tag.
- [x] 1.2 Derive the preparer set from OpenRegister's audit trail via
  `ObjectService::getLogs($objectId)` (ADR-022) — `create` actor (mandatory) + any `update`
  actor; exclude read/delete. No hand-rolled parallel actor log.
- [x] 1.3 DENY when `approverId ∈ preparerSet` with the actionable self-approval message.
- [x] 1.4 FAIL CLOSED: DENY on empty approver, empty object id, no determinable `create` actor,
  empty audit trail, or any thrown exception — never pass an indeterminate check.
- [x] 1.5 Expose the four deny messages as public constants for test assertions.
- [x] 1.6 Support both entity-shaped (`getAction`/`getUser`) and array-shaped audit rows.

## 2. Wiring

- [x] 2.1 Register the guard under its own FQCN DI tag in `lib/AppInfo/Application.php`
  (implements the interface directly; the shared adapter cannot forward the caller uid).
- [x] 2.2 Add `"requires": "OCA\\Shillinq\\Lifecycle\\FourEyesPaymentRunGuard"` to the
  `PaymentRun.approve` transition and update its description in
  `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`.
- [x] 2.3 Bump the `PaymentRun` schema `version` `0.1.0 → 0.2.0` so the re-import applies the
  new `requires`.

## 3. i18n

- [x] 3.1 Add the four guard messages to `l10n/en.json` (English).
- [x] 3.2 Add Dutch translations to `l10n/nl.json`.

## 4. Tests

- [x] 4.1 `tests/Unit/Lifecycle/FourEyesPaymentRunGuardTest.php` — **preparer self-approves →
  REJECTED** (the failing-path proof), plus different-user ALLOWED and draft-modifier REJECTED.
- [x] 4.2 Indeterminate-preparer cases (no `create` actor / empty trail / unknown create user)
  all BLOCKED, not passed.
- [x] 4.3 Unknown-approver and unidentifiable-batch BLOCKED without an audit read; audit-read
  exception fails closed.
- [x] 4.4 `@self`-envelope id and array-shaped rows honoured.

## 5. Validation

- [x] 5.1 `register.d` JSON valid; `requires` + version confirmed.
- [x] 5.2 Run the guard test in the `php:8.3-cli` container (fresh `composer install`) — green.
- [x] 5.3 Hydra mechanical gates on changed files (spdx, forbidden-patterns, unsafe-auth-resolver,
  orphan-auth, notification-dialect, manifest-validation).
