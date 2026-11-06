# recurring-invoicing Specification

## ADDED Requirements

### Requirement: REQ-RIN-010: A generated invoice SHALL write only declared fields and carry its number and period

ARInvoice SHALL declare `recurringProfileId` and `billingPeriod`, so the
double-billing guard of REQ-RIN-004 filters on fields OpenRegister keeps. The
payload SHALL carry `periodId` (the billing period, `YYYY-MM`) and an
`invoiceNumber` `REC-<yyyymm>-<first 8 of the profile id, upper case>-<nn>`, where
`nn` is one more than the invoices of that profile and period already present. Each
line SHALL carry the profile's `revenueAccount` as `glAccount`. Every payload key
and every line key SHALL be a declared ARInvoice property.

#### Scenario: The payload writes only declared fields

- GIVEN a profile with a revenue account and one line, for period 2026-10
- WHEN the payload is built
- THEN every key is declared on the effective ARInvoice and every line key on its lines
- AND `periodId` is `2026-10`, `invoiceNumber` starts `REC-202610-` and each line's `glAccount` is the revenue account
- @e2e exclude payload builder; covered by `BillingPayloadDeclaredFieldsTest`

#### Scenario: A regenerated invoice after a cancellation gets its own number

- GIVEN a cancelled invoice for the profile and period
- WHEN the period is generated again
- THEN the new invoice's number ends in `-02`
- @e2e exclude generator; covered by `RecurringInvoiceGeneratorTest`
