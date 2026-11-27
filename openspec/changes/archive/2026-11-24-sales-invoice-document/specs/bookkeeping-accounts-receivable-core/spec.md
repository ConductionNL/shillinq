# bookkeeping-accounts-receivable-core Specification (delta)

## Purpose

The invoice document carries the sender's branding, the customer's language,
the invoice currency and a scan-to-pay code. From shillinq matrix rows
`sal-branded-invoice`, `sal-qr`, `sal-foreign-currency` and
`sal-customer-language`.

## ADDED Requirements

### Requirement: An administration sets its invoice layout (REQ-SID-001)

Shillinq SHALL keep one `InvoiceLayout` per administration with a logo, a
primary and an accent colour, a layout variant and footer text, edited on an
invoice layout settings page with a preview, and SHALL refuse a primary
colour below a 4.5:1 contrast ratio against white.

#### Scenario: An office sets its house style

- GIVEN an administrator on the invoice layout settings page of Adviesbureau Van Dijk
- WHEN they upload the logo, choose primary colour #1B4F72 and variant modern and save
- THEN the preview shows the logo and the colour
- AND the next invoice PDF shows them too

### Requirement: The invoice is rendered through docudesk (REQ-SID-002)

The invoice PDF SHALL be rendered by docudesk from the `sales-invoice`
template with the administration's layout. When docudesk is absent, the
invoice page SHALL say so instead of producing a file.

#### Scenario: Docudesk is not installed

- GIVEN an instance without docudesk
- WHEN a bookkeeper asks for the PDF of an issued invoice on the AR invoice detail page
- THEN the page states that docudesk is needed to produce invoice documents

### Requirement: The invoice speaks the customer's language (REQ-SID-003)

`CustomerMaster` SHALL carry a `language` (nl, en, de, fr). The invoice
document SHALL use that language for its labels and number formatting, and
the invoice SHALL record it for the invoice email.

#### Scenario: An English customer gets an English invoice

- GIVEN customer Brightside Consulting Ltd with language en
- WHEN its invoice PDF is produced
- THEN the labels read "Invoice", "Due date" and "Total", and amounts use English formatting

### Requirement: The invoice shows its own currency (REQ-SID-004)

The invoice document SHALL show every amount in the invoice currency, and
the time-and-expense generator SHALL set the currency from the order or the
customer instead of fixing it to EUR.

#### Scenario: A pound invoice

- GIVEN Brightside Consulting Ltd with currency GBP
- WHEN an invoice of 40 hours at GBP 120 is generated from logged time
- THEN the invoice and its PDF show GBP 4,800 and no euro sign

### Requirement: The invoice carries a scan-to-pay code (REQ-SID-005)

When the layout shows QR codes, a EUR invoice with an administration IBAN
SHALL carry an EPC SEPA transfer QR for its open amount with the invoice
number as reference, and an invoice with a payment link SHALL carry a QR of
that link.

#### Scenario: A municipality pays by scanning

- GIVEN issued invoice 2026-0042 of EUR 24,200 to Gemeente Voorbeeld
- WHEN its PDF is produced
- THEN it shows an EPC QR whose payload names amount EUR 24200.00 and reference 2026-0042
