# Tasks: revive-lease-capabilities

## Verification (Step 1)

- [x] 1. Re-verify all five methods against `origin/development` HEAD
  (repo/fleet `->method(` grep, dynamic dispatch, `register.d`
  handler/guard/requires strings, routes, `info.xml` jobs, DI/listener
  registration) and the superseding check (single-writer grep on both
  schemas); record the verdict table with caller evidence in `design.md`.
- [x] 2. Confirm OR has no declarative action executor and that
  `LeaseContract`'s list-form transitions block `ObjectTransitionedEvent`;
  select `ObjectUpdatedEvent`/`ObjectCreatedEvent` as the executing trigger
  (design D1).

## Schedule generation (`LeasePaymentScheduleService::generateSchedule`)

- [x] 3. New `lib/Listener/LeaseActivationListener.php` on `ObjectUpdatedEvent`
  + `ObjectCreatedEvent`, filtered to schema `LeaseContract`, firing
  `generateSchedule` on the edge into `active` (`ObjectUpdatedEvent`:
  `old.status !== 'active' && new.status === 'active'`; `ObjectCreatedEvent`:
  `status === 'active'`). Fail-soft; resolve lease id + `administrationId`
  from the object.
- [x] 4. Register `LeaseActivationListener` for both events in
  `Application::register()` next to `StockMoveTransitionedListener`.

## Reassessment events (four `LeaseReassessmentService::record*` methods)

- [x] 5. New `lib/Controller/LeaseReassessmentController.php`: four
  `#[NoAdminRequired]` `POST` endpoints — `indexation`,
  `extensionOption`, `modification`, `impairment` — each rejecting the
  anonymous caller (401), validating `administrationId` via
  `AdministrationContextService::canAccess` (404 cross-tenant), validating
  the lease-id slug + the event body, calling the matching `record*` method,
  and returning the persisted event payload (201) / structured errors with
  no stack trace.
- [x] 6. Four routes in `appinfo/routes.php` under `leaseReassessment#…`
  (`POST /api/leases/reassessment/{indexation|extension-option|modification|impairment}`).

## Tests (the gate)

- [x] 7. `tests/Unit/Listener/LeaseActivationListenerTest.php` — a
  `LeaseContract` `draft → active` `ObjectUpdatedEvent` persists one
  `LeasePaymentSchedule` row per period whose amounts amortize (final-period
  closing lease liability ≈ 0; each row `interest + principal ≈ payment`);
  an exempt lease and a non-edge save (already `active`, or a non-lease
  schema) persist nothing; a `created active` lease also generates.
- [x] 8. `tests/Unit/Controller/LeaseReassessmentControllerTest.php` — each of
  the four endpoints invokes its `record*` method and returns a payload whose
  `glLines` **balance** (`sum(debit) === sum(credit)`) and whose RoU
  adjustment equals the liability delta (catch-up) / the impairment magnitude;
  plus 401 anonymous and 404 cross-tenant.
- [x] 9. Full unit suite green in a `php:8.3-cli` container
  (`phpunit-unit.xml`) with no new failures vs the baseline; `phpcs` +
  `phpstan` clean on the changed paths.
