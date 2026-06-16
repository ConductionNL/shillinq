# Proposal: inventory-cogs-posting

`kind: config` per ADR-032 — the centre of mass is declarative
lifecycle extensions (`x-openregister-lifecycle` actions on
`InventoryValuation` state transitions) + account-mapping
configuration + manifest entries. No PHP GL-posting service is
authored (ADR-031; at most one single-method `InventoryPostingGuard`
if the lifecycle engine cannot express conditional account resolution).

## Summary

Introduce **perpetual inventory GL posting** for Shillinq: auto-post
COGS on sale, inventory-asset on goods receipt, and variance
adjustment on count correction. This is the ERPNext "Perpetual
Inventory" pattern adapted to the Shillinq GL (T1) and the upcoming
`inventory-valuation-fifo-avg` change that provides the unit-cost
source.

Every stock movement that changes inventory value MUST produce a
balanced `GLTransaction` per the T1 REQ-JE-007 materialisation
pattern — no manual journal for routine stock events.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:**
[`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(T1 `GLTransaction` + `GLLine` pattern),
`inventory-valuation-fifo-avg` (provides `InventoryValuation.unitCost`
used as the GL posting amount per movement).

## Motivation

16 of 22 surveyed competitors offer perpetual inventory with native GL
posting (ERPNext, Brightpearl, Cin7 Core, Fishbowl, inFlow, and
others). Manual COGS journalling is the pain point that drives
mid-market Dutch retailers and wholesalers toward ERPNext or
Brightpearl. Without auto-posting, Shillinq's bookkeeper persona must
hand-calculate COGS at period end — a known accuracy and time risk.

The P0-must priority and 16/22 competitor penetration make this a
blocking gap for the MKB segment.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`inventory-cogs-posting`); extends `InventoryValuation` with a
  lifecycle that triggers GL posting; declares the
  `InventoryGLConfig` register for account mapping; adds 2 manifest
  entries (Inventory Posting Config, Inventory Posting History).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-calculations`. If the
  lifecycle engine cannot express conditional account resolution
  declaratively, ADR-031's exception path applies (one
  single-method `InventoryPostingGuard`).
- [ ] Project: shillinq (T1 GL) — no destructive changes; adds
  `GLLine.subLedgerType: "inventory"` as a new enum value per REQ-GL-009
  stub, backed by `InventoryValuation` FK.

## Scope

### In Scope

- One new capability spec (`inventory-cogs-posting`) — see `specs/` folder.
- The `InventoryGLConfig` register mapping stock-movement event types
  to GL accounts (COGS, Inventory Asset, GR/IR clearing, Inventory
  Adjustment) per administration.
- Lifecycle action on `InventoryValuation` for three posting events:
  1. **Sale/Dispatch** — Debit COGS account, Credit Inventory Asset account.
  2. **Goods Receipt** — Debit Inventory Asset account, Credit GR/IR clearing account.
  3. **Count Variance** — Debit/Credit Inventory Adjustment account,
     Credit/Debit Inventory Asset account.
- Each posting materialises exactly one balanced `GLTransaction`
  (header) + 2 `GLLine` rows (debit + credit) per the T1 REQ-JE-007
  pattern.
- `GLLine.subLedgerType: "inventory"` + `subLedgerRef:
  <InventoryValuation UUID>` for traceability.
- Manifest navigation: Inventory Posting Config (index + detail on
  `InventoryGLConfig`), Inventory Posting History (index on
  materialised `GLTransaction` filtered by `subLedgerType: inventory`).

### Out of Scope

- **Valuation method calculation** (FIFO, weighted-average) — owned
  by `inventory-valuation-fifo-avg`. This change reads the already-
  computed `InventoryValuation.unitCost`.
- **VAT/BTW on cost postings** — T3 per ADR-001 roadmap.
- **Multi-currency inventory** — T5.
- **Landed cost allocation** — future change; this spec posts at
  landed-cost-inclusive unit cost when `inventory-valuation-fifo-avg`
  provides it.
- **Standard-cost variance (manufacturing WIP)** — MKB out of scope.
- **Implementation code** — spec-only change. PHP + Vue implementation
  lands via a separate `opsx-apply` cycle.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-cogs-posting`** — declares `InventoryGLConfig`, the
lifecycle extension on `InventoryValuation` for the three posting
events, the GL materialisation (balanced `GLTransaction` per T1
REQ-JE-007), and the manifest navigation.

Requirements use the `REQ-CG-NNN` prefix for traceability. Each
requirement follows RFC 2119 keywords and includes `#### Scenario:`
blocks with GIVEN/WHEN/THEN.

## New Dependencies

None beyond the listed `depends_on` entries. Consumes existing
OpenRegister abstractions and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds the `InventoryGLConfig`
  schema; extends `InventoryValuation` with a lifecycle posting action.
  Additive — no existing schema changes.
- `src/manifest.json` — adds 2 navigation entries (Inventory Posting
  Config, Inventory Posting History) + their index/detail pages.
- No new PHP services (subject to ADR-031 exception: one single-method
  `InventoryPostingGuard` if the lifecycle engine cannot express
  conditional account resolution).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **T1 general ledger** — depends on `add-shillinq-general-ledger` for
  the `GLTransaction` + `GLLine` materialisation pattern. The T1
  `GLLine.subLedgerType` enum gains an `"inventory"` value.
- **inventory-valuation-fifo-avg** — depends on this sibling change for
  `InventoryValuation.unitCost` (the monetary amount posted to GL).
- **OpenRegister** — consumes `x-openregister-lifecycle` (ADR-031) for
  declarative posting triggers; if conditional account resolution is
  not expressible declaratively, ADR-031 exception path applies.

## Risks

### Risk 1: InventoryValuation lifecycle coupling to GL

**Severity**: Medium
**Mitigation**: The `InventoryValuation` lifecycle action fires
conditionally — only when `InventoryGLConfig` is configured for the
administration. Administrations that have not configured posting
accounts will skip the GL action without error, preserving backward
compatibility.

### Risk 2: Conditional debit/credit direction on count variance

**Severity**: Low-Medium
**Mitigation**: The sign of a count-variance posting depends on
whether the variance is positive (stock increase: Dr Inventory Asset,
Cr Inventory Adjustment) or negative (stock decrease: Dr Inventory
Adjustment, Cr Inventory Asset). If the lifecycle engine cannot
express directional conditionals declaratively, the ADR-031 exception
path applies: a single-method `InventoryPostingGuard::direction(...)`.

### Risk 3: Double-posting if GoodsReceipt fires before AP invoice matching

**Severity**: Medium
**Mitigation**: The GR posting debits Inventory Asset and credits the
GR/IR clearing account (not the AP control account). The AP invoice
(`APInvoice.post`) subsequently debits GR/IR clearing and credits the
AP control account, netting to the correct double-entry pair. This
two-step approach (identical to ERPNext + SAP practice) avoids
double-posting. The spec explicitly documents the clearing account
coupling with the AP core change.

### Risk 4: inventory-valuation-fifo-avg not yet merged

**Severity**: Medium
**Mitigation**: The lifecycle action checks for the presence of a
non-null `InventoryValuation.unitCost`. If absent (because the
valuation change has not yet landed), the posting action is skipped
with a structured warning event. No GL entry is made with a zero
cost, which would produce silent misstated financials.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
`InventoryGLConfig` records and any materialised `GLTransaction`
entries with `subLedgerType: inventory` remain queryable but
unreferenced; no orphan FK violations.

## Open Questions

1. **Lifecycle engine conditional direction** — can the
   `x-openregister-lifecycle` engine evaluate `variance > 0` /
   `variance < 0` inline? Resolved in `opsx-ff` discovery; spec
   shape-neutral pending the answer.
2. **GoodsReceipt vs InventoryValuation trigger point** — does the
   posting fire on `GoodsReceipt` lifecycle confirmation, or on
   `InventoryValuation` updated-quantity event? To be resolved
   during the implementing cycle; this spec treats
   `InventoryValuation` as the trigger for uniformity.
3. **Default account mappings for NL RGS** — should the spec ship
   default Dutch RGS account codes (1400 Voorraden, 7000 Kostprijs
   omzet, 1800 GR/IR) in the `InventoryGLConfig` seed? Resolved
   during design review; default mappings in seed data simplify
   first-time setup.
