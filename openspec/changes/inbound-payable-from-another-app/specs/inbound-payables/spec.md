# inbound-payables Specification (delta)

## Purpose

Another app owes a payee money and asks shillinq to pay it. Shillinq stores the payable, finance books and pays it, and the app hears every step on a signed callback (decision 192).

## ADDED Requirements

### Requirement: An app raises a payable through a typed event (REQ-IPY-001)

Shillinq SHALL publish `OCA\Shillinq\Event\PayableRaisedEvent` carrying amount, payee name and IBAN, reference, description, callback URL, category, correlation id, administration and pay-by date, and SHALL answer it with `{contractVersion, payableId, state}` or an error. It SHALL refuse, and store nothing, when the app is not installed, the amount is not above zero, the payee has no name, the IBAN fails mod-97, the reference is shorter than 6 characters or the callback is not an http(s) address. The same reference from the same app with the same amount and IBAN SHALL answer the existing payable; with another amount or IBAN it SHALL be refused.

#### Scenario: Dossiq raises a penalty payout

- GIVEN dossiq is installed
- WHEN dossiq dispatches `PayableRaisedEvent` for 1442.00 to J. Jansen, IBAN NL91ABNA0417164300, reference DWS-2026-0042
- THEN the event comes back handled with state `requested` and an `InboundPayable` is stored with that payee and reference

#### Scenario: A retry does not pay twice

- GIVEN that payable
- WHEN dossiq raises DWS-2026-0042 again with the same amount and IBAN
- THEN the answer names the same payable and no second one is stored

### Requirement: Finance approves a payable (REQ-IPY-002)

A user with `payment.administer` SHALL approve a requested payable, which books debit the expense account (from `inboundPayableExpenseAccounts`, by category, else by app) and credit `inboundPayableAccount`, and moves it to `approved`. Without an expense account the approval SHALL be refused before anything is booked.

#### Scenario: A treasurer approves the penalty

- GIVEN the requested payable with category `dwangsom` mapped to 4390 and `inboundPayableAccount` 1610
- WHEN the treasurer approves it
- THEN a journal entry debits 4390 and credits 1610 for 1442.00 and the payable reads `approved`

### Requirement: Finance pays or rejects a payable (REQ-IPY-003)

A user with `payment.administer` SHALL mark an approved payable paid with a bank reference, bank account and date, which books debit `inboundPayableAccount` and credit the bank and moves it to `paid`, and SHALL reject a requested payable with a reason, which books nothing and moves it to `rejected`.

#### Scenario: The payout leaves the bank

- GIVEN the approved payable
- WHEN the treasurer marks it paid with SEPA-777 from bank account 1100 on 2026-10-20
- THEN a journal entry debits 1610 and credits 1100 and the payable reads `paid` with that reference and date

### Requirement: Each step reaches the app on a signed callback (REQ-IPY-004)

After approve, mark paid and reject, shillinq SHALL post `{contractVersion, payableId, reference, correlationId, status, amount, currency, actualPaymentDate, bankReference, reason}` to the payable's callback URL with header `X-Shillinq-Signature` set to the hex HMAC-SHA256 of the body under the app's secret in `inboundPayableCallbackSecrets`. Without a secret it SHALL NOT send, and SHALL record why in `callbackError`. A failed callback SHALL NOT undo the step.

#### Scenario: Dossiq hears that the penalty was paid

- GIVEN a secret for dossiq
- WHEN the payable is marked paid
- THEN dossiq's callback receives status `paid`, the date and the bank reference, signed with dossiq's secret

### Requirement: The raising app withdraws a payable finance has not acted on (REQ-IPY-005)

The app that raised a payable SHALL be able to withdraw it with `PayableCancelRequestedEvent` (source app, reference, reason) while it is `requested`, which moves it to `cancelled` with `cancelledAt` and `cancelledReason`, books nothing and sends no callback. Withdrawing a `cancelled` payable SHALL answer it again. An `approved`, `paid` or `rejected` payable, an unknown reference or an empty reason SHALL be refused and change nothing.

#### Scenario: Dossiq withdraws a penalty payout under objection

- GIVEN dossiq raised payable DWS-2026-0042 and finance has not acted on it
- WHEN dossiq sends the cancel command with reason "Bezwaar ingediend"
- THEN the payable reads `cancelled` with that reason, and no journal entry and no callback follow

#### Scenario: Finance already approved it

- GIVEN finance approved payable DWS-2026-0042
- WHEN dossiq sends the cancel command
- THEN the command is refused naming the state `approved`, and the payable stays approved
