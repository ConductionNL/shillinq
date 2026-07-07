# Tasks: consolidate-order-subsidie-collisions

> Prerequisite for `abstract-order-primitive` (which is blocked until this lands). Non-destructive first: schema union + rename, then gated migration, then reference re-point.

## 1. Canonical Subsidie (union merge)

- [ ] 1.1 Deep-merge every `Subsidie` definition (`shillinq_register.json` rich-Dutch, `add-shillinq-bookkeeping-operations.json` English/simple, `add-shillinq-audit-trail.json` stub, `bookkeeping-subsidie-verantwoording.json` fields) into ONE canonical `Subsidie` in `shillinq_register.json` — the UNION of all properties, every regulatory field preserved; richer definition wins on same-name conflicts, mapping recorded. Bump the register `info.version`. Edit tool + re-parse JSON.
  - **spec_ref**: `specs/schema-consolidation/spec.md#requirement-subsidie-is-a-single-canonical-schema-that-drops-no-regulatory-field`
  - **acceptance_criteria**:
    - One `Subsidie`, union of fields, no regulatory field dropped; a schema-diff test proves union coverage
- [ ] 1.2 Retire the duplicate `Subsidie` blocks in the other fragments (leave one canonical). Verify no fragment still defines `Subsidie`.
  - **spec_ref**: `specs/schema-consolidation/spec.md#requirement-subsidie-is-a-single-canonical-schema-that-drops-no-regulatory-field`
  - **acceptance_criteria**:
    - `grep -rn '"Subsidie"' lib/Settings` shows one schema definition + references only

## 2. Free the Order slug

- [ ] 2.1 Rename the booking-context `Order` schema in `bookings-deposit-to-invoice.json` to a namespaced slug (`BookingOrder`), updating its `slug` + internal `$ref`s. Keep `SalesOrder`/`PurchaseOrder` distinct. Document the order-family slug map (`Order` reserved for the abstract primitive) in the change design.
  - **spec_ref**: `specs/schema-consolidation/spec.md#requirement-the-generic-order-slug-is-freed-for-the-abstract-primitive`
  - **acceptance_criteria**:
    - No schema occupies `lower(slug)=order` after the rename; SalesOrder/PurchaseOrder unchanged

## 3. Migration + reference re-point

- [ ] 3.1 Add a repair step migrating objects of retired Subsidie duplicates + the renamed booking `Order` to the canonical/renamed schema, field-mapped, with a source→target count check that ABORTS on mismatch (no row drop). Re-point every `$ref`/`referenceType`/relation naming a retired/renamed schema.
  - **spec_ref**: `specs/schema-consolidation/spec.md#requirement-existing-data-is-migrated-with-no-row-loss-and-references-re-pointed`
  - **acceptance_criteria**:
    - All objects migrated; references resolve; count-mismatch aborts with source intact; unit tests cover map + abort

## 4. Unblock + verify

- [ ] 4.1 Update `abstract-order-primitive` to note this consolidation is its prerequisite (it proceeds once this lands). `openspec validate consolidate-order-subsidie-collisions --strict` clean; register imports cleanly; the schema-diff + migration tests green.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + tests green; abstract-order-primitive no longer blocked on slug/triplication
