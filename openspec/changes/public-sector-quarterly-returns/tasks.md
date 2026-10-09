# Tasks: public-sector-quarterly-returns

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. BCF

- [x] 1.1 `compute` action on `BcfClaim` (REQ-BCF-010). Done 9 Oct 2026: `lib/Lifecycle/Action/ComputeBcfClaimAction.php`, transition `compute` (draft to draft) in bookkeeping-bcf-vat-compensation.json (BcfClaim 0.2.0, info.xml 0.5.6-unstable.20261009090000, lock recorded); tests/Unit/Lifecycle/Action/ComputeBcfClaimActionTest.php validates the saved claim against the merged schema. Verify: PHPUnit red first with the real service and calculator; the patched payload validates against the real fragment.
- [x] 1.2 Claim page breakdown (REQ-BCF-010). Done 9 Oct 2026: BcfClaimDetail shows claim quarter, compensable VAT and the breakdown per account; compute also sets totalClaimAmount for the threshold guard; tests/vitest/bcfClaimBreakdown.spec.js. Verify: vitest; `check:manifest`.

## 2. Fido

- [ ] 2.1 `FidoQuarter::compute` (REQ-FDO-010). Verify: PHPUnit red first; a EUR 100 million budget at 8.5 percent gives a EUR 8.5 million limit; payloads validate against the real fragment.
- [ ] 2.2 `compute` action on the quarterly report and the dashboard figures (REQ-FDO-011). Verify: PHPUnit; vitest on the dashboard.

## 3. Strings and live check

- [ ] 3.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 3.2 Live: compute the BCF claim for 2026-Q3 and see the breakdown; compute Fido Q3 and see headroom on the dashboard.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
