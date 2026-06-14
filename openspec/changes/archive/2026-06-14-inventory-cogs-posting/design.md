# Design — Inventory Auto-post COGS + Inventory-asset GL Entries

## Context

16 of 22 surveyed competitors offer perpetual inventory GL posting
(ERPNext, Brightpearl, Cin7 Core, Fishbowl, inFlow, and others).
Shillinq's MKB bookkeeper persona currently must compute COGS
manually at period end — a known accuracy and time risk flagged in
the 2026-05-20 market intelligence gap report.

The change extends the `InventoryValuation` entity (from ADR-000)
with a lifecycle action that materialises a balanced `GLTransaction`
(T1 pattern) for each stock movement event. Per ADR-031, posting
is declarative: the lifecycle extension fires the GL action; no PHP
`CogsPostingService` is authored. Per ADR-022, administration-level
account mapping is held in a dedicated `InventoryGLConfig` register.

**Status: pr-created.** Implemented via `opsx-apply` cycle; see PR for shillinq issue #132.

## Goals

- Express the entire perpetual inventory posting surface as
  **declarative metadata** — lifecycle extension on
  `InventoryValuation` + `InventoryGLConfig` register + manifest
  entries — per ADR-031.
- Make the three posting events (sale/dispatch, goods receipt, count
  variance) traceable via `GLLine.subLedgerType: "inventory"` +
  `subLedgerRef: <InventoryValuation UUID>`.
- Keep the spec **MKB-bookkeeper-readable**: Dutch RGS account codes,
  Dutch seed values, double-entry pair spelled out for each event.
- Support the GR/IR clearing account pattern to avoid double-posting
  when AP invoices settle the goods receipt.

## Non-Goals

- No PHP `CogsPostingService`, `InventoryGLService`, or similar.
- No valuation-method calculation — that is `inventory-valuation-fifo-avg`.
- No VAT/BTW on cost postings — T3.
- No multi-currency revaluation — T5.
- No landed-cost allocation service — future change.
- No standard-cost manufacturing WIP — MKB out of scope.

## Decisions

### D1 — Posting is a lifecycle action on InventoryValuation, not a GL service

`InventoryValuation` represents the monetary state of on-hand stock.
When its quantity changes (goods receipt, sale, count correction), the
corresponding GL impact is a deterministic function of the delta ×
unitCost. This is a pure state-machine transition — the ideal case for
`x-openregister-lifecycle`.

**Alternative considered**: A PHP `CogsPostingService` that listens to
stock events and emits GL entries. Rejected per ADR-031 anti-pattern
enumeration — "a service that maps A to B with no branching" is a
declarative calculation.

### D2 — A dedicated InventoryGLConfig register holds account mapping

Per ADR-022, account mapping per administration is configuration, not
hardcoded logic. `InventoryGLConfig` carries one record per
administration holding: `cogsAccountNumber`, `inventoryAssetAccountNumber`,
`grIrClearingAccountNumber`, `inventoryAdjustmentAccountNumber`.
The lifecycle action reads this at post time.

**Alternative considered**: Hardcode default RGS account codes (1400,
7000, etc.) into the lifecycle spec. Rejected — administrations using
non-RGS charts (e.g. GBAF, custom SMB charts) would need overrides.
A per-administration config record is flexible and uses the existing
`Account` FK contract.

### D3 — GR/IR two-step posting to avoid double-posting with AP

On goods receipt:
- Dr `inventoryAssetAccountNumber` (1400)
- Cr `grIrClearingAccountNumber` (1800)

Later, when the AP invoice is posted (T2 `bookkeeping-accounts-payable-core`):
- Dr `grIrClearingAccountNumber` (1800)
- Cr AP Control Account (crediteuren)

This two-step pattern (identical to ERPNext + SAP MM) ensures the
total P&L impact is: Dr Inventory Asset, Cr Crediteuren — correct
double-entry, no duplication.

**Alternative considered**: Post directly to AP Control on GR. Rejected —
the GR lifecycle fires before the AP invoice exists; posting to AP
Control without a linked AP invoice would create an unmatched AP line
that AP aging would flag as overdue.

### D4 — Count-variance direction determined by sign of (actual − book) quantity

A positive variance (more stock than book): Dr Inventory Asset, Cr
Inventory Adjustment. A negative variance (less stock than book): Dr
Inventory Adjustment, Cr Inventory Asset.

The direction MUST be determined declaratively from
`InventoryValuation.quantity` vs. the prior snapshot. If the lifecycle
engine cannot evaluate directional conditionals inline, the ADR-031
exception path applies: a single-method
`OCA\Shillinq\Lifecycle\InventoryPostingGuard::direction(int $delta): string`
(returns `"debit"` or `"credit"` for the Inventory Asset side).

### D5 — InventoryValuation carries glTransactionId back-reference

After the lifecycle action fires, the materialised `GLTransaction.id`
is written back to `InventoryValuation.glTransactionId`. This closes
the drill-down loop: from the inventory line, the operator can
navigate directly to the GL transaction and vice versa. Same pattern
as `APInvoice.glTransactionId` (T2 AP core).

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| GL posting entry point | T1 `GLTransaction` + `GLLine` materialisation (REQ-JE-007) | Lifecycle action emits one balanced `GLTransaction`; same pattern as AP + AR core |
| Account lookup per administration | `Account` register (T1 chart-of-accounts) + `InventoryGLConfig` | Config register holds FK account numbers; lifecycle resolves at post time |
| Sub-ledger traceability | `GLLine.subLedgerType` + `subLedgerRef` (T1 REQ-GL-009 stub) | `subLedgerType: "inventory"` + `subLedgerRef: <InventoryValuation UUID>` |
| Unit cost source | `InventoryValuation.unitCost` (from `inventory-valuation-fifo-avg`) | Lifecycle reads `unitCost`; no duplication of valuation logic |
| Stock movement trigger | `InventoryValuation` quantity-change events (`GoodsReceipt`, `SaleDispatch`, `CountAdjustment`) | Lifecycle extension on `InventoryValuation` per ADR-031 path 1 |
| Directional variance posting | Lifecycle engine conditional OR `InventoryPostingGuard::direction()` | ADR-031 exception path if conditional inline not expressible |
| Audit trail | T2 `bookkeeping-audit-trail` (OR audit-trail-immutable) | Automatic on lifecycle transitions |
| Manifest navigation | T1 manifest pattern per ADR-024 | 2 entries (Inventory Posting Config, Inventory Posting History) + pages |

**Net new code in implementation cycle**: 1 schema declaration
(`InventoryGLConfig`) + lifecycle action extension on
`InventoryValuation` + 1 enum addition (`subLedgerType: "inventory"`)
+ 2 manifest entry pairs. At most 1 single-method PHP guard
(`InventoryPostingGuard::direction()`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| COGS posting on sale | Declarative (`x-openregister-lifecycle` action) | Pure state → GL entry function; no branching beyond account lookup |
| Inventory asset posting on receipt | Declarative (`x-openregister-lifecycle` action) | Same |
| Count-variance posting | Declarative if engine supports sign-conditional direction; else single-method `InventoryPostingGuard::direction()` | ADR-031 exception path; cited in spec |
| Account resolution | Declarative (config register FK lookup at action time) | Pure FK resolution |
| Unit cost source | Read from `InventoryValuation.unitCost` provided by `inventory-valuation-fifo-avg` | No duplication |
| Audit trail | Automatic via OR audit-trail-immutable | ADR-022 |
| Manifest navigation | Declarative (`src/manifest.json`) | ADR-024 Tier 4 |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `InventoryPostingGuard::direction()`).

## Seed Data

Seed data for the `InventoryGLConfig` register (per administration):

| administrationId | cogsAccountNumber | inventoryAssetAccountNumber | grIrClearingAccountNumber | inventoryAdjustmentAccountNumber | description |
|---|---|---|---|---|---|
| adm-demo-1 | 7000 | 1400 | 1800 | 7100 | Shillinq demo administratie 2026 |
| adm-demo-2 | 7001 | 1401 | 1801 | 7101 | Horecabedrijf De Tulp BV 2026 |

Seed data for illustrative `InventoryValuation` records (3 examples):

| sku | warehouse | quantity | unitCost | totalValue | valuationMethod | date | status |
|---|---|---|---|---|---|---|---|
| KZA-001 | Magazijn Amsterdam | 15 | 45,00 | 675,00 | fifo | 2026-05-01 | active |
| MSC-002 | Magazijn Amsterdam | 40 | 12,50 | 500,00 | average | 2026-05-01 | active |
| KBN-003 | Magazijn Rotterdam | 120 | 8,75 | 1.050,00 | fifo | 2026-05-01 | active |

Seed data for illustrative materialised `GLTransaction` records from inventory postings (3 examples):

| entryNumber | description | journalCode | subLedgerType | debitAccount | creditAccount | amount |
|---|---|---|---|---|---|---|
| INV-GL-2026-0001 | COGS verkoop 5× KZA-001 | inkoop | inventory | 7000 Kostprijs omzet | 1400 Voorraden | 225,00 |
| INV-GL-2026-0002 | Ontvangst 20× MSC-002 van leverancier | inkoop | inventory | 1400 Voorraden | 1800 GR/IR clearing | 250,00 |
| INV-GL-2026-0003 | Telkorting voorraadopname KBN-003 | memo | inventory | 7100 Voorraadmutaties | 1400 Voorraden | 87,50 |

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| `inventory-valuation-fifo-avg` not yet merged | Lifecycle action checks `unitCost != null`; skips with structured warning if absent — no zero-cost GL entry |
| Conditional sign direction on count variance | Single-method `InventoryPostingGuard::direction()` per ADR-031 exception if engine cannot handle inline |
| Double-posting if GR fires before AP invoice | GR credits GR/IR clearing (1800), not AP Control; AP invoice subsequently moves GR/IR → AP Control; net result correct |
| Chart-of-accounts mismatch | `InventoryGLConfig` validates that all four account numbers resolve to an existing `Account` record in the administration; lifecycle action fails cleanly if any is absent |
| Reverse of a COGS GL entry | T1 REQ-GL-004 reversal pattern applies; `InventoryValuation` has a `returnDispatch` event that triggers the reverse `GLTransaction` |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the
   `InventoryGLConfig` schema + lifecycle extension on
   `InventoryValuation` (additive — no existing schema breakage).
2. `GLLine` `subLedgerType` enum gains the `"inventory"` value
   (additive — existing `ap`, `ar`, `project`, `none` values
   unchanged).
3. `src/manifest.json` gains 2 new menu entries + pages (additive).
4. `InventoryGLConfig` records are seeded per the seed-data table
   above for demo administrations.
5. If `InventoryPostingGuard::direction()` is required,
   `lib/Lifecycle/InventoryPostingGuard.php` ships (~20 LOC,
   ADR-031 exception annotated).

Down-direction: revert implementing PR; `InventoryGLConfig` records
and materialised `GLTransaction` rows with `subLedgerType: inventory`
remain but are unreferenced. No orphan FK violations — OR tolerates
dangling `subLedgerRef` values per T1 REQ-GL-009 design.

## Open Questions

1. **GR trigger timing** — `GoodsReceipt` lifecycle confirmation vs.
   `InventoryValuation` quantity-increase event. Resolved during
   implementing cycle; spec treats `InventoryValuation` as the
   canonical trigger.
2. **Default RGS seed accounts** — include Dutch RGS defaults (1400,
   7000, 1800, 7100) in `InventoryGLConfig` seed? Yes — documented
   in seed-data section above; simplifies first-time setup for the
   NL MKB segment.
3. **Return/reverse event name** — `returnDispatch` vs.
   `saleReturn`; resolved during implementing cycle's UX review.
