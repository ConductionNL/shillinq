---
kind: code
depends_on: [tax-vat-return-from-books, tax-digipoort-filing]
---

# Proposal: tax-vat-position-and-suppletie

## Summary

The VAT reports page adds up returns already made, and suppletie detection runs from nothing a user can reach. This change shows the running VAT position of the open period from the posted lines, box by box, and puts drift detection and the supplementary return on the filed return and the corrections page, so a bookkeeper sees what is owed before filing and corrects a filed period without leaving shillinq.

## Motivation

The build-all pass of 2026-09-29 decided `build` for these `building` rows of
the shillinq capability matrix (`openspec/parity/capabilities.json`), because
no change covered their missing half. Each row keeps `built.state: building`
with `built.change` naming this change until it ships.

**`tax-vat-position`**, "Check the running VAT position before you file." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "VATReports /vat-reports is a dashboard of summary widgets summing BtwAangifte.vatBalance (src/manifest.d/bookkeeping-vat-btw-filing.json); it sums returns already made, not the running position from the ledger"

- exact-online (yes): https://support.exactonline.com/community/s/article/All-All-HNO-Reference-financial-taxes-vat-fintax-vatchcklistr?language=en_GB: "You can use the VAT checklist before you create a VAT return ... The VAT overview is a report where you can check your VAT entries at any time in the VAT process"
- moneybird (yes): https://helpcenter.moneybird.nl/nl/articles/207263-btw-overzicht : 'houdt Moneybird de btw automatisch bij in een btw-rapport ... Per btw-tarief zie je het totaalbedrag ... en de btw die daarover is berekend' for any chosen period
- snelstart (yes): https://www.snelstart.nl/ondernemer/instap : the online dashboard shows 'hoeveel btw je moet betalen of ontvangt'; package table: 'Overzichtelijk financieel dashboard' (SnelStart Web)
- odoo (yes): https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting/tax_returns.html Review step shows calculated tax amounts and validation checks before Submit; Community path via OCA module l10n_nl_tax_statement (OCA/l10n-netherlands@19.0 l10n_nl_tax_statement/models/l10n_nl_vat_statement.py) Note: Tax report screen is Enterprise account_reports, rated from docs

**`tax-suppletie`**, "File a supplementary VAT return to correct an earlier period." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "lib/Service/VatSuppletieDetectionService.php detects corrections, called from VATReturnService and AansluitingService, neither reached from a page (no /api/vat-returns or /api/aansluitingen call under src/)"

- exact-online (yes): https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-taxes-vat-fintax-crtvatreturnt (Btw-aangiften en suppleties aanmaken, search snippet read today): "Klik op Suppletie aanmaken ... Aangifte definitief maken"
- moneybird (yes): https://helpcenter.moneybird.nl/nl/articles/223663-btw-correcties-suppletie : 'Via Moneybird kun je ook correcties en suppleties indienen ... Moneybird herkent automatisch correcties in je administratie'; corrections under EUR 1.000 go into the regular return
- snelstart (yes): https://kennisplein.snelstart.nl/klanten/s/article/btw-aangifte-algemene-informatie : 'Een tussentijdse suppletie ... Deze aangifte kun je net als de normale aangifte versturen naar de Belastingdienst' and 'Een jaarsuppletie'; https://www.snelstart.nl/nieuwinsnelstart/elektronisch-indienen-suppletie-aangifte (SnelStart 12.20)
- twinfield (yes): https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-aangifte-su-3041092 (Btw-aangifte Suppleties): "De Belastingdienst stelt verplicht om een suppletieaangifte te versturen als de omzetbelasting ... meer dan 1.000 in de btw bedraagt"; year suppletion also for fiscal units

## What is already built

- `VATReports` (`/vat-reports`, `src/manifest.d/bookkeeping-vat-btw-filing.json`) is a dashboard summing `BtwAangifte.vatBalance` over returns already made.
- `lib/Service/VatSuppletieDetectionService.php`: `detect($vatReturnId)` compares a filed return's snapshot with the ledger and writes a `VatCorrection` when it drifted (REQ-VBTW-013); `prepare($vatCorrectionId)` compiles per-box deltas, decides against the EUR 1,000 threshold and stages the correction posting (REQ-VBTW-014). Nothing outside the service calls them.
- `BtwCorrecties` (`/belastingen/btw-correcties`) lists `VatCorrection` records.
- The open change `tax-vat-return-from-books` stamps `vatReturnBox` and `vatAmountKind` on each posted `GLLine` and makes `VATReturnService` prepare from them; `tax-digipoort-filing` hands a validated instance to integriq.

## What this change adds

- A VAT position panel on `VATReports`: for the open period, per box, the base and VAT of the posted lines so far, the amount payable or refundable, and the lines behind a box on click. It reads the same stamped lines the return uses, so the position and the prepared return agree.
- "Check for corrections" on a filed return runs `detect`; "Prepare supplementary return" on a detected correction runs `prepare`; "File" hands the supplementary return over the Digipoort path, or downloads it while that change is not yet shipped.
- A background check after each period close runs `detect` on the returns of that fiscal year, so drift shows up on the corrections page without anyone asking.
