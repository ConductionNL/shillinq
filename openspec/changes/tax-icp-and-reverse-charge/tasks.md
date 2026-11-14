# Tasks: tax-icp-and-reverse-charge

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Reverse charge on the invoice

- [ ] 1.1 `ReverseChargeNotice` and its use in `InvoicePdfGenerator` and `ArInvoiceUblMapper` (REQ-ICR-001). Verify: PHPUnit red first; the UBL of an EU reverse-charge invoice carries category K and the buyer VAT number.
- [ ] 1.2 Issue guard for a reverse-charged invoice without a buyer VAT number (REQ-ICR-001). Verify: PHPUnit with the declared-lifecycle engine.

## 2. ICP statement

- [ ] 2.1 Prepare endpoint writing the draft statement, validated against the real fragment (REQ-ICR-002). Verify: PHPUnit red first; a filed statement is not replaced.
- [ ] 2.2 `IcpReportGenerator` for `icp-opgaaf` (REQ-ICR-002). Verify: PHPUnit; `ReportGenerationService` no longer answers "no generator".
- [ ] 2.3 ICP page: prepare, reconciliation panel, download, file when available (REQ-ICR-002, 003). Verify: `npm run check:manifest`; vitest on the actions.
- [ ] 2.4 Filing through the Digipoort path on the ICP entry point (REQ-ICR-003). Verify: PHPUnit with the `tax-digipoort-filing` hand-off stub named after the real class.

## 3. Strings and end to end

- [ ] 3.1 Dutch and English strings, including the notice text. Verify: `npm run test:l10n`.
- [ ] 3.2 Playwright `tests/e2e/tax-icp-and-reverse-charge.spec.ts`. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
