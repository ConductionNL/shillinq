# Design: tax-vat-position-and-suppletie

Read at shillinq `feat/ledger-booking-rules` @6ee1421d9 on 2026-09-29.

## Context

- After `tax-vat-return-from-books`, every posted line on a VAT-relevant account carries `vatReturnBox` and `vatAmountKind`.
- `VATReturnService::getReturn` reads a return; its preparation reads the stamped lines.

## Decisions

### D1. The position is an aggregation over stamped lines

`GET /api/vat-position?administrationId=&period=` (`#[NoAdminRequired]`, administration checked) returns per box the sum of base and VAT of posted `GLLine` in the period, plus payable. `lib/Service/Tax/VatPositionService.php` uses the same box logic as `VATReturnService` preparation (one shared method, not a copy), so the position of a closed period equals its prepared return.

### D2. Actions on existing pages

- Return detail: "Check for corrections" calls `POST /api/vat-returns/{id}/detect-drift` → `detect`.
- `BtwCorrecties` detail: "Prepare supplementary return" calls `POST /api/vat-corrections/{id}/prepare` → `prepare`; then "File" through the `tax-digipoort-filing` hand-off.
Both endpoints check the object's administration.

### D3. Detection after close

A listener on the period close (`ledger-period-end`) runs `detect` for each filed return of the closed period's fiscal year and logs the count.

## Dependencies

`tax-vat-return-from-books` (stamped boxes), `tax-digipoort-filing` (filing).

