# shillinq-invoice-quick-draft Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- arinvoice-lines-and-portal-amounts

## Purpose

The quick draft (schema:Invoice, `ARInvoice`) must save the lines the user typed.
OpenRegister drops a property the schema does not declare, and `ARInvoice`
declares its lines as `invoiceLines` in the EN 16931 BG-25 shape.

## ADDED Requirements

### Requirement: The quick draft saves its lines as invoiceLines (REQ-IQD-006)

`buildInvoicePayload()` SHALL write the draft's lines to `invoiceLines`, each with
`lineId` (the position as a string), `itemName` (the trimmed description),
`quantity`, `unitCode` `C62`, `netPrice` (the unit price), `netAmount` (quantity
times unit price, rounded to cents), `vatRate` and `vatCategory` (`S` above zero,
`Z` at zero). It SHALL NOT write `lines`. Empty lines SHALL still be dropped.

#### Scenario: A consultancy drafts two hours of work

- GIVEN a draft line "Consulting", quantity 2, unit price 100, VAT 21, and an empty line
- WHEN the payload is built
- THEN `invoiceLines` holds one line with `lineId` "1", `itemName` "Consulting", `netPrice` 100, `netAmount` 200, `vatRate` 21, `vatCategory` S and `unitCode` C62
- AND the payload has no `lines` key, and the totals are net 200, VAT 42, gross 242
- @e2e exclude payload builder; covered by `tests/vitest/invoiceQuickDraft.spec.js` "builds a draft ARInvoice whose lines are the declared invoiceLines"
