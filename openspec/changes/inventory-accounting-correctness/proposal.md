---
kind: code
depends_on: []
---

# Change: inventory-accounting-correctness

## Why

Three inventory-VALUATION correctness gaps from the sweep all bear on the same thing — a
balance-sheet-correct inventory figure for the jaarrekening (Titel 9 BW2) — so they ship as one
coherent change. Each was verified against HEAD; only the real delta is built.

1. **No inventory valuation / ageing / turnover report.** The sweep found zero hits across 133
   specs. The jaarrekening needs `voorraadwaarde per 31-12` (stock value as-of a date), and the
   immutable `StockMove` ledger already keeps the running total — but nothing surfaces it at a
   historical cut-off. The sweep flagged this "needs FIFO layers persisted first". **VERIFIED
   FALSE against HEAD**: `FifoValuationService` derives FIFO cost lots on the fly from the ledger
   (each posted `receipt` StockMove IS a lot; `issue` rows consume oldest-first) — it never writes
   layer objects. Therefore value-as-of-date is computable TODAY by replaying every posted move
   with `postedAt <= asOfDate`; no new persistence is required. (Persisting explicit layer objects
   so OpenRegister aggregation could compute this natively remains an optional future optimisation,
   named below — not a blocker.)

2. **Landed costs not capitalised.** Freight + import duties are not allocated into unit cost,
   understating both COGS and balance-sheet inventory value (violates RJ 220.107 / IAS 2.11 — costs
   of purchase include transport, handling and duties directly attributable to acquisition).

3. **No lower-of-cost-or-NRV.** FIFO / moving-average alone cannot produce a compliant balance
   sheet: RJ 220.301 / IAS 2.9 require inventory at the LOWER of cost and net realisable value. No
   period-end write-down exists. (LIFO is deliberately NOT built — IAS 2.25 prohibits it.)

## What Changes

All three extend the existing valuation / COGS / stock-ledger service family
(`FifoValuationService`, `MovingAverageValuationService`, `CogsPosterService`, `StockLedgerService`)
and consume OpenRegister's ObjectService (ADR-022 — no app tables, no SQL). Every GL posting is
BALANCED by construction (`debitCents === creditCents`).

- **NEW `OCA\Shillinq\Service\InventoryValuationReportService`** (read-only, ADR-031 exception). Replays
  the `StockMove` ledger up to a cut-off date to compute stock value as-of-date — FIFO lot
  reconstruction and moving-average running-average replay — plus FIFO ageing buckets
  (0-30 / 31-60 / 61-90 / 90+ days) and inventory turnover (`COGS(window) / average inventory
  value` + days-on-hand). No new persistence.
- **NEW `OCA\Shillinq\Service\LandedCostAllocationService`** (ADR-031 exception). Allocates a receipt's
  total landed cost across its lines pro-rata by extended value (or quantity) using a
  largest-remainder distribution so allocated cents sum EXACTLY to the input, computes each line's
  landed unit cost, posts ONE balanced capitalisation (debit Inventory `1300` / credit
  landed-cost clearing `1305`), and bumps the driving `InventoryValuation` snapshot.
- **NEW `OCA\Shillinq\Service\NrvWriteDownService`** (ADR-031 exception). Applies lower-of-cost-or-NRV
  to an `InventoryValuation` snapshot: when `nrvPerUnit < unitCost`, posts a balanced write-down
  (debit write-down expense `7050` / credit Inventory `1300`) and re-marks the snapshot to NRV;
  when `nrvPerUnit >= unitCost` it is a strict no-op — never writing inventory up. A batch
  `runForAdministration()` drives it from an operator NRV-per-SKU map.
- **NEW `OCA\Shillinq\Service\InventoryGlAdjustmentPoster`** — shared balanced two-line `GLTransaction`
  poster used by landed-cost + NRV. Refuses to post an unbalanced or missing-account request.
- **NEW thin read/write controllers** (ADR-003, `#[NoAdminRequired]`, RBAC per `administrationId`
  via `AdministrationContextService`, masked 404, no IDOR per ADR-005):
  `InventoryValuationReportController` (`GET /api/inventory/valuation-report`) and
  `InventoryAdjustmentController` (`POST /api/inventory/landed-cost`, `POST /api/inventory/nrv-writedown`).
- **Routes** in `appinfo/routes.php` (ADR-016), declared before the SPA catch-all.
- **PHPUnit tests** (17 cases): landed-cost value-basis allocation → correct unit cost + balanced
  posting; largest-remainder exactness; NRV write-down posts when NRV<cost and NOT when NRV>=cost
  (both == and > cost), balanced; batch run; value-as-of-date for a known FIFO ledger (before and
  after an issue) + moving-average + ageing; poster balance/refusal.
- **i18n** EN + NL for the report view labels.
- **No new schema, no seed, no register edits** — the balanced `GLTransaction` + `GLLine` (existing
  schemas) ARE the audit trail, mirroring `CogsPosterService`. New IAppConfig keys
  (`landed_cost_clearing_account`, `inventory_writedown_account`) carry documented RGS 3.5 MKB
  defaults.

## Capabilities

### New Capabilities

- `inventory-accounting-correctness` — value-as-of-date reporting, landed-cost capitalisation, and
  lower-of-cost-or-NRV write-down over the existing stock ledger.

## Follow-up (named, not blocking)

- **FIFO-layer persistence (optional).** Persisting explicit FIFO cost-layer objects would let
  OpenRegister's `x-openregister-aggregations` compute value-as-of-date natively (and enable
  multi-field groupBy reporting a sibling agent is adding to OR). Not required — the ledger replay
  is exact today. File as an OR/shillinq optimisation if aggregation-native reporting is wanted.
