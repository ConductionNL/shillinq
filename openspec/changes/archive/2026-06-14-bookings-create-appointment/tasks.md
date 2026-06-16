# Tasks — Appointment Create

> **Implemented against the real `shillinq` app conventions.** The original spec was authored generically for a fictional `nextcloud-bookings` app (bespoke REST controllers, `bookings_register.json`, `src/components/*.vue`, `src/locales/*`). Shillinq is a fully declarative manifest-v2 + OpenRegister-CRUD app: data lives in register schemas (served by OR's generic CRUD HTTP surface), list/detail UIs are declarative manifest pages (no bespoke Vue), business-rule validation lives in lifecycle Guards (ADR-031), and ADR-037 register/manifest fragments avoid editing the monolith. The tasks below are corrected to those conventions; the spec's REQ intent is preserved.

## Tasks

- [x] Task 1: Confirm the appointment data model belongs in OpenRegister (ADR-031/ADR-024) — no app-local PHP model or DB table. The `Appointment` register schema is the canonical entity, served by OR's generic CRUD (REQ-BCA-001).
- [x] Task 2: Customer is a Nextcloud contact entity, NOT a bespoke `Customer` schema (fleet convention). `Appointment.customerId` is an FK to the customer contact record; eligibility is enforced against an app-managed `Contact` status field when present, otherwise treated as an external NC contact (REQ-BCA-006).
- [x] Task 3: Spec authored at `specs/bookings-create-appointment/spec.md` (REQ-BCA-001..010, RFC 2119, GIVEN/WHEN/THEN scenarios).
- [x] Task 4: `proposal.md` authored (Affected Projects / Scope / Risks / Rollback / Open Questions).
- [x] Task 5: `design.md` authored (Reuse Analysis, Migration Plan, decisions D1–D6).
- [x] Task 6: Declared `Appointment`, `Service`, and `Resource` schemas in the ADR-037 fragment `lib/Settings/register.d/10-bookings-create-appointment.json` (NOT the monolith). Appointment carries appointmentId, startTime, endTime, serviceId, resourceId, customerId, status, notes, cancelledAt, cancelledReason, administrationId with typed/required flags (REQ-BCA-002). OR auto-manages id/uuid/owner/version/auditTrail.
- [x] Task 7: Added `x-openregister-relations` on `Appointment` (serviceId → Service.serviceId, resourceId → Resource.resourceId) per ADR-031.
- [x] Task 8 (corrected): No `lib/Migration/*` — OR owns the storage table for register objects; appps do not write per-schema migrations. Double-booking is enforced in the `AppointmentGuard` save precondition (interval-overlap check, REQ-BCA-004) rather than a DB unique constraint, because OR object tables are generic. Customer/status query optimisation is OR's concern.
- [x] Task 9 (corrected): No bespoke `AppointmentCreate.vue` — admin create/edit is the declarative manifest detail page `AfspraakDetail` (OR generic form). Added via `src/manifest.d/10-bookings-create-appointment.json` under the new **Verkoop → Afspraken** menu (REQ-BCA-009; placement per context-brief SUB_PAGE).
- [DEFERRED] Task 10: Customer self-service portal (`PortalBooking.vue`, REQ-BCA-010) — deferred. Shillinq has no public customer portal surface yet; a `#[PublicPage]` self-service portal needs an authenticated-customer session model + signature-verified entry that does not exist in this app. The status pathway (pending_confirmation vs confirmed, REQ-BCA-005) is already modelled in the schema lifecycle; the portal UI is a follow-up. See deferral note below.
- [x] Task 11 (corrected): No bespoke `AppointmentApiController` / OCS route — appointment create/read is served by OpenRegister's generic CRUD endpoints for the `Appointment` schema (REQ-BCA-008). Server-side validation (payload/availability/duration/eligibility) runs in `AppointmentGuard::validateOnSave` on every persist, so the rules apply uniformly to UI, API, and any future portal caller.
- [x] Task 12: Implemented `lib/Guard/AppointmentGuard.php` (`validateOnSave`) using the real OR ObjectService API (`setRegister`/`setSchema`/`find`/`findAll`): time-window validity, duration match vs Service.duration (REQ-BCA-003), operational-hours + double-booking (REQ-BCA-004), customer eligibility (REQ-BCA-006). Fail-closed (CWE-863).
- [DEFERRED] Task 13: `src/api/appointmentApi.js` REST client — not needed; declarative manifest pages consume OR's CRUD via the shared manifest store (`createObjectStore`). No bespoke client.
- [x] Task 14: Added the **Verkoop → Afspraken** menu entry + `Afspraken` index page + `AfspraakDetail` detail page to the manifest fragment (REQ-BCA-002 list/detail surface).
- [x] Task 15: `tests/Unit/Guard/AppointmentGuardTest.php` — happy path, duration match/mismatch/tolerance, operational-hours, double-booking conflict/cancelled/allowOverlap, customer active/suspended, cancelled-skip, invalid window, T1-absent catalog, fail-closed (13 tests).
- [DEFERRED] Task 16: Integration API controller test — N/A; there is no bespoke controller. Concurrency/conflict behaviour is covered at the guard level (overlap detection test). A live-instance OR CRUD integration test is a runtime-verify follow-up.
- [x] Task 17 (corrected): Test fixtures are inline in `AppointmentGuardTest` (`appointment()`/`catalog()` builders) covering multiple statuses + service/resource combinations; the register fragment also ships seed Service + Resource objects.
- [x] Task 18: Added nl + en i18n strings additively to `l10n/en.json` and `l10n/nl.json` (Verkoop, Afspraken, Afspraak, Service, Resource, Customer, statuses, Slot unavailable, Duration mismatch, Outside operational hours, Customer account is suspended, etc.).
- [DEFERRED] Task 19: journeydoc `docs/...create-appointment.md` (ADR-030) — deferred; requires screenshots from a live instance (admin flow + portal). Filed as documentation follow-up.
- [x] Task 20: `composer` checks run — lint clean; phpcs/phpmd/psalm/phpstan clean on all touched files; unit tests green (AppointmentGuard 13 + fragment 5). `tests/validate-manifest.js` validates only the monolith (manifest.d fragments merge at build); the only structural-lint findings are pre-existing monolith page types (`roadmap`, `report`) unrelated to this change, surfaced only because the npm schema is absent in the build sandbox.
- [x] Task 21 (Hydra coordination, not an opsx task): handled by the hydra build flow (branch + PR).

## Added (beyond original task list)

- [x] `tests/Unit/Service/BookingsCreateAppointmentFragmentTest.php` — proves the ADR-037 fragment is valid JSON, declares Appointment/Service/Resource with lifecycle + relations + guard precondition, and merges additively onto the monolith (no schema/object dropped) (5 tests).

## Deferred work (filed as follow-ups)

- **Customer self-service portal (REQ-BCA-010)** — needs a public/authenticated-customer surface that shillinq does not yet have; schema-level status pathway is ready.
- **Live OR CRUD integration test + journeydoc screenshots** — require a running instance.

## Verification

`openspec validate` must exit clean on the change folder. Product personas (admin, customer) review the spec and confirm:
- Admin workflow (Task 9 implementation) enables fast appointment entry for staff scheduling
- Customer workflow (Task 10 implementation) is intuitive and shows available slots clearly
- REST API (Task 11 implementation) returns correct error codes and allows third-party systems to create appointments

Architecture reviewer confirms ADR-031 + ADR-024 compliance (Appointment is a register, no app-local Mapper class, manifest carries navigation). No source code changes outside `lib/Settings/bookings_register.json`, Vue components, PHP controllers, service classes, and tests. Database schema changes are scoped to the migration file only.

## Tests (company-wide ADR-009)

Implementation cycle is responsible for:

- **Unit tests** (Task 15): AppointmentService logic (duration, availability, eligibility validation)
- **Integration tests** (Task 16): API controller endpoints, concurrent request handling, conflict detection
- **Fixture tests** (Task 17): sample appointments load and validate correctly
- **Manual QA** (product): admin booking, customer portal, REST API integration

`composer test` MUST pass green at PR merge gate. Playwright MCP browser tests (Task 20) cover: create via admin UI, create via customer portal, error states (slot unavailable, customer suspended).

## Documentation (company-wide ADR-010)

Implementation cycle authors:

- `docs/user-guide/bookings/create-appointment.md` with journeydoc format (admin + customer workflows, screenshots)
- Screenshot of admin appointment create modal
- Screenshot of customer portal booking flow
- REST API example in `docs/api/appointments.md` (POST request/response)

## i18n (company-wide ADR-005)

Implementation cycle adds translation strings:

- `src/locales/en_US.json`: English strings (appointment, create, confirm, etc.)
- `src/locales/nl_NL.json`: Dutch translations (`Afspraak`, `Afspraak Maken`, `Bevestigd`, etc.)
