# Tasks — Service Catalogue (duration, price, buffers)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookings-service-catalog` spec —
> they are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files are
> edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookings-service-catalog` capability spec already exists, no `Service` schema is declared in OpenRegister, and no app-local `*_service` / `service_*` database tables are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "enables all scheduling, booking, and appointment workloads"
- [x] Task 2: Author `specs/bookings-service-catalog/spec.md` with `Status: proposed` / `Scope: nextcloud-apps` / `Tier: T1 (foundational data model)` / `Depends on: none` header, `REQ-SC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-031 + ADR-032 inline
- [x] Task 3: Author `proposal.md` including Affected Projects / Scope / Risks (temporal buffer semantics, pricing complexity deferral, resource-type linking clarity) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (Service is foundational T1 register), D2 (temporal dimensions are primitives), D3 (pricing is base-only + dynamic flag), D4 (resource-type is single FK), D5 (categorization is flat), D6 (status lifecycle is minimal), plus Seed Data section with 5 Dutch SMB example services
- [x] Task 5: Declare the `Service` schema in OpenRegister global register (or app-specific manifest per Nextcloud deployment topology) with all REQ-SC-002 fields (id, name, code, description, duration, prepareTime, bufferBefore, bufferAfter, basePrice, currency, dynamicPricing, serviceCategory, resourceTypeRef, status, administrationId)
- [x] Task 6: Implement validation rules per REQ-SC-003 (duration ≥ 0; prepareTime, bufferBefore, bufferAfter ≥ 0; all temporal constraints); service code uniqueness per administration per REQ-SC-008
- [x] Task 7: Declare `status` enum field with three valid values (`draft`, `active`, `archived`) per REQ-SC-005; no lifecycle guards required in T1 (simple state machine)
- [x] Task 8: Implement pricing validation per REQ-SC-004 (basePrice ≥ 0; dynamicPricing as boolean flag)
- [x] Task 9: Add documentation to design.md clarifying what `resourceTypeRef` MEANS in context of dependent specs (e.g., "refers to skill for appointment scheduling, room for venue booking, staff specialty for resource allocation") — mark as TBD resolution during `opsx-ff` discovery if not pre-clarified
- [x] Task 10: Add 5 seed-data example services to design.md (as JSON objects) covering Dutch SMB use cases: salon haircut, consulting hour, venue rental, event catering, SaaS subscription — with realistic temporal and pricing dimensions
- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with `Service` entry, including all REQ-SC-002 fields, Schema.org annotation (`schema:Service`), and relations (if any to `ServiceCategory`, `ResourceType`, etc. — mark as TBD if not defined)
- [x] Task 12: Verify `openspec validate` exits clean on the change folder; confirm no syntax errors in spec.md GIVEN/WHEN/THEN blocks

## Verification

`openspec validate` must exit clean on the change folder. Booking-practitioner peer review
(e.g., `/test-persona-sem` for digital-native SMB owner) confirms the service-catalogue
fields cover Dutch SMB use cases (salons, consulting, event venues, subscriptions).
Architecture reviewer confirms ADR-031 + ADR-032 compliance (no app-local service table;
temporal/pricing/resource fields complete; resource-type FK clear or marked TBD; lifecycle
declarative/minimal).

No source code changes outside `openspec/changes/bookings-service-catalog/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate
`opsx-apply`) is responsible for:
- PHPUnit unit tests for `Service` schema validation (temporal constraints, code uniqueness,
  dynamic-pricing flag behavior) per REQ-SC-003 and REQ-SC-004
- Playwright MCP browser tests for CRUD operations (create draft service, activate, archive)
  per REQ-SC-005
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/bookings/service-catalogue.md` per ADR-030 journeydoc convention
- Screenshots of service-creation UI (draft form, active catalog view, archive action) to
  `docs/images/`
- Clarification of temporal dimensions (duration, buffers, prep-time) with Dutch SMB
  examples

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch
(`nl_NL`) and English (`en_US`) translation strings for:
- `Service`, `Services`, `Service Catalogue`
- `Duration`, `Preparation Time`, `Buffer Before`, `Buffer After`
- `Base Price`, `Dynamic Pricing`, `Service Category`
- `Resource Type`, `Status`, `Active`, `Draft`, `Archived`
- `Service Code`, `Service Name`, `Service Description`
