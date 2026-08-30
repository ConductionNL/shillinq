---
kind: config
---

# Proposal: bookings-service-catalog

`kind: config` per ADR-032 — service catalogue with time and pricing dimensions.
Declares `Service` schema with duration, pricing, buffers (pre/post), preparation time, and categorization.
Consumes OR's relationship engine for service-to-category and service-to-resource linkage.
No PHP service classes are authored (subject to ADR-031 exception: at most one single-method
`ServicePricingCalculator` if declarative pricing rules prove insufficient).

## Why

Competitor analysis (21/21 SaaS booking platforms including Bookly, Acuity, Booksy,
Salonized, Vagaro) shows service catalogues with duration, price, and buffer management
are table-stakes features. Dutch SMBs — salons, consultancies, event venues, subscription
businesses — require a structured service catalogue to price services, manage resource
contention, and calculate staff utilisation.

This is a T1 foundational capability. All booking workloads (appointment scheduling,
resource allocation, invoice line generation) depend on `Service` existing with complete
temporal and financial metadata. Without this register, no dependent scheduling,
booking, or calendar spec can land.

## What Changes

- Declare a new `Service` register in `lib/Settings/shillinq_register.json` with 15
  fields covering identification (name, code, description), temporal dimensions
  (duration, prepareTime, bufferBefore, bufferAfter), pricing (basePrice, currency,
  dynamicPricing flag), categorization (serviceCategory, resourceTypeRef), and
  lifecycle (status, administrationId).
- Add `x-openregister-lifecycle` for `draft → active → archived → active` state machine.
- Add `x-openregister-unique` composite constraint on `[code, administrationId]` per REQ-SC-008.
- Add JSON Schema `minimum: 0` validation on all temporal and pricing numeric fields.
- Add 5 Dutch SMB seed data examples (salon haircut, consulting hour, venue rental,
  event catering, SaaS subscription) to the `objects` array.
- Add `Service` entity to `openspec/architecture/adr-000-data-model.md`.
- Add capability spec `specs/bookings-service-catalog/spec.md` with 8 `REQ-SC-*` requirements.

## Summary

Introduce the **service catalogue capability** for booking-enabled Nextcloud apps
as a foundational T1 data-model capability. This capability **enables all scheduling,
booking, and appointment workloads** by declaring the `Service` register with all
temporal and financial dimensions required for slot scheduling, resource allocation,
and pricing calculation.

The change declares the `Service` register with the following fields:
- **Identification**: name, code (slug), description
- **Temporal dimensions**: duration, prepareTime (setup), bufferBefore (gap), bufferAfter (gap)
- **Pricing**: basePrice, currency, dynamicPricing (flag for complex rules)
- **Categorization**: serviceCategory (grouping), resourceTypeRef (skill/room mapping)
- **Configuration**: status, administrationId

The service catalogue is **declarative-first** — all service properties are stored
as register properties; pricing and booking logic consumes these properties via
OR's aggregation primitives. This change conforms to the shared `nextcloud-app` spec
for app structure.

**Depends on:** no internal dependencies. Consumed by all scheduling/booking specs.

## Motivation

Competitor analysis (21/21 SaaS platforms including Bookly, Acuity, Booksy, Salonized)
shows service catalogues with duration, price, and buffer management are table-stakes.
Dutch SMBs (salons, consultancies, event venues) require this to price services,
manage resource contention, and calculate staff utilization.

This is a T1 foundational capability — all booking workloads (appointment scheduling,
resource allocation, invoice line generation) depend on `Service` existing with complete
temporal and financial metadata.

## Affected Projects

- [x] Project: nextcloud-apps (bookings, calendar, resource-planning etc.) — declares
  1 foundational spec (`bookings-service-catalog`); registers the `Service` schema in
  OR's global register with OR-scope visibility.
- [ ] Project: openregister — no source changes; consumes existing `x-openregister-*`
  primitives for relationships, aggregations, and validation.

## Scope

### In Scope

- One new capability spec (`bookings-service-catalog`) — see the `specs/` folder.
- The `Service` register with identification, temporal, pricing, and categorization fields.
- Validation rules (duration > 0, price ≥ 0, buffers ≥ 0).
- Relationship declarations enabling service-to-category, service-to-resource-type FK.
- Pricing policy storage (base price + flag for dynamic/rule-based pricing to be computed
  by dependent specs).
- Service status lifecycle (draft, active, archived) with state transitions.

### Out of Scope

- **Pricing calculation engine** — T2+. Dynamic pricing rules (time-of-day, volume discounts,
  surge pricing) computed in dependent specs, not here.
- **Booking scheduling logic** — T2+. Slot availability, resource allocation, conflict
  detection in dependent specs.
- **Invoice line generation** — T3. Price lookup and line-item composition in billing specs.
- **Multilingual service names** — handled by i18n in dependent specs.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookings-service-catalog`** — declares the `Service` register with all temporal
and financial dimensions, validation rules, relationship declarations for categories
and resources, and service-status lifecycle.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-SC-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions.

## Impact

- OpenRegister global register — adds 1 new schema: `Service`.
- No new PHP services or Vue components.
- All booking/scheduling/resource-allocation specs depend on this.

## Cross-Project Dependencies

None upstream. All booking/calendar/resource-planning apps depend on this capability.

## Risks

### Risk 1: Temporal buffer semantics unclear

**Severity**: Low
**Mitigation**: REQ-SC-003 defines `bufferBefore` and `bufferAfter` with concrete Dutch
SMB examples (salon haircut = 30 min service + 15 min buffer-after for room turnover).
Resolved during UX review.

### Risk 2: Pricing complexity deferred to T2+

**Severity**: Low
**Mitigation**: T1 stores base price + `dynamicPricing` flag. Dependent specs compute
actual price via aggregation rules. Flag signals to UI/booking-engine that base price
is not final (e.g., time-of-day surcharge, volume discount).

### Risk 3: Resource-type linking unclear

**Severity**: Low-Medium
**Mitigation**: `resourceTypeRef` is a string FK. What constitutes a "resource type"
(skill, room, staff specialty) is resolved during `opsx-ff` discovery and documented
in design.md.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime
impact. After implementation (separate cycle), rollback is non-destructive — `Service`
records remain queryable.

## Open Questions

1. **Resource-type linking scope** — does a `Service` reference one resource type or
   many (many-to-many)? Resolved in design.md.
2. **Multi-tenant isolation** — should `Service` carry `administrationId` or be global
   per OR? Resolved in design.md (proposal: administrationId per request from Nextcloud
   multi-app context).
3. **Service grouping hierarchy** — flat category list or nested categories? Resolved in
   `opsx-ff` discovery (proposal: flat for T1, nested deferred to T2).
