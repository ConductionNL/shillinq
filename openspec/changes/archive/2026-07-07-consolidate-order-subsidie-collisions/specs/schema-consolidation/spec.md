## ADDED Requirements

### Requirement: Subsidie is a single canonical schema that drops no regulatory field

There MUST be exactly one `Subsidie` schema. Its properties MUST be the UNION of every
current `Subsidie` definition (the rich-Dutch version in `shillinq_register.json`, the
English/simple version in `add-shillinq-bookkeeping-operations.json`, the empty audit stub
in `add-shillinq-audit-trail.json`, and any Subsidie fields in
`bookkeeping-subsidie-verantwoording.json`). No regulatory field may be lost in the merge —
regeling/beschikking/vaststelling references, the five subsidie state-amounts,
prestatie-verantwoording, the auditor threshold, and repayment MUST all survive on the
canonical schema. Where two sources define the same property with different shapes, the
richer/more-constrained definition MUST win and the mapping MUST be recorded. The duplicate
`Subsidie` blocks MUST be retired from the other fragments.

#### Scenario: The canonical Subsidie is the field union

- **WHEN** the registers import after this change
- **THEN** exactly one `Subsidie` schema MUST exist, carrying the union of all previously-defined Subsidie properties
- **AND** no regulatory field from any source definition MUST be absent
- **AND** no other fragment MUST still define a `Subsidie` schema

@e2e exclude schema-shape/union invariant is verified by a register-import + schema-diff test, not a UI flow.

### Requirement: The generic Order slug is freed for the abstract primitive

The generic `Order` slug MUST be freed for the abstract Order primitive by renaming the booking-context order schema currently occupying `"slug": "Order"` in `bookings-deposit-to-invoice.json` to a namespaced slug (e.g. `BookingOrder`), so that under OpenRegister's global `lower(slug)` resolution the generic `Order` slug becomes available for the abstract Order primitive introduced by `abstract-order-primitive`.
`SalesOrder` and `PurchaseOrder` MUST remain distinct schemas. The change MUST document the
canonical order-family slug map (`Order` reserved for the abstract primitive; `SalesOrder`;
`PurchaseOrder`; the renamed booking order) so the abstract primitive can be added without a
slug collision.

#### Scenario: Adding a generic Order no longer collides

- **GIVEN** this change has renamed the booking `Order` to a namespaced slug
- **WHEN** a generic `Order` schema (the abstract primitive) is subsequently imported
- **THEN** it MUST NOT collide with any existing slug under `lower(slug)` resolution
- **AND** `SalesOrder` and `PurchaseOrder` MUST remain as their own schemas

@e2e exclude slug-availability invariant verified by the register-import test after renaming.

### Requirement: Existing data is migrated with no row loss and references re-pointed

A repair step MUST migrate every object of a retired Subsidie duplicate and of the renamed
booking `Order` to its canonical/renamed schema, field-mapped, and MUST re-point every
`$ref`/`referenceType`/relation that named a retired or renamed schema. The migration MUST
verify a source→target object count and MUST abort (leaving the source intact) on a mismatch
— it MUST NOT drop rows.

#### Scenario: Migration preserves every object and aborts on mismatch

- **WHEN** the repair step runs
- **THEN** every object of a retired/renamed schema MUST exist under the canonical/renamed schema, field-mapped
- **AND** references to the old schemas MUST now resolve to the canonical/renamed ones
- **AND** if the source→target count does not match, the step MUST abort with the source data intact (no drop)

@e2e exclude migration count/abort behaviour is unit-tested on seeded objects; the full register migration is verified on a live import.
