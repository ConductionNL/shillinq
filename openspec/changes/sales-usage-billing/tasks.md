# Tasks: sales-usage-billing

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Backend

- [ ] 1.1 `MeterReadingImportService` and the import endpoint (REQ-USB-001). Verify: PHPUnit red first; rows validated with `RegisterSchema::errors` on the real fragment; a row with a negative quantity is refused with its row number.
- [ ] 1.2 Billed readings move to invoiced; an invoiced reading passed again is refused (REQ-USB-002). Verify: PHPUnit on `InvoiceGenerationService` with the real `BillingModelEngine`.

## 2. Pages

- [ ] 2.1 `UsageRatePlans` and `MeterReadings` pages with the rate and import actions (REQ-USB-001). Verify: `npm run check:manifest`; nav reachability.
- [ ] 2.2 Usage option in `InvoiceGenerator.vue` (REQ-USB-002). Verify: vitest mounts the generator, picks usage and asserts the request carries the reading ids.

## 3. Strings and end to end

- [ ] 3.1 Dutch and English strings. Verify: `npm run test:l10n`.
- [ ] 3.2 Playwright `tests/e2e/sales-usage-billing.spec.ts`: import readings, rate, invoice. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
