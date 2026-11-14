# Design: tax-icp-and-reverse-charge

Read at shillinq `feat/ledger-booking-rules` @6ee1421d9 on 2026-09-29.

## Context

- `InvoicePdfGenerator` renders the ordinary invoice; `ArInvoiceUblMapper` maps the UBL. Neither reads the reverse-charge state today.
- Box 3b is the VAT return box for EU supplies; after `tax-vat-return-from-books` the box is on each posted line.

## Decisions

### D1. One notice, two outputs

A new `lib/Service/Tax/ReverseChargeNotice.php::forInvoice(array $invoice, array $customer): ?array` returns the notice text key, the buyer VAT number and the tax category, or null. `InvoicePdfGenerator` and `ArInvoiceUblMapper` both call it; `ArInvoiceIcpPdfRenderer` keeps its endpoint but delegates the notice to it. An invoice with a reverse-charged line and no buyer VAT number is refused at `issue` by a guard, naming the missing number.

### D2. Prepare and reconcile on the page

`POST /api/icp/statements/prepare` (`period`, `administrationId`) calls `IcpService::suppliesInPeriod` and `IcpCalculator` and writes or replaces the draft `IcpStatement` with its lines; a filed statement is never replaced (corrections go through `IcpFilingService::createCorrection`, REQ-ICP-008). The page's detail shows the lines and the `reconcile` result.

### D3. Generator and filing

`lib/Reporting/Generator/IcpReportGenerator.php` serves the `icp-opgaaf` report type. Filing reuses the `XbrlInstance` builder and hand-off of `tax-digipoort-filing` with the ICP entry point. Until that change ships, "File" is hidden and the XML can be downloaded.

## Dependencies

`tax-vat-return-from-books` (box 3b per line) and `tax-digipoort-filing` (instance and hand-off).

