# Tasks: public-sector-quarterly-returns

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. BCF

- [ ] 1.1 `compute` action on `BcfClaim` (REQ-BCF-010). Verify: PHPUnit red first with the real service and calculator; the patched payload validates against the real fragment.
- [ ] 1.2 Claim page breakdown (REQ-BCF-010). Verify: vitest; `check:manifest`.

## 2. Fido

- [ ] 2.1 `FidoQuarter::compute` (REQ-FDO-010). Verify: PHPUnit red first; a EUR 100 million budget at 8.5 percent gives a EUR 8.5 million limit; payloads validate against the real fragment.
- [ ] 2.2 `compute` action on the quarterly report and the dashboard figures (REQ-FDO-011). Verify: PHPUnit; vitest on the dashboard.

## 3. Strings and live check

- [ ] 3.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 3.2 Live: compute the BCF claim for 2026-Q3 and see the breakdown; compute Fido Q3 and see headroom on the dashboard.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
