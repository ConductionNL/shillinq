# bookkeeping-sepa-direct-debit Specification (delta)

## Purpose

Direct debit collections reach the bank and the invoice. From shillinq
matrix row `rec-direct-debit`.

## ADDED Requirements

### Requirement: A batch is proposed from open invoices with a mandate (REQ-SDD-011)

The app SHALL propose, for a collection date, one collection per issued and
unpaid invoice paid by direct debit whose customer has an active mandate,
grouped in a batch per sequence type, and SHALL list each invoice it skipped
with the reason.

#### Scenario: A bookkeeper proposes the October collection

- GIVEN three open direct debit invoices, two for customers with an active mandate and one for a customer whose mandate was cancelled
- WHEN the bookkeeper proposes a batch for 2026-10-15 on the direct debit batches page
- THEN a draft batch with two collections and their total opens
- AND the third invoice is listed as skipped because the mandate is cancelled

### Requirement: The batch is written as a validated pain.008 file (REQ-SDD-012)

The app SHALL write a draft batch as a pain.008.001.02 file with the
administration's creditor identifier, SHALL validate it against the XSD
before storing it, and SHALL let the user download it.

#### Scenario: A bookkeeper downloads the file for the bank

- GIVEN a draft batch of two collections totalling EUR 1,250.00
- WHEN the bookkeeper chooses generate and then download
- THEN a pain.008 file downloads with two transactions and a control sum of 1250.00
- AND the batch shows state generated

#### Scenario: A file that fails the XSD is not stored

- GIVEN a collection whose mandate carries an IBAN with a wrong check digit
- WHEN the bookkeeper chooses generate
- THEN the batch stays draft and shows the validation error naming the mandate

### Requirement: A collection outcome settles or keeps the invoice (REQ-SDD-013)

The app SHALL register the payment on the invoice when its collection
succeeds, and SHALL keep the invoice open with the reject reason when the
collection is rejected.

#### Scenario: A successful collection pays the invoice

- GIVEN a submitted collection of EUR 500.00 for invoice F-2026-118
- WHEN the collection is marked succeeded
- THEN invoice F-2026-118 shows paid

#### Scenario: A rejected collection keeps the invoice open

- GIVEN a submitted collection for invoice F-2026-119
- WHEN it is rejected with reason AM04
- THEN the invoice stays open and the collection shows reason AM04
