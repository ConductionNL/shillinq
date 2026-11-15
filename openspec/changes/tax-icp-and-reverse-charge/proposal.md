---
kind: code
depends_on: [tax-vat-return-from-books, tax-digipoort-filing]
---

# Proposal: tax-icp-and-reverse-charge

## Summary

Shillinq computes ICP supplies and can render a reverse-charge notice, but nothing on a screen calls either. This change puts the reverse-charge notice and the buyer's VAT number on every invoice whose line is reverse-charged, prepares the ICP statement of a period from the posted invoices on the ICP page, reconciles it with box 3b of the VAT return, and files it through the Digipoort path the VAT return uses.

## Motivation

The build-all pass of 2026-09-29 decided `build` for these `building` rows of
the shillinq capability matrix (`openspec/parity/capabilities.json`), because
no change covered their missing half. Each row keeps `built.state: building`
with `built.change` naming this change until it ships.

**`tax-icp`**, "Produce and file the ICP declaration of EU supplies." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "lib/Service/IcpService.php, IcpCalculator.php and IcpFilingService.php exist and ViesOutageRetryJob is registered in appinfo/info.xml, but /api/icp/* (appinfo/routes.php:479-485) is called by nothing under src/; filing depends on digipoort-sbr, unavailable in lib/Settings/connections.json; lib/Reporting/ReportCatalogue.php lists icp-opgaaf but lib/Reporting/Generator has no generator for it (ReportGenerationService.php:160 'no generator for report type')"

- exact-online (yes): https://www.exact.com/nl/producten/boekhouden/features-en-prijzen: "Btw/ICP-aangiftes" in Essentials; "Met Exact Online kun je je btw-aangifte en opgaaf ICP opstellen, goedkeuren en digitaal indienen"
- moneybird (yes): https://helpcenter.moneybird.nl/nl/articles/207677-icp-aangifte-doen-vanuit-moneybird : 'Het is binnen Moneybird mogelijk om direct de ICP-aangifte door te sturen naar de Belastingdienst', built from invoices with the EU reverse-charge rates
- snelstart (yes): package table https://www.snelstart.nl/ondernemer/inzicht : 'ICP-aangifte in één klik' from inKaart; https://www.snelstart.nl/ondernemer/inkaart : 'Je btw- en ICP-aangifte altijd up-to-date. Verstuur ze met enkele klikken'
- twinfield (yes): https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-aangifte-fi-3041056: "Opgaaf ICP voor fiscale eenheid"; https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-codes-3041086: "ICP : intracommunautaire prestaties, verkoop binnen EU, 0%"

**`tax-reverse-charge`**, "Apply reverse-charge VAT on cross-border business invoices." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "lib/Service/ArInvoiceIcpPdfRenderer.php renders the reverse-charge mention but is reached only by GET /api/icp/invoice-pdf, uncalled from src/; the BtwAangifte regime filter offers reverse-charge (src/manifest.d/bookkeeping-vat-btw-filing.json)"

- exact-online (yes): https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-masterdata-finmd-crtedtlnkvatcodest (search snippet read today): in the Financial section of a VAT code "Btw verlegd" can be ticked
- moneybird (yes): https://helpcenter.moneybird.nl/nl/articles/207104-btw-verleggen : rates 'Btw verlegd binnenland', 'Product binnen EU (btw verlegd)', 'Dienst binnen EU (btw verlegd)', 'Product buiten EU (btw verlegd)'; 'Moneybird voegt dit [btw-nummer] automatisch toe'
- snelstart (yes): https://kennisplein.snelstart.nl/snelstartpolaris/hoe-werkt-btw-verlegd : 'Vanaf het pakket inStap kun je factureren met btw-verlegd'; package table: 'Factureren binnen EU met buitenlandse btw' and 'Btw-verlegd factureren'
- twinfield (yes): https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/verkoopfactuur-3041176: "Btw verlegd: Hier wordt de factuurtekst van de btw-code getoond, als deze op de btw-code is ingesteld"
- odoo (yes): odoo/odoo@19.0 addons/l10n_nl/data/template/account.fiscal.position-nl.csv fiscal positions 'VAT reverse charge' and 'EU intra B2B'; verlegd taxes in addons/l10n_nl/data/template/account.tax-nl.csv (e.g. '21% BTW verlegd', 'BTW af te dragen verlegd (inkopen)'), reported on rubriek 2a/4b (addons/l10n_nl/data/account_tax_report_data.xml:149,:305)

## What is already built

- `lib/Service/IcpService.php` (supplies per period, periodicity check, reconcile with box 3b), `IcpCalculator.php` (aggregation per buyer VAT number and supply type, CSV and XML), `IcpFilingService.php` (corrections, inspection export), `ViesOutageRetryJob`. The endpoints `/api/icp/*` exist (`appinfo/routes.php`) but no page calls them.
- `IcpOpgaaf` page (`/belastingen/icp-opgaaf`) lists hand-kept `IcpStatement` records only. `ReportCatalogue` lists `icp-opgaaf` with no generator.
- `lib/Service/ArInvoiceIcpPdfRenderer.php` renders the reverse-charge notice and buyer VAT number (REQ-ICP-007), reached only by `GET /api/icp/invoice-pdf`. `OssInvoiceRouter` routes a B2B EU sale with a validated VAT number to the ICP path at 0%.
- The open change `tax-vat-return-from-books` stamps the VAT return box on each posted line; `tax-digipoort-filing` builds and hands over the VAT return XBRL and names the ICP statement over SBR as later work.

## What this change adds

- The ordinary invoice PDF and the UBL of an `ARInvoice` carry the reverse-charge notice, the buyer's VAT number and tax category K (EU) or AE (domestic reverse charge) when a line's tariff is reverse-charged.
- On the ICP page: "Prepare" for a period writes the `IcpStatement` from posted invoices through `IcpService`, shows the reconciliation with box 3b, and blocks finalising outside the EUR 1 tolerance (REQ-ICP-004).
- "File" builds the ICP XBRL instance on the ICP entry point through the `tax-digipoort-filing` path and hands it to integriq; the status follows integriq's answer.
