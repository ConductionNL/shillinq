# recurring-invoicing Specification

## ADDED Requirements

### Requirement: REQ-RIN-011: Invoices generated before ARInvoice 0.16.0 SHALL get their provenance back where it can be derived

A post-migration repair step SHALL fill `recurringProfileId`, `billingPeriod` and
each line's `glAccount` on an ARInvoice that lacks them when exactly one
RecurringInvoiceProfile fits it: the same customer and administration, an invoice
date equal to the profile's invoice day clamped to that month, a month between the
profile's start and its last generated period, and the profile's net amount. The
period SHALL be the invoice date's month and a line's account the profile line's
`revenueAccount` at the same position. A field holding a value SHALL NOT be
written. When two profiles fit, the invoice SHALL be left alone. A second run SHALL
save nothing. A failure SHALL warn and never block the upgrade.

#### Scenario: A generated invoice gets its profile, period and line accounts

- GIVEN a monthly profile invoiced on the 5th, generated up to 2026-09, with two lines on accounts 8000 and 8010
- AND an invoice of 2026-03-05 for its customer and amount, without provenance
- WHEN the repair step runs
- THEN the invoice carries the profile id, billing period `2026-03` and the two accounts on its lines
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAGeneratedInvoiceGetsItsProfilePeriodAndLineAccounts`

#### Scenario: An invoice that does not fit is left alone

- GIVEN invoices on another day, with another amount, in a month never generated, or in another administration
- WHEN the repair step runs
- THEN none of them is saved
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAnInvoiceThatDoesNotFitTheProfileIsLeftAlone`

#### Scenario: Two fitting profiles leave the invoice alone

- GIVEN two profiles that both fit one invoice
- WHEN the repair step runs
- THEN the invoice is not saved and the summary counts one ambiguous invoice
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAnInvoiceTwoProfilesFitIsLeftAlone`

#### Scenario: A value already there is never overwritten

- GIVEN an invoice with its profile id and one line account already set
- WHEN the repair step runs
- THEN only the billing period and the other line's account are filled
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAValueAlreadyThereIsNeverOverwritten`

#### Scenario: A second run saves nothing

- GIVEN the repair step ran once
- WHEN it runs again
- THEN it saves nothing
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testASecondRunSavesNothing`
