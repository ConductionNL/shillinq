# bookkeeping-reconciliation-reports Specification (delta)

## Purpose

For an organisation that is both a customer and a supplier, every invoice sent
and received, the open amounts on both sides and the net position are shown
together. From shillinq matrix row `rep-relation-both-sides`.

## ADDED Requirements

### Requirement: A customer and a supplier record of one organisation are linked (REQ-RRBS-001)

Shillinq SHALL let a user link a `CustomerMaster` to the `Payee` of the same
organisation within one administration, at most one payee per customer. It SHALL
suggest pairs whose KvK number or VAT number are equal after normalisation, and
a suggestion MUST NOT become a link until a user confirms it; a dismissed
suggestion SHALL NOT be suggested again.

#### Scenario: A suggested pair is confirmed

- GIVEN customer and supplier records for Reclamebureau Zuid B.V. with KvK number 90000001 on both
- WHEN a bookkeeper opens the suggestions on the Relations both ways report and confirms the pair
- THEN the customer page of Reclamebureau Zuid B.V. shows it is linked to the supplier record, matched on KvK number, confirmed by that bookkeeper

### Requirement: Both detail pages show both sides of a linked relation (REQ-RRBS-002)

`CustomerDetail` and `PayeeDetail` of a linked relation SHALL show the invoices
sent to it and the invoices received from it, with invoiced sales, invoiced
purchases, open receivable, open payable and the net position (open receivable
minus open payable) for a chosen period. Credit notes SHALL count negative on
their side.

#### Scenario: The bookkeeper sees who owes whom

- GIVEN the linked relation Reclamebureau Zuid B.V. with sent invoices of EUR 4,235.00 paid and EUR 1,210.00 open, and a received invoice of EUR 2,662.00 open
- WHEN the bookkeeper opens the customer page and the Both sides section for 2026
- THEN it lists the three invoices and shows open receivable EUR 1,210.00, open payable EUR 2,662.00 and a net position of minus EUR 1,452.00

### Requirement: A report lists every relation that is both customer and supplier (REQ-RRBS-003)

The Reports page SHALL offer a Relations both ways report that lists, for a
chosen period, every linked relation of the administration with its invoiced
sales, invoiced purchases, open receivable, open payable and net position, and
SHALL export the list as CSV.

@e2e exclude the CSV body is asserted by RelationBothSidesServiceTest::testTheControllerExportsTheYear and RelationControllerTest::testTheReportAndItsCsv; a browser download adds nothing

#### Scenario: The controller exports the year

- GIVEN three linked relations in Drukkerij Van Wijk B.V.
- WHEN the controller opens Relations both ways for 2026 and exports it
- THEN the CSV has one row per relation with the five amounts

### Requirement: Each side is shown only to those who may read it (REQ-RRBS-004)

The both-sides view SHALL read each side's invoices with the caller's
permissions. A side the caller may not read MUST NOT be shown or counted, and the
view SHALL say that the side is not available to them.

@e2e exclude role-based sides are decided server-side and asserted by RelationControllerTest::testAReceivablesRoleSeesOnlyTheSalesSide and RelationBothSidesServiceTest::testASideTheCallerMayNotReadIsRestricted

#### Scenario: An AR controller without purchase access

- GIVEN a user with role ar-controller only
- WHEN they open the Both sides section of Reclamebureau Zuid B.V.
- THEN the sent invoices and open receivable are shown
- AND the received side says it is not available to them, and no purchase amount appears in the totals
