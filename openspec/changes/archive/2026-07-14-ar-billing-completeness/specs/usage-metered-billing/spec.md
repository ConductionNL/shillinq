# usage-metered-billing Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- ar-billing-completeness

@e2e exclude pure backend/billing: meter rating and invoice-line generation are schema + service + pipeline behaviour, exercised by PHPUnit — not browser-testable

## Purpose

Adds consumption-based (usage / metered) billing to shillinq, extending its
existing flat recurring (`RecurringInvoiceProfile`) and retainer
(`invoice-from-time-and-expense` retainer model) billing. A `MeterReading`
captures a metered quantity of a resource over a period; a `UsageRatePlan`
prices it either flat (`quantity x unitPriceCents`) or with graduated tiers;
`OCA\Shillinq\Service\UsageRatingCalculator` rates the reading, and the new
`usage` billing model on the EXISTING `InvoiceGenerationService.draftInvoice()`
pipeline (via `BillingModelEngine::calculateUsage`) turns rated readings into
`BillableInvoiceLine` rows and posts through the EXISTING `postInvoice()` GL
path. Invoicing is reused, never forked.

## ADDED Requirements

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
