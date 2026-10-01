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


## As built (2026-10-02)

- **Rated amount.** MeterReading had no amount field, so "the reading shows EUR 30.00" had nowhere to live. Added `ratedAmount` (euros, nullable) and `RateMeterReadingAction` on the `rate` transition (`lib/Service/Usage/MeterReadingRating.php`, plan looked up in the reading's own administration; no plan refuses the transition).
- **Import.** `POST /api/meter-readings/import` takes parsed rows (the dialog reads the CSV in the browser, `src/utils/usageBilling.js`), checks membership of `administrationId` (404 otherwise), and finds the rate plan from the resource type when exactly one plan in the administration prices it.
- **Generator lookups were dead.** `InvoiceGenerationService::findScoped()` read every record through `findAll(['filters' => ['id' => ...]])`, which OpenRegister never matches, and kept only array rows while OpenRegister returns entities. Every usage, time and expense id resolved to nothing live, so a generated invoice had no lines. It now uses `find()` and checks the administration. `InMemoryObjectServiceStub` gained `idFiltersMatchNothing` so a test can see this.
- **Marking invoiced.** The generator refuses a reading that is not `rated` (an `invoiced` one with `Conflict:`, answered 409) before anything is saved, then patches `invoiceId` and runs the `invoice` transition per billed reading.
- **Pages.** `src/manifest.d/sales-usage-billing.json`: Meter readings (index with filters, Import readings header action, Rate readings bulk action, detail with lifecycle actions) and Rate plans (index, detail), relocated under Sales in `src/menu-layout.json`. The bulk action is labelled "Rate readings" because the catalogue key "Rate" already means "Tarief".
