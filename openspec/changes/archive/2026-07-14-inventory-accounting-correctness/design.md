# Design: inventory-accounting-correctness

**Kind:** code · **Scope:** shillinq · **depends_on:** none

Bundles three inventory-valuation correctness gaps that all touch balance-sheet correctness. Each
was verified against HEAD before any code was written; "already covered" was a valid per-item
outcome. This document records the per-item verify-first verdict, the arithmetic, the Seed Data,
and the ADR-031 justification.

## Per-item verify-first verdicts

### 1. inventory-reporting-pack — VERDICT: BUILD (sweep's blocker verified FALSE)

The sweep said "needs FIFO layers persisted first". **Verified against HEAD**
(`lib/Service/FifoValuationService.php`): FIFO cost layers are NOT persisted as objects. Each
posted `receipt` StockMove IS a lot; `openInboundLots()` reads them from the ledger and
`consumedQtyFromHistory()` derives consumption from `issue` rows — the snapshot is a derived view
(design D2 of `inventory-stock-movement-ledger`). Consequence: value-as-of-date is computable TODAY
by replaying posted moves with `postedAt <= asOfDate`; **no FIFO-layer persistence is required.**
The blocker does not apply. Built: `InventoryValuationReportService` (value-as-of-date, ageing,
turnover). The layer-persistence idea is recorded as an OPTIONAL follow-up (would enable
OR-aggregation-native reporting), not a dependency.

### 2. inventory-landed-cost — VERDICT: BUILD (no prior coverage)

Verified: no landed-cost allocation anywhere in `lib/` (the only `landed`/`nrv` hits are compliance
rule catalogues under `lib/Standards/`). Built: `LandedCostAllocationService` — pro-rata
capitalisation into unit cost + one balanced GL posting.

### 3. inventory-nrv-writedown — VERDICT: BUILD (no prior coverage)

Verified: no lower-of-cost-or-NRV logic exists. Built: `NrvWriteDownService` — period-end write-down
posting. LIFO deliberately NOT built (IAS 2.25 prohibits); the two costing methods stay FIFO and
weighted average.

## Arithmetic (money discipline: integer cents, round once at the boundary)

### Value-as-of-date (ledger replay)

Per `(sku, warehouse)`, take all posted, non-cancelled `receipt` + `issue` moves with
`postedAt <= cutoff`, sort chronologically, and replay:

- **FIFO**: each receipt pushes a lot `{qty, unitCost, postedAt}`; each issue consumes lots
  oldest-first; residual value `= Σ lotQty × lotCost`.
- **average**: running weighted average
  `newAvg = (curQty·curAvg + rcvQty·rcvCost) / (curQty + rcvQty)`; issues decrement qty and retain
  the average; value `= qty × avg`.

The method is read from the driving `InventoryValuation.valuationMethod` (default FIFO). A bare
`yyyy-mm-dd` cut-off is normalised to `…T23:59:59Z` so same-day moves are inclusive
(`voorraadwaarde per 31-12` includes 31-12 postings).

Worked example (test `InventoryValuationReportServiceTest`): receipts 30@10,00 (01-04) + 20@12,00
(15-04), issue 35 (01-05). As-of 30-04 → 540,00 / 50 units. As-of 31-05 → FIFO consumes 30@10 +
5@12, residual 15@12 = 180,00 / 15 units. Moving-average as-of 31-05 → 15 @ avg 10,80 = 162,00.

### Landed-cost allocation

`weight[i] = qty[i] × unitCost[i]` (value basis; `qty[i]` for quantity basis). Distribute
`landedCostCents` by largest-remainder (Hamilton) so `Σ share[i] === landedCostCents` EXACTLY —
this guarantees the posting stays balanced. `landedUnit[i] = (origValueCents[i] + share[i]) / 100 /
qty[i]`. Worked example: lines 300,00 + 240,00, landed 54,00 → shares 30,00 / 24,00 → unit costs
11,00 / 13,20. Posting: **debit Inventory 1300 / credit landed-cost clearing 1305, 54,00**.

### NRV write-down (lower-of-cost-or-NRV)

If `nrvPerUnit >= unitCost` → strict no-op (never write UP; no reversal above historical cost). Else
`writeDownCents = round((unitCost − nrvPerUnit) × quantity × 100)`. Posting: **debit write-down
expense 7050 / credit Inventory 1300**; snapshot re-marked `unitCost = nrvPerUnit`,
`totalValue = qty × nrvPerUnit`, `status = adjusted`. Worked example: 100 @ 10,00, NRV 7,00 →
write-down 300,00.

## Balanced-posting invariant

Both adjustment engines post via `InventoryGlAdjustmentPoster`, which derives both legs from a
single `amountCents` (`debitCents === creditCents` by construction), re-asserts the equality, and
refuses to post an unbalanced or missing-account request (logged, never a lopsided journal).
`GLTransaction` state is `draft` (enters the normal GL approval flow like `CogsPosterService`).

## GL accounts (IAppConfig, documented RGS 3.5 MKB defaults)

| Key                             | Default | Role                                   |
|---------------------------------|---------|----------------------------------------|
| `inventory_account`             | `1300`  | Inventory asset (existing key)         |
| `landed_cost_clearing_account`  | `1305`  | Landed-cost accrual clearing (NEW)     |
| `inventory_writedown_account`   | `7050`  | Afwaardering voorraden — expense (NEW) |
| `cogs_account`                  | `5100`  | COGS (existing, unchanged)             |

## Seed Data

None. This change adds no schema and no seed. The audit trail is the balanced `GLTransaction` +
two `GLLine` rows (existing schemas), exactly as `CogsPosterService` already writes. Reporting is
read-only over the existing `StockMove` ledger. Manual verification uses an administration that
already has posted `StockMove` + `InventoryValuation` records (any inventory-enabled tenant).

## ADR-031 exception justification

All three services are imperative orchestration the declarative `x-openregister-notifications` /
`-aggregations` grammar cannot express: (a) a chronological ledger REPLAY with lot reconstruction
bounded by a runtime date; (b) a pro-rata allocation with largest-remainder rounding across a
receipt's lines; (c) a conditional lower-of-cost-or-NRV branch with a runtime-computed monetary
delta. Precedent in this same register: `CogsPosterService` (COGS) and
`Treasury\FxRevaluationService` (period-end revaluation). Each is thin, ADR-022-compliant (reads +
writes via ObjectService), and fail-soft.

## Non-goals

- LIFO (IAS 2.25 prohibits).
- FIFO-layer object persistence (optional follow-up; ledger replay is exact today).
- Mutating posted `StockMove` rows (immutable — landed cost is capitalised via a GL posting +
  snapshot bump, not by editing sealed ledger rows).
