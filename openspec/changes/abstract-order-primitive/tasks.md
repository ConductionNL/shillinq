# Tasks — abstract-order-primitive

## Phase 1 — Schema (non-destructive)
- [x] Add `Order` schema (register.d/order-primitive.json) with orderType + direction
      discriminators, shared core, and subsidie/purchase/engagement field groups.
- [x] Add type-aware `x-openregister-lifecycle` transitions per orderType.

## Phase 2 — Migration + guards
- [x] Repair step `MigrateSubsidiePurchaseOrderToOrder` (lossless, idempotent).
- [x] `occ shillinq:orders:audit` count-equality check before/after.

## Phase 3 — UI + nav
- [x] Order index (filter by orderType) + type-aware detail; retire Subsidie/PO/DBA pages.
- [x] menu-layout: remove the 6 subsidie + PO + DBA entries → one Order workspace.

## Phase 4 — Compliance + cleanup
- [x] Re-point subsidie/PO compliance providers to Order (preserve rule ids).
- [x] Retire the duplicate Subsidie schema (add-shillinq-bookkeeping-operations.json).
