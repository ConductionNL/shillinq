# Proposal: inventory-lot-batch-expiry

`kind: config` per ADR-032 — the centre of mass is declarative schema
declarations (`InventoryLot`, `ExpiryAlert` registers, lifecycle rules,
FEFO sort annotation). No PHP service classes are authored unless Risk 1
confirms the FEFO sort constraint cannot run inside the declarative engine
(see Risk 1 below — ADR-031 exception path applies in that case, ≤20 LOC).

## Summary

Introduce **inventory lot/batch tracking with First-Expiry-First-Out (FEFO)
picking and expiry alerting** for Shillinq. This is a P0-must capability
with evidence across 13 of 22 surveyed competitors and is **critical for
pet food and perishables** customers.

This change declares two new registers — `InventoryLot` and `ExpiryAlert`
— in `lib/Settings/shillinq_register.json`, declares `x-openregister-lifecycle`
lot-state transitions (active → quarantined → expired → exhausted), adds
`x-openregister-sort` for FEFO order, patches `InventoryItem` with an
additive `requiresLotTracking` boolean field, wires manifest navigation,
and ships seed data for 5 Dutch pet-food lot examples.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** [`inventory-stock-movement-ledger`](../inventory-stock-movement-ledger/proposal.md).
`InventoryLot.stockMovements` FKs into the `StockMovement` register declared
in that change.

## Motivation

13 of 22 surveyed WMS/ERP competitors implement lot/batch tracking with
expiry dates: Blue Yonder, Cin7, ERPNext, Fishbowl, Inflow, Manhattan,
NetSuite, Odoo, Sage Intacct, SAP EWM, Sortly, Tryton, and Zoho. The
demand score (13/22) is the highest recorded in this triage cycle, making
this a competitive must-have.

Without lot tracking, Shillinq cannot serve:
- Pet food distributors (regulatory shelf-life requirements)
- Pharmaceutical wholesalers (batch recall obligations)
- Food & beverage producers (HACCP compliance, FEFO picking)
- Fresh-produce and dairy handlers (FEFO mandatory)

FEFO picking — serving lots with the earliest expiry dates first — prevents
spoilage write-offs and regulatory violations. Expiry alerting ensures
operators dispose of or discount stock before it crosses the expiry
threshold.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas (`InventoryLot`,
  `ExpiryAlert`) to `lib/Settings/shillinq_register.json`; patches
  `InventoryItem` with 1 additive field (`requiresLotTracking`); adds
  2 manifest navigation entries (`Inventory > Lots & Batches` index +
  detail) in `src/manifest.json`.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (`x-openregister-lifecycle`, `x-openregister-sort`,
  `x-openregister-relations`, audit-trail-immutable, RBAC). If the
  FEFO sort directive cannot be enforced at the API layer (see Risk 1),
  the gap is filed as an OR issue and a thin ≤20 LOC PHP guard is
  registered per ADR-031 exception path.

## Scope

### In Scope

- One new capability spec (`inventory-lot-batch-expiry`) — see the
  `specs/` folder.
- `InventoryLot` register: `lotNumber`, `batchCode`, `manufactureDate`,
  `expiryDate`, `bestBeforeDate`, `quantity`, `unitCode`, `unitCost`,
  `warehouseLocation`, `lotStatus`, `receivedDate`, `notes`, FK to
  `InventoryItem` (via `productSku`), FK to `GoodsReceipt` (via
  `goodsReceiptId`), FK to `StockMovement` (one-to-many).
- `ExpiryAlert` register: `alertType`, `daysBeforeExpiry`, `alertDate`,
  `status`, `resolvedDate`, `notes`, FK to `InventoryLot` (via `lotId`),
  FK to `Person` (via `recipientId`).
- `x-openregister-lifecycle` on `InventoryLot` declaring states: `active`,
  `quarantined`, `expired`, `exhausted`; transitions with guards per
  REQ-LOT-005.
- FEFO sort declared as `x-openregister-sort: [{field: expiryDate, direction: asc}]`
  on the `InventoryLot` schema; NULL-last semantics for lots without
  an expiry date.
- Additive patch to `InventoryItem`: `requiresLotTracking` boolean
  (default: false). Existing InventoryItem objects remain valid.
- Manifest navigation entry (`Inventory > Lots & Batches`) with
  `type: index` page binding to `InventoryLot` and `type: detail` page
  showing lot header + movement history.
- Seed data: 5 example `InventoryLot` objects with Dutch pet-food
  product values (see `design.md`).
- Expiry alert threshold configuration: alerts generated N days before
  `expiryDate` (configurable per item category, default: 30 days).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes land via a separate
  `opsx-apply` cycle.
- **StockMovement register** — owned by the dependency
  `inventory-stock-movement-ledger`.
- **FEFO pick-list generation service** — a Tier 2 capability that
  reads sorted `InventoryLot` records; filed as a separate spec
  (`inventory-fefo-pick-list`).
- **Lot recall workflow (forward/backward trace)** — competitor Blue
  Yonder's "Lot Trace + Recall" is a separate `inventory-lot-recall`
  spec.
- **Serial number per-piece tracking** — distinct from lot/batch;
  separate spec.
- **Multi-UoM conversion on lots** — Tier 5 capability.
- **Frontend Vue components** beyond what `CnIndexPage`/`CnDetailPage`
  from `@conduction/nextcloud-vue` render generically from the manifest.
  No bespoke Vue files in this spec.

## Approach

One delta: declare `InventoryLot` and `ExpiryAlert` schemas with lifecycle
and FEFO sort, patch `InventoryItem`, add manifest navigation, seed 5
Dutch example lots.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-LOT-*` for
traceability.

## New Dependencies

- **`inventory-stock-movement-ledger`** must land first — `InventoryLot`
  FKs into the `StockMovement` register that change declares.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas (`InventoryLot`,
  `ExpiryAlert`); patches `InventoryItem` with `requiresLotTracking`.
- `src/manifest.json` — adds 1 navigation entry (`Inventory > Lots &
  Batches`) with `type: index` + `type: detail` pages.
- No new PHP services unless Risk 1 forces the ADR-031 exception path
  for FEFO sort enforcement.
- No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` (ADR-031),
  `x-openregister-sort`, `x-openregister-relations`, and
  audit-trail-immutable (ADR-022) being stable.

## Risks

### Risk 1: `x-openregister-sort` may not enforce FEFO at the API query layer

**Severity**: Medium  
**Mitigation**: `x-openregister-sort` is a schema-level hint. If the
hint is treated as advisory rather than enforced, FEFO order is applied
in a thin PHP guard (single method, ≤20 LOC) called on the lot-list
endpoint, per ADR-031 exception path. The spec is shape-neutral:
`REQ-LOT-005` mandates FEFO order without prescribing implementation.
Resolve in `opsx-ff` discovery before the implementing cycle begins.

### Risk 2: Expiry date absent for some received items

**Severity**: Low  
**Mitigation**: `expiryDate` is a non-required field on `InventoryLot`.
FEFO sort uses NULL-last semantics — lots without an expiry date sort
after lots with one. Operators are warned at receipt time (via an
`ExpiryAlert` of type `missing_expiry_date`) when `requiresLotTracking:
true` items arrive without an expiry date.

### Risk 3: InventoryItem schema patch could break existing objects

**Severity**: Low  
**Mitigation**: `requiresLotTracking` is additive (optional, default:
false). OpenRegister's schema versioning guarantees additive optional
fields are non-breaking for existing objects.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` runs. After implementation (separate cycle), rollback
follows the standard pattern: revert the implementing PR, run the repair
step in down-direction (registers are non-destructive — unused schemas
remain queryable but unreferenced). No data migration risk at the spec
stage.

## Open Questions

1. **`x-openregister-sort` enforcement** — see Risk 1. Resolved in the
   `opsx-ff` design phase before the implementing cycle begins.
2. **Expiry alert scheduling** — daily cron comparing `expiryDate - today`
   against configured thresholds. Does OR's job-queue abstraction support
   this without a custom `IJob`? If a Nextcloud `IJob` is needed, that
   classifies as `kind: code` and splits out as chain spec 2.
3. **Best-before vs expiry** — some regulations (e.g. EU food law)
   distinguish legal expiry date from best-before quality date. Both
   fields are declared on `InventoryLot`; the implementing cycle's UX
   review settles the label wording for Dutch operators.
