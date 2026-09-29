# payment-control-guards Specification (delta)

## Purpose

A supplier invoice that names a different bank account than the supplier
record is flagged and held from payment. From shillinq matrix row
`pur-iban-check`.

## ADDED Requirements

### Requirement: An invoice IBAN that differs from the supplier record holds payment (REQ-PSII-004)

When a supplier invoice names an IBAN that differs, after normalising
spaces and case, from the payee's bank account or the payee's supplier
qualification, shillinq SHALL show both IBANs as a warning on the invoice,
SHALL require a reason before the invoice is booked, and SHALL write the
resulting AP transaction as payment blocked with the reason "IBAN on invoice
differs from supplier record". An invoice without an IBAN SHALL NOT be
flagged.

#### Scenario: A changed IBAN is caught before payment

- GIVEN payee Drukkerij Van der Meer B.V. with IBAN NL20INGB0001234567
- WHEN UBL invoice 2026-0456 from that payee naming IBAN NL02ABNA0123456789 is imported
- THEN the invoice page warns that NL02ABNA0123456789 differs from NL20INGB0001234567
- AND after booking with a reason, its AP transaction shows payment blocked with that reason

#### Scenario: The same IBAN written with spaces is not a mismatch

- GIVEN the same payee
- WHEN an invoice names IBAN NL20 INGB 0001 2345 67
- THEN no IBAN warning is shown
