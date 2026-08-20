# Spec: bookkeeping-ifrs15-revenue (delta — contracts-single-home)

This delta MODIFIES REQ-IFRS15-001 only. REQ-IFRS15-002 through
REQ-IFRS15-011 are untouched in normative behaviour — the revenue-recognition
model, allocation methods, and disclosure logic are unaffected — but every
one of them references "Contract" in prose or in an FK-field description
(`contractId` → "FK to the parent Contract" / "FK to the Contract",
`ContractModification.parentContractId` → "FK to the Contract being
modified") where it means the IFRS-15 revenue contract; those are mechanical
renames tracked in `contracts-single-home`'s tasks.md, not re-specified here.

## Why this requirement is being replaced, not just updated

REQ-IFRS15-001 names `Contract` as one of ten registers this capability
declares. That schema slug also happens to be declared, independently, as a
**full, different** schema by `contract-lifecycle-management.json`
(REQ-CLM-001, a separate capability). `SettingsService::deepMergeConfig()`
unions both into one merged schema at import time — confirmed by reading the
merge algorithm (list arrays concatenate, keyed objects recurse) and by the
merged `required` array literally demanding fields from both domains at
once. `contracts-single-home` renames the IFRS-15 schema in this capability
to `RevenueContract`, ending the collision; `contract-lifecycle-management`'s
`Contract` is unaffected and becomes the fleet's canonical ADR-051
`ns#Contract` implementer.

## MODIFIED Requirements

### Requirement: REQ-IFRS15-001 — Five-step revenue recognition model SHALL be implemented as ten core registers with explicit revenue contract, PO, transaction-price, and allocation structure

IFRS 15 revenue recognition SHALL be expressed as ten registers per ADR-024,
none of which share a schema slug with a register declared by another
capability:

- `RevenueContract` — customer contract with identification, dates,
  transaction price (fixed + variable), currency, signed-at date,
  modifications history. Named `RevenueContract` (not the bare `Contract`)
  specifically to avoid colliding with `contract-lifecycle-management`'s
  generic `Contract` schema, which is the fleet's canonical ADR-051
  `ns#Contract` implementer; the two are deliberately separate registers for
  separate bounded contexts (revenue recognition vs. generic contract
  lifecycle) and MUST NOT be merged, aliased, or share a slug.
- `PerformanceObligation` — distinct good or service within a
  `RevenueContract`, with satisfaction pattern (point-in-time | over-time)
  and method (output units, milestones, time-elapsed, cost-to-cost,
  labour-hours).
- `TransactionPrice` — decomposed price with fixed, variable, financing
  adjustment, non-cash consideration, consideration payable to customer.
- `PriceAllocation` — per-PO allocated amount using relative SSP
  (IFRS 15.74) or residual method (IFRS 15.79).
- `RevenueRecognitionEvent` — evidence that a PO moved toward completion
  (units delivered, % complete via input method, milestone achieved, etc.).
- `ContractAsset` — derived nightly; right to consideration when
  recognised > billed.
- `ContractLiability` — derived nightly; deferred revenue when
  billed > recognised.
- `ContractModification` — amendments to a `RevenueContract` classified per
  IFRS 15.18-21 (new contract, cumulative catch-up, prospective).
- `VariableConsiderationAdjustment` — rebates, volume discounts, performance
  bonuses, refund obligations, with periodic re-estimation and constraint.
- `ContractCostAsset` — incremental costs to obtain or fulfil a
  `RevenueContract` (sales commission, setup labor), capitalised and
  amortised per IFRS 15.91-104.
- `RevenueWaterfall` — per-`RevenueContract` time-series aggregation of
  transaction price, recognition by period, remaining amount, for 60+ months
  (IFRS 15.120 disclosure).

`RevenueContract`s and POs MUST NOT be embedded in GL transactions or
sub-ledger invoice rows; they are first-class entities with their own
lifecycle, modification history, and audit trail. Posting a
`RevenueRecognitionEvent` MUST materialise exactly one balanced
`GLTransaction` per the T1 pattern per REQ-IFRS15-007.

#### Scenario: Schema validator accepts a simple one-PO revenue contract

- **GIVEN** the schema
- **WHEN** a draft `RevenueContract` with one point-in-time PO
  (implementation service, completed on-site) is saved
- **THEN** validation MUST pass using only `RevenueContract`'s own
  `required` fields (`contractNumber`, `customerId`, `signedAt`,
  `startDate`, `fixedConsideration`, `currency`, `lifecycleState`,
  `administrationId`) — no field from `contract-lifecycle-management`'s
  `Contract` schema (e.g. `contractType`, `status`) MUST be required or
  present, confirming the two schemas no longer merge
- AND `RevenueRecognitionEvent` entries can be added at the PO-completion
  date

#### Scenario: Contract modification is recorded separately, not as inline edit

- **GIVEN** a signed `RevenueContract` C-2026-001
- **WHEN** a scope change is recorded via `ContractModification` with type =
  "new-distinct-scope" (per IFRS 15.20(a))
- **THEN** the original `RevenueContract`'s POs remain unmodified; a new
  `RevenueContract` is created with its own POs and allocation

#### Scenario: RevenueContract does not collide with the generic Contract schema

- **GIVEN** both `contract-lifecycle-management` and `bookkeeping-ifrs15-
  revenue` are imported
- **WHEN** `components.schemas` is inspected for the merged register
- **THEN** exactly one full schema definition exists for `Contract` (the
  generic CLM record) and exactly one full schema definition exists for
  `RevenueContract` (this capability's record); neither `required` list
  contains a field the other schema's UI form does not collect

@e2e exclude pure backend/compliance: IFRS 15 revenue recognition — not
browser-testable at the requirement's own level; route-mount rendering after
the rename is covered by `contracts-single-home`'s own e2e spec, not
duplicated here
