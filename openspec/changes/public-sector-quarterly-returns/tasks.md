# Tasks: public-sector-quarterly-returns

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. BCF

- [x] 1.1 `compute` action on `BcfClaim` (REQ-BCF-010). Done 9 Oct 2026: `lib/Lifecycle/Action/ComputeBcfClaimAction.php`, transition `compute` (draft to draft) in bookkeeping-bcf-vat-compensation.json (BcfClaim 0.2.0, info.xml 0.5.6-unstable.20261009090000, lock recorded); tests/Unit/Lifecycle/Action/ComputeBcfClaimActionTest.php validates the saved claim against the merged schema. Verify: PHPUnit red first with the real service and calculator; the patched payload validates against the real fragment.
- [x] 1.2 Claim page breakdown (REQ-BCF-010). Done 9 Oct 2026: BcfClaimDetail shows claim quarter, compensable VAT and the breakdown per account; compute also sets totalClaimAmount for the threshold guard; tests/vitest/bcfClaimBreakdown.spec.js. Verify: vitest; `check:manifest`.

## 2. Fido

- [x] 2.1 `FidoQuarter::compute` (REQ-FDO-010). Done 9 Oct 2026: `lib/Service/PublicSector/FidoQuarter.php` (cash limit, average month-end net floating debt, ladder from the previous report, interest risk norm for the year and three after); tests/Unit/Service/PublicSector/FidoQuarterTest.php with the saved KasgeldLimiet and RenteRisicoNorm validated against the merged schema. Reads the organisation's GL as the administration with the same id (Q-shillinq-3). Verify: PHPUnit red first; a EUR 100 million budget at 8.5 percent gives a EUR 8.5 million limit; payloads validate against the real fragment.
- [x] 2.2 `compute` action on the quarterly report and the dashboard figures (REQ-FDO-011). Done 9 Oct 2026: `ComputeFidoQuarterAction` on QuartaalrapportageFido (0.2.0, info.xml 0.5.6-unstable.20261009100000, lock recorded); the Treasury Dashboard's two fixed-zero tiles now read the latest KasgeldLimiet and RenteRisicoNorm; tests/Unit/Lifecycle/Action/ComputeFidoQuarterActionTest.php, tests/vitest/fidoQuarterCompute.spec.js. Verify: PHPUnit; vitest on the dashboard.

## 3. Strings and live check

- [x] 3.1 English and Dutch strings. Done 9 Oct 2026 for every string this change added (en, nl); test:l10n, test:l10n-parity, check:schema-l10n 0. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 3.2 (not run: needs the live instance) Live: compute the BCF claim for 2026-Q3 and see the breakdown; compute Fido Q3 and see headroom on the dashboard.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
