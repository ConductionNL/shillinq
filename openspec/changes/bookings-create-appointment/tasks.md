# Tasks — Appointment Create

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookings-create-appointment` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Validate that `bookings-resource-calendar` and `bookings-service-catalog` specs exist and are approved (scan openspec/specs/ and verify both are marked status: approved)
- [ ] Task 2: Confirm that the `Customer` register exists and has at minimum fields: customerId, email, phone, status, createdAt (if not, create `bookings-customer-profiles` as a prerequisite phase-1 spec)
- [ ] Task 3: Author `specs/bookings-create-appointment/spec.md` with Status: proposed / Scope: nextcloud-bookings / Tier: T1 header, REQ-BCA-NNN requirements per RFC 2119, and `#### Scenario:` blocks with GIVEN/WHEN/THEN per hydra conventions
- [ ] Task 4: Author `proposal.md` with Affected Projects / Scope / Risks / Rollback / Open Questions per nextcloud-bookings config.yaml rules
- [ ] Task 5: Author `design.md` with Reuse Analysis table, Migration Plan, and design decisions (D1–D6) per hydra rules; product personas (admin, customer) review and confirm the design serves their workflows
- [ ] Task 6: Declare the `Appointment` schema in `lib/Settings/bookings_register.json` with all REQ-BCA-002 fields (appointmentId, startTime, endTime, serviceId, resourceId, customerId, status, notes, createdAt, createdBy, updatedAt, cancelledAt, cancelledReason) with correct types and required flags
- [ ] Task 7: Add relation declarations to `Appointment` schema for serviceId → Service.serviceId and resourceId → Resource.resourceId using `x-openregister-relations` per ADR-031
- [ ] Task 8: Create database migration `lib/Migration/VersionXXXX_AddAppointmentTable.php` with indexed unique constraint on (resourceId, startTime, endTime) for conflict detection; add indexes on (customerId, status, startTime) for query optimization
- [ ] Task 9: Implement `src/components/AppointmentCreate.vue` admin component with fields: service selector, resource selector, date/time picker (UTC input with browser-local display), customer search, notes field, plus "Confirm" and "Clear" actions
- [ ] Task 10: Implement `src/views/PortalBooking.vue` customer self-service portal with service selector → available slots display → date/time picker → confirmation screen per REQ-BCA-010
- [ ] Task 11: Implement `lib/Controller/AppointmentApiController.php` with POST handler for `/ocs/v2.php/apps/bookings/api/v1/appointments`, including request validation (REQ-BCA-008), availability check (REQ-BCA-004), duration validation (REQ-BCA-003), and customer eligibility (REQ-BCA-006); return 201 Created on success, 400 Bad Request / 409 Conflict on failure
- [ ] Task 12: Implement `src/Service/AppointmentService.php` with `createAppointment()` method: accepts appointment DTO, validates duration/availability/eligibility, persists via OpenRegister API, returns full appointment object with appointmentId
- [ ] Task 13: Implement `src/api/appointmentApi.js` REST client (post, getById, list) wrapping the appointment endpoints; used by Vue components
- [ ] Task 14: Add appointment navigation entry to `src/manifest.json` (menu item: Bookings > Appointments; navigation to `/apps/bookings/appointments` list view) and appointment create modal action (button in admin dashboard)
- [ ] Task 15: Create `tests/Unit/Service/AppointmentServiceTest.php` covering: happy path (create confirmed/pending appointments), duration validation (matches/mismatches), availability validation (available/conflicts), customer eligibility (active/suspended), edge cases (timezone conversion, UTC storage)
- [ ] Task 16: Create `tests/Integration/Api/AppointmentApiControllerTest.php` covering: POST /appointments (201, 400, 409 responses), payload validation, auth checks (admin vs customer), concurrent request handling (duplicate attempts should fail with 409 or retry cleanly)
- [ ] Task 17: Create `tests/Fixtures/AppointmentFixtures.php` with sample appointment objects for testing, covering multiple status states and resource/service combinations
- [ ] Task 18: Add i18n strings to `src/locales/en_US.json` and `src/locales/nl_NL.json` for: "Appointment", "Create Appointment", "Date & Time", "Service", "Resource", "Customer", "Confirmation", "Booked", "Pending Confirmation", "Slot Unavailable", "Duration Mismatch", etc.
- [ ] Task 19: Create `docs/user-guide/bookings/create-appointment.md` journeydoc (per ADR-030) covering: admin booking flow (1–2 screenshot), customer self-service flow (1–2 screenshots), REST API example (curl command + response)
- [ ] Task 20: Run `composer test` to ensure all unit + integration tests pass; run `npm run lint` to ensure Vue component linting passes; verify `node tests/validate-manifest.js` exits 0 (manifest structure valid)
- [ ] Task 21: Create a PR with all implementation changes, link to the spec proposal in PR description, request review from @bookings-team and @product

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
