# Abstract Subsidie + PurchaseOrder + DBA into a single Order/Contract primitive

## Why
The menu-IA audit (2026-06) found ~23 bespoke pages that are special cases of a
few primitives. The owner chose the **aggressive** path: fold the distinct schemas
into their primitive behind a `type` discriminator. This change does the flagship —
collapsing the 6 Subsidie nav entries + Purchase Orders + DBA engagements into one
**Order** workspace — and is the template for the Invoice/Journal/Project merges
that follow.

## What changes
- Introduce a generic **`Order`** schema with an `orderType` discriminator
  (`purchase | sales | subsidie | engagement`) and a `direction` (`incoming | outgoing`).
- A shared core (number, counterparty, currency, amounts, period, state, lines) plus
  type-namespaced optional field groups so each type keeps its full semantics:
  - `subsidie`: regeling/beschikking/vaststelling, the five state-amounts, prestatie-
    verantwoording, auditor-threshold + repayment — **no regulatory field is dropped**.
  - `purchase`: supplier/3-way/peppol fields (folds PurchaseOrder).
  - `engagement`: DBA modelovereenkomst + risicoklasse.
- A **type-aware lifecycle**: each `orderType` keeps its own state vocabulary
  (subsidie: aanvraag→verleend→vastgesteld→uitbetaald→teruggevorderd→afgehandeld; etc.)
  enforced by `x-openregister-lifecycle` transitions gated on `orderType`.
- A **data migration** (repair step) converts existing Subsidie + PurchaseOrder rows
  to Order rows (type-tagged, fields preserved), and the duplicate Subsidie schema
  (add-shillinq-bookkeeping-operations.json) is retired.
- One **Order workspace** (index filterable by type + a type-aware detail) replaces
  the Subsidie/PO/DBA pages; the compliance providers that reference Subsidie/PO are
  re-pointed to Order.

## Phasing (non-destructive first)
1. **Schema** (this artifact set): add the `Order` schema alongside the existing ones
   — zero data risk.
2. **Migration + guards**: repair step + lifecycle, behind a verify/audit gate.
3. **UI + nav collapse**: Order workspace; retire Subsidie/PO pages + nav entries.
4. **Compliance re-point + retire duplicate Subsidie schema**.

## Impact
- Schemas: +Order; (later) −Subsidie ×2, −PurchaseOrder.
- Compliance: re-point subsidie/PO rule providers to Order (preserve rule ids).
- Nav: 6 Subsidie + PO + DBA entries → one Order workspace.

## BLOCKER (2026-06-22) — prerequisite schema-dedup required
A parallel build attempt (author→verify) was REJECTED by the verify stage and is not
shipped. Root causes discovered:
- **`Order` slug collision**: three `Order` schemas already exist — bookings-deposit-to-invoice
  (deposit/booking), bookkeeping-quote-order-invoice, and this change's draft. A unified Order
  primitive cannot be added without first consolidating these.
- **`Subsidie` is triplicated** with *different* field sets (shillinq_register.json Dutch/rich vs
  add-shillinq-bookkeeping-operations.json English/simple vs an empty audit-trail stub). The merge
  must map the deep-merged UNION, and the duplicates must be retired first.
- **Migrations are unverifiable locally** — 0 rows of Subsidie/PurchaseOrder/Order exist in the dev
  env, so a count-equality / no-field-loss check cannot run.
- The auto-generated repair steps dropped ~14 source fields, passed `currentUser` as a string
  (IUser TypeError), read under RBAC in a no-session repair context, and were unregistered.

**Required before resuming**: (1) consolidate the 3 Order + 3 Subsidie schemas into one canonical
each; (2) seed representative test data so migrations can be verified; (3) write the migration by
hand against the real merged field set with the IUser + `_rbac:false` fixes. Until then this change
stays in DESIGN state — the nav entries are NOT collapsed (removing them without the fold would
strand the pages).
