---
kind: code
depends_on: []
---

# Proposal: sales-usage-billing

## Summary

Shillinq can rate metered usage and put it on an invoice, but no screen reaches it. This change gives rate plans and meter readings their pages, lets a user enter or import readings, and adds usage as a billing model to the invoice generator, so a customer is billed for what they used.

## Motivation

The build-all pass of 2026-09-29 decided `build` for these `building` rows of
the shillinq capability matrix (`openspec/parity/capabilities.json`), because
no change covered their missing half. Each row keeps `built.state: building`
with `built.change` naming this change until it ships.

**`sal-usage-billing`**, "Bill a customer per unit of usage they consumed." Shillinq rated no, built state `building`. The matrix evidence for the built half: "UsageRatePlan and MeterReading schemas (lib/Settings/register.d/usage-metered-billing.json) and lib/Service/UsageRatingCalculator.php + BillingModelEngine.php:299-326 exist, but no manifest page uses either schema and src/components/invoice/InvoiceGenerator.vue:25-37 offers no usage billing model nor meter-reading input"


## What is already built

- `UsageRatePlan` and `MeterReading` with the unrated, rated, invoiced lifecycle (`lib/Settings/register.d/usage-metered-billing.json`, spec `usage-metered-billing`, REQ-UMB-001 to 004).
- `lib/Service/UsageRatingCalculator.php` (flat and graduated rating in cents) and `BillingModelEngine::calculateUsage`.
- `InvoiceGenerationService::draftInvoice` already handles `billingModel: usage` with `meterReadingIds` and `usageRatePlanId`.

## What this change adds

- `UsageRatePlans` and `MeterReadings` pages (under Sales), with a CSV import of readings.
- A "Usage" option in `src/components/invoice/InvoiceGenerator.vue` that lists the customer's rated, not yet invoiced readings in the period and sends their ids.
- After the draft invoice is saved, each reading it billed moves to `invoiced` with `invoiceId` set, so it is never billed twice.
