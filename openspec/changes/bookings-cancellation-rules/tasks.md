# Tasks — Booking Cancellation Rules

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookings-cancellation-rules` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Validate that `bookings-create-appointment` and `bookings-service-catalog` specs exist and are approved (scan openspec/changes/ and verify both are marked status: approved in their proposals)
- [ ] Task 2: Confirm that the `Appointment` register exists with all required fields from phase 1; if missing, list gaps and create prerequisite changes
- [ ] Task 3: Author `specs/bookings-cancellation-rules/spec.md` with Status: proposed / Scope: nextcloud-bookings / Tier: T1-phase2 header, REQ-BCR-NNN requirements per RFC 2119, and `#### Scenario:` blocks with GIVEN/WHEN/THEN per hydra conventions (DONE — this file)
- [ ] Task 4: Author `proposal.md` with Affected Projects / Scope / Risks / Rollback / Open Questions per nextcloud-bookings config.yaml rules (DONE)
- [ ] Task 5: Author `design.md` with Reuse Analysis table, Migration Plan, and design decisions (D1–D8) per hydra rules; product personas (admin, customer, operator) review and confirm the design serves their workflows
- [ ] Task 6: Declare the `CancellationPolicy` schema in `lib/Settings/bookings_register.json` with all fields from REQ-BCR-001 (policyId, name, description, minNoticeDays, rescheduleWindowDays, lateFeeBrackets, noShowFee, cardHoldRequired, refundPolicy, linkedService, status) with correct types and required flags
- [ ] Task 7: Add relation declarations to `CancellationPolicy` schema for linkedService → Service (optional, T2+) using `x-openregister-relations` per ADR-031
- [ ] Task 8: Extend the `Appointment` schema in `lib/Settings/bookings_register.json` with 6 new fields from REQ-BCR-002 (cancelledAt, cancelledReason, appliedPolicy, refundAmount, refundStatus, refundedAt) with correct types and non-required flags (all optional post-creation)
- [ ] Task 9: Create database migration `lib/Migration/VersionXXXX_AppointmentCancellationFields.php` adding columns: `cancelled_at` (datetime, nullable), `cancelled_reason` (varchar, nullable), `applied_policy` (json, nullable), `refund_amount` (integer, nullable, default 0), `refund_status` (varchar, nullable, default 'pending'), `refunded_at` (datetime, nullable); add indexes on `(appointmentId, cancelledAt)` for audit queries
- [ ] Task 10: Implement `lib/Service/CancellationService.php` with methods: `calculateRefund(Appointment $appointment): int`, `validateCancellation(Appointment $appointment): ValidationResult`, `initiateCancellation(Appointment $appointment, string $reason): Appointment`; includes fee bracket matching logic, ISO 8601 UTC date handling, and audit trail logging
- [ ] Task 11: Implement `src/components/CancellationPolicyManager.vue` admin component with CRUD actions: List (table showing all policies with minNoticeDays, status), Create (form with all fields), Edit (modal with form), Delete/Archive (soft delete by setting status=archived)
- [ ] Task 12: Implement `src/components/AppointmentCancel.vue` admin modal with: appointment details display, applied policy display (minNoticeDays, brackets, fees in plain language), fee calculation display (refund amount + fee breakdown), reason dropdown, confirmation modal, and "Confirm Cancellation" action that calls `CancellationService.initiateCancellation()`
- [ ] Task 13: Implement `src/views/PortalCancellation.vue` customer self-service view with: list of customer's upcoming appointments, "Cancel" button on each, modal showing policy + refund calculation + late-fee warning (if applicable), reason selection, and cancellation confirmation
- [ ] Task 14: Extend `lib/Controller/AppointmentApiController.php` with DELETE handler for `/ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}`: accept optional JSON body with `{reason, notes}`, call `CancellationService.initiateCancellation()`, return 200 with full appointment object on success, return 409 Conflict if already cancelled, return 400 Bad Request if validation fails
- [ ] Task 15: Extend `src/api/appointmentApi.js` REST client with `cancel(appointmentId, reason)` method wrapping the DELETE endpoint
- [ ] Task 16: Create `lib/Migration/VersionXXXX_SeedCancellationPolicies.php` seeding 3 example policies: Yoga Classes (48h notice, 20% late fee), Personal Coaching (24h notice, 50% late fee, card hold), Consultations (24h notice, €50 fixed fee). Policies are created via OpenRegister CRUD, not direct SQL.
- [ ] Task 17: Add appointment cancellation navigation action to `src/manifest.json` (admin appointments list gains "Cancel" modal action bound to AppointmentCancel component) and add "Cancellation Policies" admin section under Settings
- [ ] Task 18: Create `tests/Unit/Service/CancellationServiceTest.php` covering: happy path (cancel within notice, cancel after notice with fee), fee calculation (percentage, fixed, brackets), validation (already cancelled, not found), refund amount calculation (full refund, partial, zero), timezone handling (UTC storage)
- [ ] Task 19: Create `tests/Integration/Api/AppointmentApiControllerTest.php` covering: DELETE /appointments/{id} success (200, returns full cancelled appointment), duplicate cancellation (409 Conflict), invalid reason, auth checks (admin vs customer), concurrent cancellation attempts (only one succeeds, other gets 409)
- [ ] Task 20: Create `tests/Fixtures/CancellationPolicyFixtures.php` with sample policies (yoga, coaching, consultation) for testing, covering multiple bracket configurations and fee types
- [ ] Task 21: Create `tests/Fixtures/AppointmentFixtures.php` (extend existing) with appointment variants covering different policy scenarios: confirmed, ready-to-cancel (>48h), ready-to-cancel-with-fee (<48h), no-show-scenario
- [ ] Task 22: Add i18n strings to `src/locales/en_US.json` and `src/locales/nl_NL.json` per REQ-BCR-005 (cancellation, fee, refund, reason labels, etc.)
- [ ] Task 23: Create `docs/user-guide/bookings/cancellation-policy.md` journeydoc (per ADR-030) covering: operator workflow (create policy, assign to service T2+), admin cancellation (click cancel, see fee, confirm), customer cancellation (self-service, see refund impact), REST API example (curl DELETE request + response)
- [ ] Task 24: Create `docs/api/cancellation.md` REST API documentation with endpoint definition, request/response examples, error codes (200, 400, 409)
- [ ] Task 25: Run `composer test` to ensure all unit + integration tests pass; run `npm run lint` to ensure Vue component linting passes; verify `openspec validate` exits 0 on the spec
- [ ] Task 26: Create a PR with all implementation changes, link to the spec proposal in PR description, request review from @bookings-team and @product; include screenshots of admin cancellation policy manager and customer self-service cancellation

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
