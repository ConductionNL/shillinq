---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: tax-vat-rates-and-deductibility

## Summary

VAT on a line is calculated from four rates fixed in a PHP constant, while
a `VatTariff` register that should hold them exists and is never filled,
because the seeder asks for a file name that is not in the repository. And
a cost that is partly private, a car or a phone, cannot be booked as such:
its full VAT is reclaimed and its full amount is a business cost. This
change fills the tariff register and calculates from it, and splits a mixed
cost into a deductible and a private share with only the deductible VAT
reclaimed.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the VAT on a line. The OpenSpec
pass of 2026-09-27 decided `build` for both. This change covers both.

**`tax-vat-calc`**, "Have VAT calculated per line from rates you can
configure." Rated partial, built. The matrix evidence:
"lib/Service/VATCalculationService.php:31 fixes the rates in a VALID_RATES
constant (0, 6, 9, 21); lib/Service/InvoiceGenerationService.php:67
applies it per line when an invoice is generated from time and expense".
The note: "Per-line VAT works on the generated invoice, but the rates are a
code constant, not configurable." No demand row. All five competitors rate
it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-masterdata-finmd-crtedtlnkvatcodest?language=en_GB, "You can create VAT codes so that Exact Online can automatically register your VAT amounts".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/223671-btw-tarieven-in-moneybird, "Moneybird berekent vervolgens automatisch het btw-bedrag", with own rates under Instellingen.
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/een-verkoopfactuur-maken, "In de omzetgroep staat ingesteld ... welk btw-tarief van toepassing is".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-codes-3041086, "Alle velden worden automatisch berekend en gevuld op basis van de gekozen btw-codes" (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-aangifte-3041090).
- odoo: odoo/odoo@19.0 `addons/account/models/account_tax.py:4975` `compute_all` per line from configurable `account.tax` records.

**`tax-partial-deductibility`**, "Book only the deductible share of a mixed
business and private cost, with its VAT." Rated no, built state none. The
note: "lib/Service/TaxReportCalculator.php:202 adds nonDeductible expense
back on the income tax return, but no booking splits a mixed cost into a
deductible share with its VAT." Demand from the odoo changelog
https://www.odoo.com/odoo-19-release-notes. Two competitors rate it yes:

- moneybird: https://www.moneybird.nl/changelog/verdeel-zakelijke-en-privekosten-automatisch/, "Met fiscale verdelingen splitst Moneybird dit automatisch voor je. Het privédeel boeken we direct op een rekening zoals Privé-opnames".
- odoo: odoo/odoo@19.0 `addons/account/models/account_move_line.py:422` `deductible_amount` per line, posted to the journal's "Private Share Account" (`addons/account/models/account_journal.py:137`).

## Affected Projects

- [ ] Project: `shillinq`: the VAT tariff seeder and page, the VAT calculator, two fields on the ledger account, and the posting of cost lines.

## Scope

### In Scope

- Seeding `VatTariff` from the file that exists, and one page to see the tariffs and add one with its dates.
- Calculating VAT per line from the tariff valid on the document date, in place of the constant.
- A deductible percentage and a private-share account on a ledger account, as the default for its cost lines.
- A deductible percentage on a cost line, overriding the default.
- Posting a split line: the deductible share to the cost account with its input VAT, the private share including its VAT to the private-share account, marked non-deductible.

### Out of Scope

- The VAT return boxes for these lines. `tax-vat-return-from-books` reads them.
- The yearly private-use correction for a car (bijtelling in the VAT return). A separate correction, not a split per cost.
- Foreign VAT rates (OSS), which `EuVatRate` already covers.

## Approach

Fix the seeder, make the calculator ask a small tariff resolver, and add
the split to the posting mappers `ledger-posting-path` builds. Details in
design.md.

## New Dependencies

None.

## Impact

- `lib/Service/SettingsService.php`: the seed file name.
- `lib/Service/VATCalculationService.php`: `VALID_RATES` removed; `lib/Service/Tax/VatTariffResolver.php` (new).
- `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` and `lib/Settings/shillinq_register.json`: one `VatTariff` shape.
- `Account`: `deductiblePercentage`, `privateShareAccountNumber`; cost lines of `JournalEntry` and `APTransaction`: `deductiblePercentage`.
- `MaterialiseGlTransactionAction` mappers (from `ledger-posting-path`): the split.
- `src/manifest.json`: pages `VatTariffs` and `VatTariffDetail` under Belastingen.

## Cross-Project Dependencies

- `ledger-posting-path` (open change in this repo): the posting mappers where the split is written.
- OpenRegister: `ObjectService` as it stands. No change needed there.

## Risks

### Risk 1: Invoices calculated with a rate that is not in the register
**Severity:** Medium. **Mitigation:** the tariff seed is checked at setup and on repair, and the calculator refuses a line whose tariff it cannot resolve instead of falling back to 21 percent.

### Risk 2: A split changes a posting someone expected in full
**Severity:** Low. **Mitigation:** the default percentage is 100 on every account until someone sets it, so nothing splits unasked.

## Rollback Strategy

Restore the constant in the calculator and ignore the new fields; lines then
post in full again. The seeded tariffs stay as records.

## Open Questions

None.
