---
kind: code
depends_on: [ledger-posting-path, tax-vat-rates-and-deductibility]
---

# Proposal: tax-vat-return-from-books

## Summary

Shillinq has three ways to arrive at a VAT return and none of them is the
books as booked. The report file takes output tax from sales invoices and
input tax only from a hand-kept filing record; the return service scans the
ledger but recalculates VAT from each account's rate instead of reading the
VAT that was posted; and the declarative aggregation on the other return
schema joins through fields that do not exist. This change makes one path:
every posted line carries its VAT return box, the return is prepared from
those lines, output and input alike, checked before filing, corrected per
fiscal year when the year is broken, and filed once for a fiscal unity.

## Motivation

Four rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the VAT return. The OpenSpec
pass of 2026-09-27 decided `build` for all four. This change covers all
four.

**`tax-vat-prepare`**, "Have the VAT return filled in from the books."
Rated partial, built. The matrix evidence:
"lib/Reporting/Generator/VatReturnReportGenerator.php renders the return
XML by rubriek from a stored VatReturnFiling or, failing that, from the
period's ARInvoice rows (output tax only, input tax only from the filing
record)". The note: "The file can be produced from sales invoices; input
VAT from purchases is not derived, and the VATReturns page totals rest on
x-openregister-aggregations. Its own spec REQ-VBTW-004 forbids this
derivation (issue #525)." No demand row. All five competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Met Exact Online kun je je btw-aangifte en opgaaf ICP opstellen, goedkeuren en digitaal indienen".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207651-btw-aangifte-in-moneybird, "De btw wordt berekend aan de hand van de inkoop- en verkoopfacturen".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/btw-aangifte-algemene-informatie, "de btw-aangifte berekenen en direct versturen naar de Belastingdienst".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-aangifte-3041090, "Alle velden worden automatisch berekend en gevuld op basis van de gekozen btw-codes".
- odoo: odoo/odoo@19.0 `addons/l10n_nl/data/account_tax_report_data.xml:3`, the Dutch tax report with rubrieken 1a to 5 fed by tax tags.

**`tax-return-checks`**, "Run automatic checks on the VAT return and see
which ones fail before filing." Rated no, built state none. The note: "VAT
checks run at invoice issuance (lib/Lifecycle/VatPreconditionGuard.php),
not as a check list on the return before filing." Demand from the odoo
changelog https://www.odoo.com/odoo-19-release-notes. Two competitors rate
it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Reference-financial-taxes-vat-fintax-vatchcklistr?language=en_GB, "You can use the VAT checklist before you create a VAT return to ensure that there are no inconsistencies".
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting/tax_returns.html, "Validation checks (shown in red/green status)".

**`tax-broken-year-vat`**, "Produce VAT returns and corrections for a
fiscal year that does not follow the calendar year." Rated partial, built:
"The administration record carries nonCalendarFiscalYear
(lib/Settings/register.d/bookkeeping-multi-administratie.json:145) ... VAT
returns stay on calendar periods and no screen groups corrections by the
broken year." Demand from the twinfield release notes
https://taasupportportal.wolterskluwer.com/nl/nl-nl/release-notes/twinfield-boekh-3041276.
Three competitors rate it yes:

- snelstart: https://www.snelstart.nl/ondernemer/inzicht, "Werken met gebroken boekjaren", with the year suppletie covering the fiscal year (https://kennisplein.snelstart.nl/klanten/s/article/btw-aangifte-algemene-informatie).
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/release-notes/twinfield-boekh-3041276, "om hiervoor de nodige btw-aangiften en suppleties te kunnen genereren".
- odoo: odoo/odoo@19.0 `addons/account/models/company.py:74` `fiscalyear_last_day`, and https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting/tax_returns.html.

**`tax-fiscal-unity`**, "File one VAT return for a fiscal unity of several
companies." Rated no, built state none. The note: "FiscaleEenheid exists
for corporate income tax (lib/Lifecycle/VpbAangifteGuard.php:185), and no
manifest page names it; no combined VAT return for a fiscal unity." Demand
from the snelstart roadmap
https://www.snelstart.nl/boekhouders-en-accountants/functies/fiscaal. Two
competitors rate it yes:

- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/een-fiscale-een-3041080, "kun je de btw-aangifte centraal versturen naar de Belastingdienst".
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/tax_units.html, a tax unit "files one consolidated VAT return".

## Affected Projects

- [ ] Project: `shillinq`: the posting mappers, the VAT return service and its report generator, the return pages, a check provider and guard, the corrections page, and one new schema for a VAT fiscal unity.

## Scope

### In Scope

- Stamping each posted line with its VAT return box and whether it is a base or a VAT amount, from its tariff.
- Preparing the return from those booked lines, output and input tax, as a stored snapshot the page, the file and the correction check all read.
- Checks on the return with pass or fail per check, blocking submission on a failing blocking check.
- Grouping corrections, and the correction threshold, by the fiscal year when it is not the calendar year.
- A VAT fiscal unity of administrations with one return filed by the representative administration.
- `bookkeeping-vat-btw-filing` REQ-VBTW-004 is modified to say the above.

### Out of Scope

- Sending the return to the Belastingdienst. That is `tax-digipoort-filing`.
- The ICP statement, which has its own register (REQ-VBTW-007).
- Merging the two return schemas; this change picks the one with the engine and retires the other's menu entry only.

## Approach

One derivation, from booked lines, persisted per return. Details and the
choice between the three existing paths are in design.md.

## New Dependencies

None.

## Impact

- The posting mappers of `ledger-posting-path`: `vatTariffCode`, `vatReturnBox` and `vatAmountKind` on each line with a tariff.
- `lib/Service/VATReturnService.php`: derivation from stamped lines; fiscal unity members.
- `lib/Reporting/Generator/VatReturnReportGenerator.php`: renders from the return snapshot.
- `lib/Standards/Checks/VatReturnChecks.php` and `lib/Guard/VatReturnChecksGuard.php` (new).
- `lib/Settings/register.d/bookkeeping-vat-btw-filing.json`: `returnBox` on `VATDeclaration` and `VATLine`, `requires` on `BtwAangifte.submit`, a new `VatFiscalUnity` schema.
- `src/manifest.d/bookkeeping-vat-btw-filing.json` and `src/manifest.json`: a "Prepare return" action, a checks tab, the corrections grouping, the Taxes menu entry.

## Cross-Project Dependencies

- `ledger-posting-path` (open change in this repo): the mappers that stamp the box.
- `tax-vat-rates-and-deductibility` (open change in this repo): the tariff per line and its `section`, which is the return box.
- OpenRegister: `ObjectService` and the lifecycle engine as they stand. No change needed there.

## Risks

### Risk 1: Returns change their totals compared with what the service showed before
**Severity:** Medium. **Mitigation:** the old service recalculated VAT from rates; the new one reads what was booked. The first prepared return shows both totals for one release, and the release note says why they may differ.

### Risk 2: Lines posted before this change carry no box
**Severity:** Medium. **Mitigation:** a backfill resolves the box for existing posted lines from their tariff code or, failing that, the account's VAT settings, and lists the lines it could not resolve as a failing check.

### Risk 3: A member of a fiscal unity files its own return too
**Severity:** High. **Mitigation:** preparing a return for a member administration in a period the unity covers is refused and names the unity.

## Rollback Strategy

Restore the derivation in `VATReturnService` and the generator's invoice
path. Stamped fields stay on lines and are ignored.

## Open Questions

None.
