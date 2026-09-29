# Design: sales-usage-billing

Read at shillinq `feat/ledger-booking-rules` @6ee1421d9 on 2026-09-29.

## Context

- `InvoiceGenerator.vue` offers `t_and_m`, `fixed_fee`, `milestone`, `retainer` and `mixed`; the server already accepts `usage` (`InvoiceGenerationService.php:459`).
- A reading is rated by the `rate` transition of `MeterReading`.

## Decisions

### D1. Pages are manifest pages

`src/manifest.d/usage-metered-billing.json`: `UsageRatePlans` index and detail, `MeterReadings` index (filters customer, period, status) with a bulk action "Rate" and an import action.

### D2. CSV import through one endpoint

`POST /api/meter-readings/import` (`#[NoAdminRequired]`, administration checked) takes rows `meterId, customerId, resourceType, quantity, unit, periodStart, periodEnd`, validates each against the `MeterReading` fragment, and returns created and refused rows with the reason. `lib/Service/Usage/MeterReadingImportService.php`.

### D3. Generator

The usage option loads `MeterReading` with `status: rated`, the chosen customer and a `periodEnd` inside the invoice period, shows them with their rated amount, and passes the selected ids as `meterReadingIds`.

### D4. Marking invoiced

`InvoiceGenerationService::draftInvoice` patches each billed reading to `invoiced` with the new invoice id after the invoice is saved (`patchObject`). A reading already invoiced is refused when it is passed again.

