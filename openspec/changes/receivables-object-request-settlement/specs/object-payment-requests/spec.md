# object-payment-requests Specification (delta)

## Purpose

A request on an object carries its own transfer reference, is matched from the bank statement, sends the debtor a receipt and can have an invoice behind it. Requested by larpinq's `registration-payments-through-shillinq`.

## ADDED Requirements

### Requirement: An event fee is a request type (REQ-ORS-001)

`PaymentRequest.requestType` SHALL accept `event-fee`, and a capture of an `event-fee` request SHALL book against the revenue account the `paymentRevenueAccounts` mapping gives for `event-fee`.

#### Scenario: Larpinq asks for an event fee

- GIVEN an administrator who mapped `event-fee` to revenue account 8100
- WHEN larpinq creates a request through the `shillinq-payment-requests` leaf with `requestType: event-fee` and amount 85.00 for Anna's registration
- THEN the request is saved as `pending`
- AND its capture books 85.00 against account 8100

### Requirement: A request carries a unique transfer reference (REQ-ORS-002)

The leaf's `create` SHALL accept `paymentReference` and store it on the request. Validation SHALL refuse a reference of fewer than 6 characters and a reference that a `pending` or `authorized` request already carries.

#### Scenario: A reference in use is refused

- GIVEN a pending request with reference `WC26-0042`
- WHEN larpinq creates another request with reference `WC26-0042`
- THEN the leaf refuses it and names the existing request

### Requirement: A bank line that quotes a reference is matched to the request (REQ-ORS-003)

When a `BankStatementLine` is saved whose `reference` or `remittanceInfo` contains a pending object request's `paymentReference` as a whole token, shillinq SHALL create a `ReconciliationMatch` with `targetType` `payment-request`. The match SHALL be confirmed at once only when exactly one request matches and the line's amount equals the amount still open; otherwise it SHALL stay a `candidate`.

#### Scenario: A transfer with the reference and the full amount is matched

- GIVEN a pending request `WC26-0042` for 85.00
- WHEN a bookkeeper imports a CAMT.053 statement with a line of 85.00 whose remittance reads "Winter Court WC26-0042 Anna"
- THEN a confirmed `payment-request` match links the line to the request

#### Scenario: A transfer for less waits for the bookkeeper

- GIVEN the same request
- WHEN the line is 50.00
- THEN the match is a `candidate` on the unmatched items page and the request stays `pending`

### Requirement: A confirmed bank match settles the request once (REQ-ORS-004)

On a confirmed `payment-request` match, shillinq SHALL append a `bank-transfer` settlement with the line's amount and reference, set `settledAt`, and book the receipt with the bank account on the debit side. When the request has an `invoiceReference`, it SHALL settle that invoice instead and book nothing on the object.

#### Scenario: Larpinq sees the registration paid

- GIVEN the confirmed match from REQ-ORS-003
- WHEN larpinq's listener reads the request's object event
- THEN the request shows `settledVia` `bank-transfer` and a `settledAt`
- AND the ledger holds one receipt of 85.00 for it

### Requirement: The debtor gets one receipt mail (REQ-ORS-005)

When `settledAt` is first set on a request with `subjectKind` `object` and a known `debtor.email`, shillinq SHALL mail the debtor the description, the amount, the date, the reference and the confirmation summary, and SHALL record `receiptSentAt`. A request with `receiptSentAt` set SHALL NOT be mailed again.

#### Scenario: Anna gets a receipt after paying online

- GIVEN Anna's pending request with her email address
- WHEN the payment provider reports it captured
- THEN Anna receives one mail naming "Winter Court 2026", 85.00 and `WC26-0042`
- AND a replay of the same provider event sends no second mail

### Requirement: An invoice asked for at create stands behind the request (REQ-ORS-006)

When the leaf's `create` receives `invoiceRequested: true`, shillinq SHALL issue one `ARInvoice` to the debtor's `CustomerMaster` for the request's description and amount and SHALL set the request's `invoiceReference`, so a capture settles the invoice and books the income once.

#### Scenario: A player who asked for an invoice gets one

- GIVEN Sanne's registration with "Request an invoice" chosen
- WHEN larpinq creates her request with `invoiceRequested: true`
- THEN an issued invoice for 85.00 exists for Sanne
- AND the request's `invoiceReference` names it
