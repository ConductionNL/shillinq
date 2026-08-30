---
kind: code
---

# Proposal: consolidate-order-subsidie-collisions

## Why

The `abstract-order-primitive` change — the flagship that folds Subsidie + PurchaseOrder +
DBA-engagement into one `Order` primitive with a `type` discriminator — is **blocked**, and
its own proposal records why: a prior author→verify attempt was **rejected** because two
schema-collision problems must be fixed *first*:

1. **`Order` slug collision.** `lib/Settings/register.d/bookings-deposit-to-invoice.json`
   already ships a schema with `"slug": "Order"` (the deposit/booking order). Under
   OpenRegister's global `lower(slug)` resolution, a second `Order` (the abstract primitive)
   cannot be added — it silently no-ops the import or collides. Sibling order-family schemas
   also exist under distinct slugs (`SalesOrder` in `bookkeeping-quote-order-invoice.json`,
   `PurchaseOrder` in the 3-way-match cluster), so "Order" as a generic primitive slug is
   contested.
2. **`Subsidie` is triplicated** with *different* field sets across `shillinq_register.json`
   (the rich Dutch regulatory version), `add-shillinq-bookkeeping-operations.json` (an
   English/simple version), and `add-shillinq-audit-trail.json` (an empty audit stub) — plus
   further Subsidie references in `bookkeeping-subsidie-verantwoording.json`. Three (or more)
   definitions of the same concept means the deep-merge union import is ambiguous and no
   single schema owns the regulatory fields.

This change is the **prerequisite** that unblocks `abstract-order-primitive`: it consolidates
each collision set into one canonical schema — **dropping no regulatory field** (per the
union-merge-no-regression rule) — and retires the duplicates, so the abstract Order primitive
can then be added cleanly. It does NOT itself build the abstract Order (that stays in
`abstract-order-primitive`, which this unblocks).

## What Changes

- **Subsidie → one canonical schema.** Deep-merge the field sets of every `Subsidie`
  definition (`shillinq_register.json` rich-Dutch, `add-shillinq-bookkeeping-operations.json`
  English/simple, the `add-shillinq-audit-trail.json` stub, and the
  `bookkeeping-subsidie-verantwoording.json` fields) into a single canonical `Subsidie`
  schema in `shillinq_register.json` — the UNION of all properties, every regulatory field
  (regeling/beschikking/vaststelling, the five state-amounts, prestatie-verantwoording,
  auditor-threshold + repayment, etc.) preserved; retire the duplicate `Subsidie` blocks in
  the other fragments. Where two sources define the same property with different shapes, keep
  the richer/more-constrained one and record the mapping.
- **Order slug collision → freed.** Rename the booking-context `Order` schema in
  `bookings-deposit-to-invoice.json` to a namespaced slug (e.g. `BookingOrder` /
  `DepositOrder`, following the slug-collision namespacing rule) so the generic `Order` slug
  is available for the abstract primitive; keep `SalesOrder` and `PurchaseOrder` as the
  distinct order-family schemas they are. Document the canonical order-family slug map
  (`Order` reserved for the abstract primitive, `SalesOrder`, `PurchaseOrder`,
  `BookingOrder`) so `abstract-order-primitive` can add `Order` without collision.
- **Data migration (repair step).** A repair step migrates existing objects of the retired
  Subsidie duplicates + the renamed booking `Order` to their canonical/renamed schema,
  field-mapped, verified by a source→target count with an abort-on-mismatch guard (never
  drop rows). Re-point every `$ref`/`referenceType`/relation that named a retired schema.
- Explicitly the prerequisite for `abstract-order-primitive` — this change references it and
  is ordered before it; `abstract-order-primitive` proceeds once this lands.

## Impact

- Affected: `lib/Settings/shillinq_register.json` (canonical Subsidie), the register.d
  fragments carrying duplicate Subsidie / the booking `Order`, a repair-step migration, and
  every reference to a retired/renamed schema. No regulatory field dropped.
- Unblocks the blocked `abstract-order-primitive` change (and the Invoice/Journal/Project
  merges chained behind it).
- Out of scope: the abstract `Order` primitive itself + its `type`/`direction` discriminator
  and type-aware lifecycle (owned by `abstract-order-primitive`, which this enables).
