---
status: done
---

# Spec: usage-metered-billing

**Status:** done
**Scope:** shillinq
**OpenSpec changes:**
- `ar-billing-completeness` — adds `UsageRatePlan` + `MeterReading` schemas, the
  pure `UsageRatingCalculator`, and a `usage` billing model on the existing
  `InvoiceGenerationService` pipeline (REQ-UMB-001..004).

@e2e exclude pure backend/billing: meter rating and invoice-line generation are schema + service + pipeline behaviour exercised by PHPUnit (`tests/Unit/Service/UsageRatingCalculatorTest.php`, `tests/Unit/Service/MeteredInvoiceGenerationTest.php`) — not browser-testable.

## Purpose

Adds consumption-based (usage / metered) billing to shillinq, extending its
existing flat recurring (`RecurringInvoiceProfile`) and retainer billing. A
`MeterReading` captures a metered quantity of a resource over a period; a
`UsageRatePlan` prices it flat or by graduated tiers; `UsageRatingCalculator`
rates the reading, and the new `usage` billing model on the EXISTING
`InvoiceGenerationService.draftInvoice()` pipeline (via
`BillingModelEngine::calculateUsage`) turns rated readings into
`BillableInvoiceLine` rows and posts through the EXISTING `postInvoice()` GL
path. Invoicing is reused, never forked.

## Requirements

### Requirement: REQ-UMB-001: UsageRatePlan MUST price a metered resource flat or by graduated tiers

`UsageRatePlan` MUST declare `administrationId`, `name`, `resourceType`, `unit`
and `ratingMethod` (`flat` | `graduated`), plus `unitPriceCents` (flat) or a
`tiers` array of `{upTo, unitPriceCents}` (graduated, ascending, final `upTo`
null = unbounded), a `vatRate`, and an optional `glRevenueAccount`.

#### Scenario: UsageRatePlan declares the pricing contract
- **GIVEN** `lib/Settings/register.d/usage-metered-billing.json`
- **WHEN** the `UsageRatePlan` schema is inspected
- **THEN** `resourceType`, `unit`, `ratingMethod`, `tiers` and `vatRate` are declared, and the seed `objects` include a graduated `api_calls` plan

### Requirement: REQ-UMB-002: A metered quantity MUST rate to a cost in integer cents

`UsageRatingCalculator::rate(quantity, plan)` MUST return the cost in integer
cents. For `flat` it MUST be `round(quantity x unitPriceCents)`. For
`graduated` it MUST split the quantity across ascending tiers, pricing each
slice at its tier's `unitPriceCents`, with the null-`upTo` final tier catching
all remaining volume. Tiers supplied out of order MUST be normalised ascending
first.

#### Scenario: Graduated tiers price each slice at its own rate
- **GIVEN** a plan with tiers `[{upTo:1000,5},{upTo:10000,3},{upTo:null,2}]`
- **WHEN** a quantity of 12500 is rated
- **THEN** the cost is `1000x5 + 9000x3 + 2500x2 = 37000` cents

#### Scenario: Flat rating multiplies quantity by unit price
- **GIVEN** a plan with `ratingMethod: "flat"` and `unitPriceCents: 5`
- **WHEN** a quantity of 100 is rated
- **THEN** the cost is 500 cents

### Requirement: REQ-UMB-003: A rated MeterReading MUST land as a usage line on a BillableInvoice through the existing pipeline

`MeterReading` MUST declare `administrationId`, `customerId`, `resourceType`,
`quantity`, `periodStart` and `periodEnd`. The `usage` billing model on
`InvoiceGenerationService.draftInvoice()` MUST load the readings, resolve each
reading's `UsageRatePlan` (its own `ratePlanId`, else the request default),
rate it via `UsageRatingCalculator`, and emit one `BillableInvoiceLine` of
`sourceType: "usage"` per reading through the SAME VAT-totalling and
persistence path every other billing model uses — no separate invoicing code
path.

#### Scenario: A metered reading rates and lands on a drafted invoice
- **GIVEN** a 12500-call `MeterReading` linked to the graduated `api_calls` plan
- **WHEN** `draftInvoice()` is called with `billingModel: "usage"`
- **THEN** exactly one `BillableInvoiceLine` of `sourceType: "usage"` is persisted with `costAmount` €370.00 and `vatAmount` €77.70, and the `BillableInvoice.netAmount` is €370.00

### Requirement: REQ-UMB-004: A MeterReading MUST carry an unrated -> rated -> invoiced lifecycle

`MeterReading` MUST declare a `status` lifecycle of `unrated -> rated ->
invoiced` (with an `invoiceId` set on the `invoiced` state) so a reading is
never double-billed.

#### Scenario: MeterReading lifecycle is declared
- **GIVEN** the `MeterReading` schema
- **WHEN** its `x-openregister-lifecycle` is inspected
- **THEN** it declares states `unrated`, `rated` and `invoiced` on the `status` field

### Requirement: Readings are entered and rated on a page (REQ-USB-001)

The app SHALL list meter readings per customer and period, SHALL let a user
import readings from a CSV file with a refusal per invalid row, and SHALL let
a user rate selected readings against their rate plan.

#### Scenario: A bookkeeper imports a month of readings

- GIVEN a rate plan "Opslag per GB" at EUR 0.12 per GB
- WHEN the bookkeeper imports a CSV with 3 rows for customer Hosting Noord, one of them with quantity -5
- THEN the meter readings page shows 2 new unrated readings
- AND the import result names row 3 as refused because the quantity is negative

#### Scenario: Rating a reading shows its amount

- GIVEN an unrated reading of 250 GB on "Opslag per GB"
- WHEN the bookkeeper chooses rate on it
- THEN the reading shows status rated and an amount of EUR 30.00

### Requirement: Usage is a billing model in the invoice generator (REQ-USB-002)

The invoice generator SHALL offer usage as a billing model, SHALL list the
customer's rated readings in the period that are not invoiced, SHALL put the
selected readings on the draft invoice as usage lines, and SHALL mark each of
them invoiced so it is not billed again.

#### Scenario: A bookkeeper bills September usage

- GIVEN two rated readings for Hosting Noord in September 2026 worth EUR 30.00 and EUR 12.00
- WHEN the bookkeeper opens the invoice generator, chooses usage, the customer and September, and saves the draft
- THEN the draft invoice has two usage lines totalling EUR 42.00 before VAT
- AND both readings show status invoiced with that invoice

#### Scenario: An invoiced reading is not offered again

- GIVEN a reading already invoiced
- WHEN the bookkeeper generates another usage invoice for the same period
- THEN that reading is not listed

## Summary

Usage-metered billing lets shillinq bill consumption (API calls, kWh, storage)
alongside its flat recurring and retainer models, rating each metered reading
against a flat or graduated price book and feeding the result straight into the
existing invoice generation and GL-posting pipeline.

### Requirement: REQ-UMB-005: A closed billable period MUST become a draft invoice for the customer that carries its account reference

Shillinq SHALL answer `BillablePeriodClosedEvent` (sourceApp, externalReference, period
`YYYY-MM`, lines with description, quantity and unit price) by drafting one `BillableInvoice`
for the single `CustomerMaster` whose `externalReference` equals the event's reference, in that
customer's administration. Each line SHALL become a `MeterReading` rated by a flat
`UsageRatePlan` at the line's own unit price, and the invoice SHALL be drafted by
`InvoiceGenerationService::draftInvoice()` with the `usage` model. The same sourceApp,
reference and period with the same lines SHALL answer with the invoice already drafted; with
different lines it SHALL be refused. When no customer or more than one customer carries the
reference, or a line has no readable quantity or unit price, shillinq SHALL refuse on the event
with a reason and write nothing.

#### Scenario: A tenant month becomes a draft invoice

- **GIVEN** customer `cust-9001` in administration `adm-1` carries `externalReference` `t-42`
- **WHEN** dossiq raises the event for `t-42`, period `2026-09`, with two priced lines
- **THEN** shillinq SHALL draft one usage invoice for `cust-9001` in `adm-1` with two lines
- **AND** the event SHALL be accepted with that invoice's id and number

@e2e exclude A same-process event between two apps has no browser surface; the listener and service are covered in tests/Unit/Service/BillablePeriodInvoiceServiceTest.php and the live pass is task 1.5.

#### Scenario: The same month twice

- **GIVEN** the month above was drafted
- **WHEN** dossiq raises the same event again
- **THEN** the event SHALL be accepted with the same invoice and `duplicated: true`
- **AND** no second invoice SHALL be drafted

@e2e exclude A same-process event between two apps has no browser surface; the listener and service are covered in tests/Unit/Service/BillablePeriodInvoiceServiceTest.php and the live pass is task 1.5.

#### Scenario: No customer carries the tenant

- **GIVEN** no `CustomerMaster` carries `externalReference` `t-77`
- **WHEN** dossiq raises the event for `t-77`
- **THEN** the event SHALL be refused naming the reference
- **AND** nothing SHALL be written

@e2e exclude A same-process event between two apps has no browser surface; the listener and service are covered in tests/Unit/Service/BillablePeriodInvoiceServiceTest.php and the live pass is task 1.5.
