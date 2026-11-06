# Tasks: public-sector-grant-accountability

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Grants

- [ ] 1.1 `determine` action and the reclaim invoice or payable (REQ-SUBV-011). Verify: PHPUnit red first; the invoice and payable validate against the real schemas; paid EUR 10,000, determined EUR 8,500 gives a draft reclaim of EUR 1,500.
- [ ] 1.2 `report` action over `SubsidieVerantwoordingService` (REQ-SUBV-012). Verify: PHPUnit with the real service; the stored payload validates.

## 2. SiSa

- [ ] 2.1 `SisaIndicator` fragment, `Subsidie.sisaRegulationCode`, `SisaAppendix::forYear` (REQ-SISA-012). Verify: PHPUnit red first; payload validates against the real fragment.
- [ ] 2.2 Endpoint and CSV, grant page panel (REQ-SISA-013). Verify: controller test, route-auth and IDOR gates, vitest on the panel.

## 3. EU funds

- [ ] 3.1 `EuDeclaration` and `EuDeclarationBuilder::propose` (REQ-EUF-012). Verify: PHPUnit red first with the real guard; an expenditure without a certified document is left out with the reason.
- [ ] 3.2 Submit and the project page action (REQ-EUF-013). Verify: PHPUnit; `check:manifest`; vitest.

## 4. Strings and live check

- [ ] 4.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 4.2 Live: determine a grant lower than paid out and see the draft reclaim; export the SiSa table for 2026; propose an EU declaration with one expenditure missing evidence.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
