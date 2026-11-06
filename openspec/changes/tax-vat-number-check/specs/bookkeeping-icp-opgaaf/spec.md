# bookkeeping-icp-opgaaf Specification (delta)

## Purpose

Customer and supplier VAT numbers are checked against VIES from their pages
and when a foreign supplier invoice arrives. From shillinq matrix row
`tax-vat-number-check`.

## ADDED Requirements

### Requirement: A person checks a VAT number from the record (REQ-TVNC-001)

The customer detail page and the supplier financial profile page SHALL offer
Check VAT number, which SHALL validate the record's VAT number through the
VIES service and show valid, invalid or not reachable with the date of the
last valid result.

#### Scenario: A bookkeeper checks a German customer

- GIVEN customer Kunstverlag Müller GmbH with a VAT number on the customer detail page
- WHEN the bookkeeper presses Check VAT number
- THEN the page shows the VIES result and the date it was checked

#### Scenario: VIES is down

- GIVEN a supplier whose number was valid ten days ago and VIES is unreachable
- WHEN the bookkeeper presses Check VAT number
- THEN the page shows not reachable and the last valid date ten days ago

### Requirement: Suppliers carry a checked VAT number (REQ-TVNC-002)

`VendorMaster` SHALL carry `vatId` with its validation status, date and
validity, and `SupplierInvoice` SHALL carry `sellerVatId`, filled from a UBL
invoice.

#### Scenario: A UBL invoice fills the seller's number

- GIVEN a UBL invoice from Softwarehuis BVBA with seller VAT number BE0000000000
- WHEN it is imported through Import bill
- THEN the supplier invoice shows seller VAT number BE0000000000

### Requirement: A foreign supplier's number is checked on arrival (REQ-TVNC-003)

When a supplier invoice with a seller VAT number from an EU country other
than the Netherlands is received, shillinq SHALL validate that number and
show the result on the invoice.

#### Scenario: A Belgian bill arrives

- GIVEN the imported invoice from Softwarehuis BVBA
- WHEN intake completes
- THEN the supplier invoice detail page shows the seller's VAT number as valid with today's date
