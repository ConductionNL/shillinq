# Tasks — abstract-order-primitive

## Phase 1 — Schema (non-destructive)
- [x] Add `Order` schema (register.d/order-primitive.json) with orderType + direction
      discriminators, shared core, and subsidie/purchase/engagement field groups.
- [ ] Add type-aware `x-openregister-lifecycle` transitions per orderType.

## Phase 2 — Migration + guards
- [ ] Repair step `MigrateSubsidiePurchaseOrderToOrder` (lossless, idempotent).
- [ ] `occ shillinq:orders:audit` count-equality check before/after.

## Phase 3 — UI + nav
- [ ] Order index (filter by orderType) + type-aware detail; retire Subsidie/PO/DBA pages.
- [ ] menu-layout: remove the 6 subsidie + PO + DBA entries → one Order workspace.

## Phase 4 — Compliance + cleanup
- [ ] Re-point subsidie/PO compliance providers to Order (preserve rule ids).
- [ ] Retire the duplicate Subsidie schema (add-shillinq-bookkeeping-operations.json).
