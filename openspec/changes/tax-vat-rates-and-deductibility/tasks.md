# Tasks: tax-vat-rates-and-deductibility

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Tariffs

- [ ] 1.1 Point `SettingsService::seedBtwTariffs()` at `btw-tariffs-2026.json`, add `section` per tariff to the seed, and add `statutory` to seeded records (REQ-TVRD-001). Verify: PHPUnit that the seeder finds the file and is idempotent; a repair run on a local instance lists five tariffs.
- [ ] 1.2 Merge the two `VatTariff` declarations into the `register.d` one and remove the monolith copy (REQ-TVRD-001). Verify: `npm run check:registers`; re-import with no failed schemas.
- [ ] 1.3 Add pages `VatTariffs` and `VatTariffDetail` under Belastingen, with statutory records read-only (REQ-TVRD-001). Verify: Playwright adds mid-12 and cannot edit high.

## 2. Calculation

- [ ] 2.1 Add `lib/Service/Tax/VatTariffResolver.php` with the dated lookup and the legacy-code map, and make `VATCalculationService` use it, removing `VALID_RATES` (REQ-TVRD-002). Verify: PHPUnit for each tariff, a legacy code, an expired tariff and an unknown code.
- [ ] 2.2 Pass tariff codes from `InvoiceGenerationService` and the invoice lines it builds (REQ-TVRD-002). Verify: the existing generation tests updated and green; Playwright generates the mixed-rate invoice.

## 3. Deductibility

- [ ] 3.1 Add `deductiblePercentage` and `privateShareAccountNumber` to `Account` with the pair rule, and show them on the account detail page (REQ-TVRD-003). Verify: `npm run check:registers`; Playwright sets 70 percent on 4520.
- [ ] 3.2 Add `deductiblePercentage` to `JournalEntry` and `APTransaction` cost lines and write the split in the posting mappers (REQ-TVRD-004). Verify: PHPUnit for 70 percent, 100 percent and a line override, each balancing.

## 4. End to end

- [ ] 4.1 Live check with `ledger-posting-path` merged: post the seed fuel invoice and read the four ledger lines (REQ-TVRD-004). Verify: transaction id and lines in the PR body.

## 5. Docs

- [ ] 5.1 Release note: tariffs now seeded, legacy codes mapped, the split and its default of 100. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-N` on every new method, Dutch and English strings for every label and message.
