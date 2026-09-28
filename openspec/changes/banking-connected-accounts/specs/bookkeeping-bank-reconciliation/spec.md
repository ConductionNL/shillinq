# bookkeeping-bank-reconciliation Specification (delta)

## Purpose

A payment that can only belong to one open invoice is booked the moment
it arrives from the feed. From shillinq matrix row `bnk-integrated-account`.

## ADDED Requirements

### Requirement: An exact feed payment is booked on arrival (REQ-BCON-003)

When a statement line arrives from the bank feed and exactly one open
`ARInvoice` or `APTransaction` has the same amount and its invoice number or
payment reference appears as a whole word in the line's remittance
information or end-to-end reference, shillinq
SHALL write and confirm a `ReconciliationMatch` for that pair, recording
`system:bankfeed` as the confirmer and the reason it matched, so that the
invoice moves to paid through the same settlement step a person's
confirmation uses. A line with
no candidate, more than one candidate, or only a partial match MUST stay
unmatched for a person to resolve.

@e2e exclude booking on arrival is service behaviour, asserted by BankfeedIntakeServiceTest::testExactPaymentSettlesItsInvoice through the declared lifecycles

#### Scenario: A customer payment settles its invoice as it arrives

- GIVEN open sales invoice VF-2026-0877 of EUR 1,210.00
- WHEN a feed line of EUR 1,210.00 with remittance VF-2026-0877 arrives
- THEN the invoice shows state paid
- AND its reconciliation match names system:bankfeed and the exact amount and reference as the reason

@e2e exclude asserted by BankfeedIntakeServiceTest::testAmbiguousPaymentWaits

#### Scenario: An ambiguous payment waits for a person

- GIVEN two open invoices of EUR 99.95 and a feed line of EUR 99.95 with remittance abonnement
- WHEN the line arrives
- THEN no match is confirmed
- AND the line appears on the unmatched items page
