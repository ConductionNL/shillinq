# bookkeeping-icp-opgaaf Specification (delta)

## Purpose

Reverse charge shows on every invoice it applies to, and the ICP statement
is prepared and filed from the books. From shillinq matrix rows `tax-icp`
and `tax-reverse-charge`.

## ADDED Requirements

### Requirement: A reverse-charged invoice says so (REQ-ICR-001)

Every invoice with a reverse-charged line SHALL show the reverse-charge
notice and the buyer's VAT number on its PDF and SHALL carry tax category K
for an EU supply or AE for a domestic reverse charge in its UBL, and the app
SHALL refuse to issue such an invoice without the buyer's VAT number.

#### Scenario: A bookkeeper issues an invoice to a Belgian business

- GIVEN a draft invoice to Brouwerij Het Anker BV with VAT number BE0123456749 and a line with the tariff "levering binnen de EU, btw verlegd"
- WHEN the bookkeeper issues it and downloads the PDF
- THEN the PDF shows the notice "Btw verlegd" and the number BE0123456749
- AND the UBL line carries tax category K at 0%

#### Scenario: A missing VAT number is refused

- GIVEN the same invoice for a customer without a VAT number
- WHEN the bookkeeper issues it
- THEN the app refuses and says the buyer's VAT number is required for reverse charge

### Requirement: The ICP statement is prepared from the books (REQ-ICR-002)

The ICP page SHALL prepare the statement of a period from the posted
invoices, one line per buyer VAT number and supply type, SHALL show its
reconciliation with box 3b of the VAT return of that period, and SHALL
never replace a statement that was filed.

#### Scenario: A bookkeeper prepares the third quarter

- GIVEN posted EU supplies in Q3 2026 of EUR 12,000 to BE0123456749 and EUR 3,000 to DE123456789
- WHEN the bookkeeper chooses prepare for 2026-Q3 on the ICP page
- THEN a draft statement shows two lines of EUR 12,000 and EUR 3,000
- AND the reconciliation shows box 3b of the Q3 return and the difference

### Requirement: The ICP statement is filed through Digipoort (REQ-ICR-003)

The app SHALL file a finalised ICP statement as an XBRL instance through the
same Digipoort hand-off as the VAT return, and SHALL show submitted,
accepted or rejected only as Digipoort answers.

#### Scenario: A bookkeeper files the statement

- GIVEN a finalised ICP statement for 2026-Q3 that reconciles within EUR 1
- WHEN the bookkeeper chooses file
- THEN the statement shows submitted
- AND it shows accepted once Digipoort accepts it
