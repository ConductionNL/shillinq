# bookkeeping-einvoicing-ubl-peppol Specification

## ADDED Requirements

### Requirement: REQ-EINV-010: The NLCIUS document SHALL carry the customer's reference as the buyer reference (BT-10)

`ArInvoiceUblMapper::toNlciusXml()` SHALL write the ARInvoice's `customerReference`
as `cbc:BuyerReference`, escaped, directly after `cbc:DocumentCurrencyCode` (the
UBL 2.1 element order). A blank reference SHALL write no element.

#### Scenario: The reference is the buyer reference

- GIVEN an issued invoice with customer reference `PO-4711 & co`
- WHEN it is rendered
- THEN the document holds `<cbc:BuyerReference>PO-4711 &amp; co</cbc:BuyerReference>` after the document currency
- @e2e exclude XML mapper; covered by `ArInvoiceUblMapperTest::testCustomerReferenceIsTheBuyerReference`

#### Scenario: No reference, no element

- GIVEN an issued invoice whose customer reference is blank
- WHEN it is rendered
- THEN the document holds no `BuyerReference`
- @e2e exclude XML mapper; covered by `ArInvoiceUblMapperTest::testNoCustomerReferenceWritesNoBuyerReference`
