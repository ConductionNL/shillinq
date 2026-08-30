# inventory-accounting-correctness Specification

**Status**: done
**Scope**: shillinq
**Kind**: code
**OpenSpec changes**:
- inventory-accounting-correctness — archived 2026-07-14; synced into `openspec/specs/inventory-accounting-correctness/spec.md`

Three balance-sheet-correctness capabilities over the existing immutable `StockMove` ledger and
`InventoryValuation` snapshots: stock value as-of-date reporting, landed-cost capitalisation, and
lower-of-cost-or-NRV write-down. All services consume OpenRegister's ObjectService (ADR-022 — no
app tables, no SQL) and are ADR-031 exception services (imperative orchestration the declarative
grammar cannot express). LIFO is out of scope (IAS 2.25 prohibits).

## Requirements

### Requirement: Stock value as-of-date SHALL be computable from the ledger

The system SHALL provide `OCA\Shillinq\Service\InventoryValuationReportService::valuationAsOf(administrationId, asOfDate, sku?, warehouse?)`
that returns the inventory value at a historical cut-off (the jaarrekening `voorraadwaarde per
<as-of-date>`) by REPLAYING every posted, non-cancelled `StockMove` with `postedAt <= asOfDate` per
`(sku, warehouse)`. FIFO items SHALL reconstruct open lots and consume them oldest-first; average
items SHALL replay the running weighted average. The costing method SHALL be read from the driving
`InventoryValuation.valuationMethod` (default FIFO). No FIFO-layer object persistence is required.

#### Scenario: FIFO value before any issue

- **WHEN** the ledger holds receipts 30@10,00 and 20@12,00 and the cut-off precedes any issue
- **THEN** `valuationAsOf` returns totalValue 540,00 over quantity 50
- @e2e exclude pure backend ledger-replay arithmetic — asserted via PHPUnit (tests/Unit/Service/InventoryValuationReportServiceTest.php), not UI

#### Scenario: FIFO value after an issue consumes the oldest lots

- **WHEN** a 35-unit issue posts against those receipts and the cut-off follows it
- **THEN** FIFO consumes 30@10,00 + 5@12,00 and `valuationAsOf` returns totalValue 180,00 over quantity 15 at unit cost 12,00
- @e2e exclude pure backend ledger-replay arithmetic — asserted via PHPUnit (tests/Unit/Service/InventoryValuationReportServiceTest.php), not UI

#### Scenario: moving-average value replays the running average

- **WHEN** the driving snapshot's method is `average` for the same ledger
- **THEN** after the 35-unit issue `valuationAsOf` returns 15 units at average 10,80 = totalValue 162,00
- @e2e exclude pure backend ledger-replay arithmetic — asserted via PHPUnit (tests/Unit/Service/InventoryValuationReportServiceTest.php), not UI

### Requirement: FIFO residual lots SHALL be aged into buckets

The service SHALL provide `ageing(administrationId, asOfDate, sku, warehouse)` that buckets the FIFO
residual open lots by `asOfDate − lot.postedAt` into 0-30 / 31-60 / 61-90 / 90+ day bands, each
valued at `lotQty × lotCost`.

#### Scenario: residual lot lands in the 31-60 day bucket

- **WHEN** the residual 15@12,00 lot was received 46 days before the cut-off
- **THEN** its 180,00 value falls in the 31-60 bucket and the 0-30 bucket is 0,00
- @e2e exclude pure backend ageing arithmetic — asserted via PHPUnit (tests/Unit/Service/InventoryValuationReportServiceTest.php), not UI

### Requirement: Landed costs SHALL be capitalised into unit cost with a balanced posting

The system SHALL provide `OCA\Shillinq\Service\LandedCostAllocationService::allocate(administrationId, receiptReference, landedCostCents, basis)`
that allocates a receipt's total landed cost across its `receipt` lines pro-rata by extended value
(default) or quantity, using a largest-remainder distribution so allocated cents sum EXACTLY to the
input. Each line's landed unit cost SHALL be `(originalValueCents + allocatedCents) / 100 /
quantity`. The service SHALL post ONE BALANCED `GLTransaction` — debit the inventory asset account
(`inventory_account`, default 1300), credit the landed-cost clearing account
(`landed_cost_clearing_account`, default 1305) — and bump the driving `InventoryValuation` snapshot.

#### Scenario: two lines, value basis

- **WHEN** lines of 300,00 and 240,00 receive a 54,00 landed cost on the value basis
- **THEN** shares are 30,00 / 24,00, landed unit costs are 11,00 / 13,20, and a single balanced GLTransaction posts debit 1300 / credit 1305 of 54,00 (debitCents === creditCents)
- @e2e exclude pure backend allocation + balanced-posting arithmetic — asserted via PHPUnit (tests/Unit/Service/LandedCostAllocationServiceTest.php), not UI

#### Scenario: largest-remainder keeps allocation exact

- **WHEN** 100 cents are allocated across three equal-value lines
- **THEN** the shares are 34 / 33 / 33 summing to exactly 100 and the posting stays balanced
- @e2e exclude pure backend rounding arithmetic — asserted via PHPUnit (tests/Unit/Service/LandedCostAllocationServiceTest.php), not UI

### Requirement: Inventory SHALL be written down to the lower of cost and NRV

The system SHALL provide `OCA\Shillinq\Service\NrvWriteDownService::writeDown(valuation, nrvPerUnit, periodId)`
implementing lower-of-cost-or-NRV (RJ 220.301 / IAS 2.9). When `nrvPerUnit < unitCost` it SHALL post
a BALANCED write-down — debit the write-down expense account (`inventory_writedown_account`, default
7050), credit the inventory asset account (`inventory_account`, default 1300),
`writeDownCents = round((unitCost − nrvPerUnit) × quantity × 100)` — and re-mark the snapshot to NRV
(`status = adjusted`). When `nrvPerUnit >= unitCost` it SHALL be a strict no-op: inventory is NEVER
written up. A batch `runForAdministration(administrationId, periodId, nrvBySku)` SHALL drive it from
an operator NRV-per-SKU map. LIFO SHALL NOT be implemented (IAS 2.25).

#### Scenario: NRV below cost posts a balanced write-down

- **WHEN** 100 units carried at 10,00 have an NRV of 7,00
- **THEN** a balanced GLTransaction posts debit 7050 / credit 1300 of 300,00 and the snapshot is re-marked to unit cost 7,00 / totalValue 700,00 / status adjusted
- @e2e exclude pure backend lower-of-cost-or-NRV arithmetic — asserted via PHPUnit (tests/Unit/Service/NrvWriteDownServiceTest.php), not UI

#### Scenario: NRV at or above cost is a no-op

- **WHEN** the NRV equals or exceeds the carrying unit cost
- **THEN** nothing is posted, no snapshot is changed, and `writeDownCents` is 0 (inventory is never written up)
- @e2e exclude pure backend guard — asserted via PHPUnit (tests/Unit/Service/NrvWriteDownServiceTest.php), not UI

### Requirement: Every inventory adjustment posting SHALL be balanced

The system SHALL provide `OCA\Shillinq\Service\InventoryGlAdjustmentPoster` that materialises a
two-line `GLTransaction` (debit + credit) from a single `amountCents` so `debitCents === creditCents`
by construction. It SHALL refuse (log, `posted:false`, write nothing) any request whose accounts are
missing or whose amount is non-positive.

#### Scenario: unbalanced or missing-account request is refused

- **WHEN** a post is requested with an empty account or a non-positive amount
- **THEN** no GLTransaction and no GLLine is written and the result is `posted:false`
- @e2e exclude pure backend poster guard — asserted via PHPUnit (tests/Unit/Service/InventoryGlAdjustmentPosterTest.php), not UI

## Follow-up

- FIFO-layer object persistence — OPTIONAL. Ledger replay is exact today; persisting cost layers as
  objects would only avoid full-history replay for long-lived high-turnover SKUs. Deferred; not required
  for correctness.
