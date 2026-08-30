# Tasks: inventory-accounting-correctness (kind: code)

Code-only. No schema, no seed, no register edits. All services consume OpenRegister's ObjectService
(ADR-022) and every GL posting is balanced by construction.

## 1. Shared balanced poster

- [x] 1.1 Create `lib/Service/InventoryGlAdjustmentPoster.php` — `post()` writes one `GLTransaction` (state draft) + two `GLLine` rows (debit/credit) from a single `amountCents`; refuses unbalanced or missing-account requests (logged, `posted:false`)
- [x] 1.2 Money discipline: integer cents; `debitCents === creditCents`; `transactionNumber = <journalCode>-<year>-<sourceReference>`

## 2. Landed-cost allocation (item 2)

- [x] 2.1 Create `lib/Service/LandedCostAllocationService.php` — read a receipt's `receipt` StockMove lines by `referenceDocumentUri`; weights by extended value (default) or quantity
- [x] 2.2 Largest-remainder (Hamilton) distribution so `Σ share === landedCostCents` exactly; compute per-line landed unit cost `(origValueCents + share)/100/qty`
- [x] 2.3 Post ONE balanced capitalisation via the poster (debit `inventory_account` 1300 / credit `landed_cost_clearing_account` 1305); bump the active `InventoryValuation` snapshot's `totalValue`/`unitCost`

## 3. NRV write-down (item 3)

- [x] 3.1 Create `lib/Service/NrvWriteDownService.php` — `writeDown(valuation, nrvPerUnit, periodId)`: no-op when `nrvPerUnit >= unitCost` (never write up); else `writeDownCents = round((unitCost−nrvPerUnit)·qty·100)`
- [x] 3.2 Post balanced write-down (debit `inventory_writedown_account` 7050 / credit `inventory_account` 1300); re-mark snapshot to NRV (`unitCost`, `totalValue`, `status=adjusted`)
- [x] 3.3 Batch `runForAdministration(administrationId, periodId, nrvBySku)` over active snapshots; aggregate count + total. LIFO NOT built (IAS 2.25)

## 4. Value-as-of-date reporting (item 1)

- [x] 4.1 Create `lib/Service/InventoryValuationReportService.php` — `valuationAsOf()` replays posted `receipt`+`issue` moves with `postedAt <= cutoff` per `(sku, warehouse)`; FIFO lot reconstruction + moving-average replay; method from `InventoryValuation.valuationMethod` (default FIFO)
- [x] 4.2 `ageing()` FIFO residual-lot buckets (0-30 / 31-60 / 61-90 / 90+ days); `turnover()` `COGS(window)/average inventory value` + days-on-hand; bare-date cut-off normalised to end-of-day inclusive

## 5. Controllers + routes (ADR-003 / ADR-005 / ADR-016)

- [x] 5.1 `lib/Controller/InventoryValuationReportController.php` — `#[NoAdminRequired]` `report()` (GET), RBAC per `administrationId` via `AdministrationContextService` (masked 404), input validation → 400
- [x] 5.2 `lib/Controller/InventoryAdjustmentController.php` — `#[NoAdminRequired]` `landedCost()` + `nrvWriteDown()` (POST), same RBAC/validation; validate `nrv_by_sku` map
- [x] 5.3 Register 3 routes in the `$extra` array of `appinfo/routes.php`, declared before the SPA catch-all; confirm no name/URL collision

## 6. i18n (ADR-007)

- [x] 6.1 Add report-view label keys (English is the key) to `l10n/en.json` + `l10n/nl.json`; controller API error strings stay plain English per the in-repo `StockLedgerController` precedent

## 7. Tests (PHPUnit — mandatory, real numbers)

- [x] 7.1 `InventoryGlAdjustmentPosterTest` — balanced two-line post; refuse missing account; refuse non-positive amount
- [x] 7.2 `LandedCostAllocationServiceTest` — value-basis allocation → unit costs 11,00 / 13,20 + balanced 54,00 posting; largest-remainder exactness (100c over 3 lines)
- [x] 7.3 `NrvWriteDownServiceTest` — posts 300,00 balanced when NRV<cost; no-op when NRV>=cost (both `==` and `>`); batch run aggregates only below-cost
- [x] 7.4 `InventoryValuationReportServiceTest` — value-as-of-date before/after issue (540,00/50 and 180,00/15); moving-average 162,00; FIFO ageing 31-60 bucket
- [x] 7.5 Run full unit suite in the php:8.3 container (ext-zip+bcmath+soap+xsl+intl+gd, fresh composer install) — no regressions

## 8. Spec delta

- [x] 8.1 Author `specs/inventory-accounting-correctness/spec.md` with ADDED requirements + `@e2e exclude` reasons for the backend-only scenarios
