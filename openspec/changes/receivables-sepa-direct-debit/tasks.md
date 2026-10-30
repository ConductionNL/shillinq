# Tasks: receivables-sepa-direct-debit

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Backend

- [ ] 1.1 `DirectDebitBatchService::propose` (REQ-SDD-011). Verify: PHPUnit red first with the real guards; collections and batch validated against the real fragment; an invoice without an active mandate is skipped with the reason.
- [ ] 1.2 `SepaPain008Generator` and the `generate` action, XSD validated (REQ-SDD-012). Verify: PHPUnit asserts the XML validates against `pain.008.001.02.xsd` and carries the control sum; an invalid IBAN keeps the batch draft.
- [ ] 1.3 Download endpoint (REQ-SDD-012). Verify: controller test; a caller from another administration is refused; route-auth and IDOR gates.
- [ ] 1.4 `succeed` registers the payment, `reject` keeps the invoice open (REQ-SDD-013). Verify: PHPUnit with the real `InvoiceSettlementService`.

## 2. Pages

- [ ] 2.1 `SepaMandates`, `DirectDebitBatches` and batch detail with propose, generate and download (REQ-SDD-011, 012). Verify: `npm run check:manifest`; nav reachability; vitest on the actions.

## 3. Strings and end to end

- [ ] 3.1 Dutch and English strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 3.2 Playwright `tests/e2e/receivables-sepa-direct-debit.spec.ts`: register a mandate, propose, generate, download. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
