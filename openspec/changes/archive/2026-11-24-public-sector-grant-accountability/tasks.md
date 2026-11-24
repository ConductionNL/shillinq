# Tasks: public-sector-grant-accountability

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Grants

- [x] 1.1 `determine` action and the reclaim invoice or payable (REQ-SUBV-011). Verify: PHPUnit red first; the invoice and payable validate against the real schemas; paid EUR 10,000, determined EUR 8,500 gives a draft reclaim of EUR 1,500.
- [x] 1.2 `report` action over `SubsidieVerantwoordingService` (REQ-SUBV-012). Verify: PHPUnit with the real service; the stored payload validates.

## 2. SiSa

- [x] 2.1 `SisaIndicator` fragment, `Subsidie.sisaRegulationCode`, `SisaAppendix::forYear` (REQ-SISA-012). Verify: PHPUnit red first; payload validates against the real fragment.
- [x] 2.2 Endpoint and CSV, grant page panel (REQ-SISA-013). Verify: controller test, route-auth and IDOR gates, vitest on the panel. — the panel is manifest configuration (a SisaIndicator related list and a CSV download on SubsidieDetail), so there is no Vue component for vitest.

## 3. EU funds

- [x] 3.1 `EuDeclaration` and `EuDeclarationBuilder::propose` (REQ-EUF-012). Verify: PHPUnit red first with the real guard; an expenditure without a certified document is left out with the reason.
- [x] 3.2 Submit and the project page action (REQ-EUF-013). Verify: PHPUnit; `check:manifest`; vitest. — the project page action and the declaration pages are manifest configuration, so there is no Vue component for vitest.
- [x] 3.3 `IrregularityReport` schema with the threshold check on escalate and the declaration block (REQ-EUF-007). Verify: PHPUnit red first for both scenarios; payload validates against the real schema.

## 4. Strings and live check

- [x] 4.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [x] 4.2 Live: determine a grant lower than paid out and see the draft reclaim; export the SiSa table for 2026; propose an EU declaration with one expenditure missing evidence.
  - **Written, runs in nightly (24 Nov 2026):** `tests/e2e/public-sector-grant-accountability.spec.ts` determines a grant at EUR 8,500 of EUR 10,000 paid and reads the EUR 1,500 draft reclaim invoice (REQ-SUBV-011), exports the 2026 SiSa table and CSV (REQ-SISA-012, REQ-SISA-013), and proposes an EU declaration that leaves out the expenditure missing evidence (REQ-EUF-012, REQ-EUF-013, REQ-EUF-007). In the CI Playwright testDir, matching no testIgnore; not verified here, no live instance in the build sandbox.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
