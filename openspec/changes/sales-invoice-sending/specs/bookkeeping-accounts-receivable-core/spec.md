# bookkeeping-accounts-receivable-core Specification (delta)

## Purpose

An issued sales invoice leaves shillinq by email with its PDF, many invoices
are created and sent in one action, and each customer carries its own payment
term and delivery method. From shillinq matrix rows `sal-email-pdf`,
`sal-batch` and `sal-customer-terms`.

## ADDED Requirements

### Requirement: An issued invoice is sent by email with its PDF attached (REQ-SIS-001)

Shillinq SHALL let a user with access to the administration send an `ARInvoice`
in state `issued` or `overdue` to the customer by email from `ARInvoiceDetail`.
The mail MUST carry the invoice as a PDF attachment named
`Factuur-<invoiceNumber>.pdf`, MUST go to `CustomerMaster.invoiceEmail` when
set and to `CustomerMaster.email` otherwise, and the invoice SHALL record
`sending.sentVia`, `sending.sentTo` and `sending.sentAt` only after the mailer
accepted the message.

#### Scenario: A bookkeeper mails an issued invoice

- GIVEN a bookkeeper on the AR invoice page of issued invoice 2026-0412 for Bakkerij De Korenaar B.V., whose invoice email is facturen@dekorenaar.example
- WHEN they choose Send by email
- THEN a mail with the attachment Factuur-2026-0412.pdf goes to facturen@dekorenaar.example
- AND the invoice page shows it was sent by email to that address, with the date and time

#### Scenario: A draft invoice cannot be sent

- GIVEN invoice 2026-0413 in state draft
- WHEN the bookkeeper opens its AR invoice page
- THEN the Send by email action is disabled with the reason that only an issued invoice can be sent

#### Scenario: A mail failure is shown, not hidden

- GIVEN the instance's mail server refuses the message
- WHEN the bookkeeper sends invoice 2026-0412
- THEN the page shows that the invoice could not be sent and why
- AND the invoice carries no sentAt

### Requirement: A send follows the customer's delivery method (REQ-SIS-002)

The send action SHALL route by `CustomerMaster.deliveryMethod`: `email` mails
the PDF, `peppol` hands the invoice to the existing e-invoice send, `post` and
`none` MUST NOT mail anything and SHALL record the method. When the e-invoice
send answers that the customer has no Peppol participant, the action SHALL
offer to mail the PDF instead of telling the user to leave the app.

#### Scenario: A Peppol customer without a participant id gets the PDF by email

- GIVEN customer Gemeente Voorbeeld with delivery method peppol and no resolvable Peppol participant
- WHEN the bookkeeper sends issued invoice 2026-0420 from its AR invoice page
- THEN the page offers Send by email instead
- AND choosing it mails the PDF and records sentVia email

#### Scenario: A post customer is not mailed

- GIVEN customer Stichting Buurthuis De Linde with delivery method post
- WHEN the bookkeeper sends issued invoice 2026-0421
- THEN no mail is sent
- AND the invoice page shows the delivery method post and that the letter is printed by hand

### Requirement: Selected invoices are sent in one action (REQ-SIS-003)

`AccountsReceivable` SHALL let a user select up to 200 invoices and send them in
one action. The sends SHALL run in a queued background job, each invoice MUST
follow REQ-SIS-002, and the batch SHALL record an outcome per invoice: sent,
skipped with a reason, or failed with the error. An invoice that already
carries `sending.sentAt` SHALL be skipped unless the user asked to resend.

#### Scenario: A bookkeeper sends the month's invoices

- GIVEN twelve issued invoices of September 2026 selected on the Accounts Receivable page, one of them already sent
- WHEN the bookkeeper chooses Send selected
- THEN eleven invoices are sent according to each customer's delivery method
- AND the batch result lists the already-sent invoice as skipped

#### Scenario: The same batch is not sent twice

- GIVEN a send batch that finished
- WHEN the bookkeeper selects the same invoices and chooses Send selected without resend
- THEN every invoice is skipped as already sent

### Requirement: A batch creates one invoice per customer (REQ-SIS-004)

Shillinq SHALL let a user create one `ARInvoice` per selected customer from one
set of lines, for up to 200 customers per call, through
`POST /apps/shillinq/api/ar-invoices/batch`. The create MUST be idempotent per
batch and customer, each invoice's due date SHALL follow that customer's
`paymentTermDays` unless the batch names a due date, and the user MAY issue and
send the created invoices in the same action.

#### Scenario: A consultancy bills its maintenance contract to three customers

- GIVEN a bookkeeper who selects Bakkerij De Korenaar B.V. (14 days), Gemeente Voorbeeld (30 days) and Stichting Buurthuis De Linde (21 days) and one line Onderhoudscontract oktober 2026 of EUR 250 excl. 21 percent VAT dated 2026-10-01
- WHEN they create the batch with issue and send
- THEN three issued invoices of EUR 302.50 exist with due dates 2026-10-15, 2026-10-31 and 2026-10-22
- AND each is sent according to its customer's delivery method

#### Scenario: A retried batch writes nothing twice

- GIVEN a batch create that wrote invoices for two of three customers before the connection dropped
- WHEN the same batch is submitted again
- THEN only the third customer's invoice is written

### Requirement: A customer carries a payment term and a delivery method (REQ-SIS-005)

`CustomerMaster` SHALL declare `paymentTermDays` (integer, default 30),
`deliveryMethod` (`email`, `peppol`, `post`, `none`, default `email`) and an
optional `invoiceEmail`, editable on `CustomerDetail`. A new invoice created by
the quick draft, the time-and-expense generator or the batch create SHALL take
its due date as invoice date plus the customer's `paymentTermDays`, and a new
recurring profile SHALL default its payment term to it.

#### Scenario: The quick draft uses the customer's term

- GIVEN customer Bakkerij De Korenaar B.V. with a payment term of 14 days
- WHEN a bookkeeper starts a quick draft for that customer dated 2026-10-01
- THEN the due date field shows 2026-10-15

#### Scenario: The time-and-expense generator uses the customer's term

- GIVEN customer Gemeente Voorbeeld with a payment term of 30 days
- WHEN a bookkeeper generates an invoice on the Generate invoice page with period end 2026-10-31
- THEN the drafted invoice has due date 2026-11-30 and payment terms stating 30 days

### Requirement: The customer list shows fields the customer carries (REQ-SIS-006)

Every column of the `Customers` index SHALL name a property `CustomerMaster`
declares. The index MUST show the customer code, legal name, payment term,
delivery method, credit limit and status.

#### Scenario: A bookkeeper scans the customer list

- GIVEN three customers with codes, names and payment terms set
- WHEN a bookkeeper opens the Customers page
- THEN every column shows a value for every customer and none is empty for want of a field

### Requirement: A recurring profile with email delivery sends its auto-issued invoices (REQ-SIS-007)

When `RecurringInvoiceGenerator` issues an invoice for a profile in issue mode
`auto-issue` whose `deliveryChannel` is `email`, the invoice SHALL be sent
through REQ-SIS-001. A profile in `draft-for-review` mode MUST NOT send.

#### Scenario: A monthly subscription invoice goes out by itself

- GIVEN an active profile for Bakkerij De Korenaar B.V. in auto-issue mode with delivery channel email
- WHEN the scheduled run issues the October 2026 invoice
- THEN the invoice is mailed to facturen@dekorenaar.example with its PDF
- AND its AR invoice page shows it was sent by email
