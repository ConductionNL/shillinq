# recurring-invoicing Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- arinvoice-lines-and-portal-amounts

## Purpose

A generated invoice (schema:Invoice, `ARInvoice`) must carry the profile's lines.
`ARInvoice` declares them as `invoiceLines`; the generator wrote `lines`, which
OpenRegister drops.

## ADDED Requirements

### Requirement: REQ-RIN-009: A generated invoice SHALL carry its lines as invoiceLines

`RecurringInvoiceGenerator::buildArInvoicePayload()` SHALL write each profile line
to `invoiceLines` with `lineId`, `itemName` (the description with its period
tokens expanded), `quantity`, `unitCode` `C62`, `netPrice`, `netAmount`,
`vatRate` and `vatCategory` (`S` above zero, `Z` at zero), and SHALL NOT write
`lines`. The totals SHALL stay as they are.

#### Scenario: A monthly retainer invoice keeps its line

- GIVEN a profile line "Retainer {month}", quantity 1, unit price 500, VAT 21, for period 2026-10
- WHEN the payload is built in Dutch
- THEN `invoiceLines[0]` has `itemName` "Retainer oktober", `netPrice` 500, `netAmount` 500, `vatRate` 21 and `vatCategory` S
- AND the payload has no `lines` key, and net 500, VAT 105, gross 605
- @e2e exclude payload builder; covered by `RecurringInvoiceGeneratorTest::testTheGeneratedInvoiceCarriesItsLinesAsInvoiceLines`
