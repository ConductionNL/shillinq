# bookkeeping-einvoicing-ubl-peppol Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- arinvoice-lines-and-portal-amounts

## Purpose

The hybrid PDF of an e-invoice (schema:Invoice) prints the invoice's lines.
`EInvoiceService` hands the PDF generator `ARInvoice.invoiceLines`; the generator
read only the BillableInvoiceLine keys, so every line printed blank and zero.

## ADDED Requirements

### Requirement: REQ-EINV-009: The hybrid PDF SHALL print the ARInvoice's own lines

`InvoicePdfGenerator` SHALL read a line in either shape: the BillableInvoiceLine
keys (`lineNumber`, `description`, `billableUnits`, `rateApplied.rateCents`,
`costAmount`) first, and the `invoiceLines` keys (`lineId`, `itemName`,
`quantity`, `netPrice`, `netAmount`) when those are absent. A BillableInvoice PDF
SHALL render exactly as before.

#### Scenario: An e-invoice PDF shows the consultancy line

- GIVEN an ARInvoice line `lineId` "1", `itemName` "Consulting", `quantity` 2, `netPrice` 100, `netAmount` 200, `vatRate` 21
- WHEN the hybrid PDF is generated
- THEN its HTML row shows 1, Consulting, 2, € 100,00, € 200,00 and 21%
- @e2e exclude document generation; covered by `InvoicePdfGeneratorTest::testTheHybridPdfPrintsTheArInvoiceLines`

#### Scenario: A time-and-expense PDF is unchanged

- GIVEN a BillableInvoiceLine with `description`, `billableUnits` and `costAmount`
- WHEN the PDF is generated
- THEN the row is the same as before this change
- @e2e exclude document generation; covered by the existing `InvoicePdfGeneratorTest` cases
