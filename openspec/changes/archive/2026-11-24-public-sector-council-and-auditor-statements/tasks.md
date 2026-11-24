# Tasks: public-sector-council-and-auditor-statements

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Lawfulness

- [x] 1.1 `LawfulnessParagraph::compute` and the `compute` action (REQ-RV-011). Verify: PHPUnit red first over real GL lines, findings and a tolerance; totals validate against the real fragment; 1 percent of EUR 200 million is EUR 2 million.

## 2. Audit protocol

- [x] 2.1 `AuditSampleDrawer::draw` (REQ-011 of bookkeeping-bado-controleprotocol). Verify: PHPUnit red first; the same seed gives the same sample; the `AuditSample` payload validates.
- [x] 2.2 Draw action and aggregation panel on the protocol page (REQ-012). Verify: vitest; `check:manifest`.

## 3. ENSIA

- [x] 3.1 Transition actions for findings, statement and XML (REQ-ENSIA-011). Verify: PHPUnit red first with the real generators; the stored `Bevinding` payload validates.
- [x] 3.2 XML download (REQ-ENSIA-012). Verify: controller test, route-auth and IDOR gates.

## 4. Strings and live check

- [x] 4.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [x] 4.2 Live: compute the 2026 paragraph with one finding; draw a sample of 25 twice with the same seed; move an ENSIA cycle to college approval and download the statement.
  - **Written, runs in nightly (24 Nov 2026):** `tests/e2e/public-sector-council-and-auditor-statements.spec.ts` computes the paragraph with one EUR 2.5M finding against a EUR 2M tolerance (REQ-RV-011), draws a sample of 25 twice with seed 20261 and gets the same lines (REQ-011; the population is 30 seeded lines, not 4,000), and moves an ENSIA cycle through peer review to college approval, downloading the statement and, after submission, the XML (REQ-ENSIA-011, REQ-ENSIA-012). In the CI Playwright testDir, matching no testIgnore; not verified here, no live instance in the build sandbox.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
