# bookkeeping-bank-reconciliation Specification (delta)

## Purpose

A bookkeeper sees the payouts of the payment providers on the receivables
page.

## ADDED Requirements

### Requirement: Provider payouts are listed on the receivables page (REQ-RPT-001)

Shillinq SHALL hold a `ProviderPayout` per settlement of a payment provider,
scoped to an administration, with the fields of
`receivables-provider-payouts` design.md Seed Data. It SHALL also hold
`matchedLineCount` and `unmatchedLineCount`, the number of its lines with
status `matched` and `unmatched`. The receivables page SHALL show the payouts
in a Provider payouts tab of its tab strip, newest first, with the number of
payouts on the tab.

#### Scenario: A Mollie payout with one unmatched payment

- GIVEN Mollie paid out EUR 4.912,35 to Gemeente Voorbeeld on 7 October after EUR 38,65 fees, 14 of its 15 lines matched
- WHEN a bookkeeper opens the Provider payouts tab on the receivables page
- THEN the payout is listed first with the amount paid out, the fees, 14 matched and 1 open

@e2e exclude the tab is manifest config, covered by tests/vitest/receivablesBoard.spec.js; the record and its counts by ProviderPayoutSchemaTest

#### Scenario: A payout without its provider is refused

- GIVEN a bookkeeper records a payout
- WHEN the payout names no provider or no amount paid out
- THEN the register refuses it

@e2e exclude schema validation, covered by ProviderPayoutSchemaTest::testAPayoutWithoutProviderOrAmountIsRefused
