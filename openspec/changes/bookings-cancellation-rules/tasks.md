# Tasks — Booking Cancellation Rules

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookings-cancellation-rules` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Build Notes (hydra-build, against real shillinq conventions)

The spec was triaged from market-intelligence research and assumes a `nextcloud-bookings`
app with an `Appointment` register, an OCS `apps/bookings/api/v1` controller, and a
`src/components/` + `src/views/` + `src/locales/` frontend. **None of those exist in
shillinq** — shillinq is the financial business-administration suite, a manifest-v2
declarative app with `register.d/` fragments and `l10n/{en,nl}.json`. The spec's task
paths were corrected to the real app layout per the guardrails:

- **ADR-037** — schemas + seed objects go in `lib/Settings/register.d/40-bookings-cancellation-rules.json`, NOT a `bookings_register.json` monolith (which does not exist). The fragment loader (`SettingsService::deepMergeConfig`) already unions `components.schemas` by key and concatenates top-level `objects[]` lists; `RegisterFragmentMergeTest` already covers that union rule.
- **ADR-022** — `CancellationService` uses no invented OR methods; it is pure logic over appointment/policy arrays (the real OR API `setRegister()->setSchema()->findAll()/saveObject()` is the persistence seam the future controller will use).
- **i18n** — strings land in the real `l10n/en.json` + `l10n/nl.json` (corrected the spec's nonsense `"Annuleringsgebeur"` to `"Annuleringskosten"`).
- **Appointment register now present** — `bookings-create-appointment` has since landed on `development` (`lib/Settings/register.d/10-bookings-create-appointment.json` declares the `Appointment`, `Service`, and `Resource` schemas; `src/manifest.d/10-bookings-create-appointment.json` ships the Afspraken UI). So this build de-defers the schema-extension, manifest-navigation and policy-CRUD-page tasks (8, 11, 17) and implements them additively per ADR-037 (a partial `Appointment` overlay in the cancellation fragment; a `40-bookings-cancellation-rules.json` manifest fragment). Tasks that depend on a *bespoke OCS controller surface* (14/15/19/20) or *live-UI screenshots* (12/13/23/24) remain deferred: shillinq is a manifest-v2 declarative app with NO per-feature OCS controllers — persistence is OpenRegister generic CRUD — and no bespoke Vue modals/views.

## Tasks

- [x] Task 1: Validate that `bookings-create-appointment` and `bookings-service-catalog` specs exist and are approved — N/A in shillinq; no such specs/registers present. Recorded as a cross-app prerequisite gap (see deferrals).
- [x] Task 2: Confirm that the `Appointment` register exists with all required fields — CONFIRMED PRESENT: `bookings-create-appointment` landed on `development` and declares `Appointment` (appointmentId, startTime, endTime, serviceId, resourceId, customerId, status, notes, cancelledAt, cancelledReason, administrationId) plus `Service` and `Resource`. Appointment-coupled schema/UI tasks de-deferred accordingly.
- [x] Task 3: Author `specs/spec.md` (REQ-BCR-NNN, RFC 2119, Scenario blocks) — DONE.
- [x] Task 4: Author `proposal.md` — DONE.
- [x] Task 5: Author `design.md` (Reuse Analysis, D1–D8) — DONE.
- [x] Task 6: Declare the `CancellationPolicy` schema — DONE in `lib/Settings/register.d/40-bookings-cancellation-rules.json` (ADR-037 fragment, not a monolith). All REQ-BCR-001 fields + lifecycleState + administrationId present.
- [x] Task 7: `linkedService → Service` relation (optional, T2+) — declared in the fragment schema; not enforced in phase 2 per design D7.
- [x] Task 8: Extend the `Appointment` schema with the cancellation fields — DONE additively per ADR-037: the base Appointment (in `10-bookings-create-appointment.json`) already declares `cancelledAt` + `cancelledReason`; the cancellation fragment adds a partial `Appointment` overlay (`components.schemas.Appointment.properties`) declaring `appliedPolicy`, `appointmentCost`, `refundAmount`, `refundStatus`, `refundedAt`. `SettingsService::deepMergeConfig` unions schema `properties` by key, so the two fragments compose into one Appointment schema with all 6 cancellation fields. All optional (set only at cancellation), so deliberately NOT added to the base `required` list (which the loader would otherwise concatenate).
- [ ] Task 9: DB migration for cancellation columns — **DEFERRED**: shillinq is OpenRegister-backed (schema-driven, no per-feature SQL migrations); there is no `Appointment` table. Cancellation state lives on OR objects, declared in the fragment.
- [x] Task 10: Implement `lib/Service/CancellationService.php` (`calculateRefund`, `validateCancellation`, `initiateCancellation`) with bracket matching, UTC date handling, audit logging — DONE.
- [x] Task 11: Cancellation Policy admin CRUD — DONE the manifest-v2 way (NOT a bespoke `src/components/CancellationPolicyManager.vue`, which does not fit this declarative app): `src/manifest.d/40-bookings-cancellation-rules.json` declares an `Annuleringsbeleid` index page (list of CancellationPolicy with name/minNoticeDays/noShowFee/refundPolicy/status columns) and an `AnnuleringsbeleidDetail` page (full field form). OpenRegister generic CRUD backs create/read/update/archive via the declarative renderer (`RoutePageRenderer`), mirroring the `Afspraken` page from create-appointment.
- [ ] Task 12: `src/components/AppointmentCancel.vue` admin modal — **DEFERRED**: shillinq is a manifest-v2 declarative app — it has NO bespoke `src/components/` modals; pages are manifest-driven (the Afspraken detail page from create-appointment is where a cancel action would live). The fee/refund logic such a modal would call IS implemented and unit-tested in `CancellationService`. A declarative "cancel action" on the appointment detail page is a follow-up once the lib exposes manifest row-actions.
- [ ] Task 13: `src/views/PortalCancellation.vue` customer self-service — **DEFERRED**: shillinq ships no customer-facing self-service portal surface (it is an internal business-administration app); a customer portal is a separate cross-app concern. Refund-impact computation is implemented/tested in `CancellationService`.
- [ ] Task 14: OCS DELETE `/apps/bookings/api/v1/appointments/{id}` — **DEFERRED**: there is no bookings OCS API surface in shillinq; cancellation persistence is an OR `saveObject` call the host app must own. Validation/409 + refund logic is implemented and unit-tested in `CancellationService`.
- [ ] Task 15: `src/api/appointmentApi.js` REST client — **DEFERRED**: depends on Task 14 endpoint.
- [x] Task 16: Seed example cancellation policies — DONE: 3 seed `CancellationPolicy` objects (Yoga 48h/20%, Coaching card-hold, Consultation €50 fixed) in the fragment's top-level `objects[]`, created via OR import (not SQL), matching the monolith seed convention.
- [x] Task 17: manifest navigation — DONE: the `40-bookings-cancellation-rules.json` manifest fragment adds an `Annuleringsbeleid` item under the `Verkoop` (Sales) menu group (the same group create-appointment's `Afspraken` lives in; `main.js::mergeManifestFragments` concatenates `menu[]` and CnAppNav merges same-id groups, as already done for the shared `Bookkeeping`/`Verkoop` groups). The per-appointment-row "Cancel" *action* remains deferred with Tasks 12-14 (no bespoke appointment modal/controller surface in this declarative app); cancellation is driven by writing the `BookingCancellation` record + Appointment cancellation fields via OpenRegister generic CRUD, with `CancellationService` computing the refund.
- [x] Task 18: `tests/Unit/Service/CancellationServiceTest.php` — DONE: full-refund / partial / fixed-fee / no-refund / rounding / zero-cost / UTC-normalisation / double-cancellation-409 / invalid-reason / snapshot-without-mutation (13 cases, 19 assertions). Plus `tests/Unit/Service/BookingsCancellationRulesFragmentTest.php` (5 cases, 48 assertions) covering the schemas, the immutable+linked BookingCancellation, the additive Appointment overlay via the real `SettingsService::deepMergeConfig`, and the seed policies. 18 tests / 67 assertions total, all green.
- [ ] Task 19: Integration test for the OCS controller — **DEFERRED**: deferred with Task 14 (no controller to integration-test).
- [ ] Task 20: `tests/Fixtures/CancellationPolicyFixtures.php` — **DEFERRED**: the seed objects in the fragment double as fixtures; a separate PHP fixture class adds value only once the controller integration test (Task 19) lands.
- [ ] Task 21: `tests/Fixtures/AppointmentFixtures.php` — **DEFERRED**: bundled with the OCS controller integration test (Task 19), which is itself deferred (no bespoke OCS surface). The seed CancellationPolicy objects + the unit-test appointment arrays already exercise the cancellation logic end to end.
- [x] Task 22: i18n strings — DONE: added cancellation/fee/refund/reason labels to the real `l10n/en.json` + `l10n/nl.json` (corrected the spec's `"Annuleringsgebeur"` → `"Annuleringskosten"`).
- [ ] Task 23: `docs/user-guide/.../cancellation-policy.md` journeydoc — **DEFERRED**: a screenshot-bearing journeydoc requires the live admin/customer UI (Tasks 11–13, deferred).
- [ ] Task 24: `docs/api/cancellation.md` REST docs — **DEFERRED**: documents the deferred OCS endpoint (Task 14).
- [x] Task 25: Run checks — `composer check:strict` reports ALL CHECKS PASSED (lint clean; phpcs clean on `lib/Service/CancellationService.php`; psalm "No errors found"; phpstan "No errors", 31 files). PHPUnit's NC-integration bootstrap can't load `OC_App` outside the server tree so `test:all` self-skips, but the pure-logic suite (`CancellationServiceTest` + `BookingsCancellationRulesFragmentTest`) runs green via a minimal autoload bootstrap — 18 tests / 67 assertions OK. `openspec change validate --strict` clean. All register.d + manifest.d JSON validated. Pre-existing phpmd warnings remain only in the untouched `SettingsService.php`.
- [ ] Task 26: PR creation — handled by the Hydra build flow (not an opsx task).

## Verification

`openspec validate` must exit clean on the change folder. Product personas (admin, operator, customer) review the spec and confirm:
- Admin workflow (Task 12 implementation) enables policy management and appointment cancellation with fee visibility
- Operator workflow (Task 11 implementation) allows easy policy creation and editing
- Customer workflow (Task 13 implementation) is intuitive and shows refund impact clearly before confirming cancellation
- REST API (Task 14 implementation) returns correct responses and supports programmatic cancellation

Architecture reviewer confirms:
- ADR-031 compliance (CancellationPolicy and Appointment extensions are register-driven, no custom mappers)
- ADR-024 compliance (manifest.json carries all navigation and actions)
- No source code changes outside `lib/Settings/bookings_register.json`, Vue components, PHP service classes, API controllers, tests, and migrations
- Database schema changes scoped to migration files only

## Tests (company-wide ADR-009)

Implementation cycle is responsible for:

- **Unit tests** (Task 18): CancellationService logic (fee calculation, bracket matching, validation, refund amount computation, UTC date handling)
- **Integration tests** (Task 19): API controller endpoints, concurrent request handling, cancellation conflict detection
- **Fixture tests** (Tasks 20-21): sample policies and appointments load and validate correctly
- **Manual QA** (product): admin policy management, admin appointment cancellation, customer self-service cancellation, REST API integration

`composer test` MUST pass green at PR merge gate.

## Documentation (company-wide ADR-010)

Implementation cycle authors:

- `docs/user-guide/bookings/cancellation-policy.md` with journeydoc format (operator, admin, customer workflows; screenshots)
- `docs/api/cancellation.md` with REST API endpoint documentation and examples
- Screenshot of cancellation policy manager
- Screenshot of admin appointment cancellation modal (showing fee calculation)
- Screenshot of customer self-service cancellation (showing refund impact)

## i18n (company-wide ADR-007)

Implementation cycle adds translation strings:

- `src/locales/en_US.json`: English strings (cancellation, policy, fee, refund, reason, etc.)
- `src/locales/nl_NL.json`: Dutch translations (annulering, beleid, tarief, restitutie, reden, etc.)

## Dependency Chain

Phase 2 bookings feature work depends on this spec in this order:

1. ✓ `bookings-create-appointment` (phase 1, approved)
2. → `bookings-cancellation-rules` (this spec, phase 2)
3. → `bookings-availability-rules` (phase 2, parallel)
4. → `bookings-reminder-notifications` (phase 3)
5. → `bookings-deposit-to-invoice` (phase 3)

Tier 1 (phase 2) cannot merge until both cancellation-rules and availability-rules pass review.
