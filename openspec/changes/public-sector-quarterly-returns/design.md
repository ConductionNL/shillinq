# Design: public-sector-quarterly-returns

Read at shillinq development @655363ab0 on 2026-09-29.

## Decisions

### D1. BCF claim

A `compute` action on `BcfClaim` (declared by FQCN) calls `BcfClaimService::computeClaim($administrationId, $claimQuarter)` and writes `totalCompensableAmount` and `breakdown` with `patchObject`. The claim detail page shows the breakdown; `submit` keeps its guard (`BcfClaimGuard`, quarter closed). The unused `GET /api/bcf/compensation` route stays for integrations.

### D2. Fido quarter

`lib/Service/PublicSector/FidoQuarter.php::compute(string $organisationId, string $year, int $quarter)`: the cash limit is `baseBudget` times the statutory percentage (8.5 percent for a municipality, from `KasgeldLimiet.percentage`); the exposure is the average of the month-end net floating debt (short-term `Lening` with a term under one year minus bank balances from posted GL lines on the bank accounts) over the quarter; headroom is the difference. The interest risk norm is 20 percent of the budget total against the sum of refinancing and rate revisions of long-term loans in the year. It writes `KasgeldLimiet`, `RenteRisicoNorm` and the matching fields of `QuartaalrapportageFido`, whose `submit` guard stays `FidoTreasuryGuard::canSubmitRapportage`. A `compute` action on the quarterly report runs it.
