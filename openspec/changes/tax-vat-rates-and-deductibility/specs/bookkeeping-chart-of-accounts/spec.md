# bookkeeping-chart-of-accounts Specification (delta)

## Purpose

A ledger account can say which share of its costs is deductible and where
the rest goes. From shillinq matrix row `tax-partial-deductibility`.

## ADDED Requirements

### Requirement: A ledger account carries a deductible share and a private-share account (REQ-TVRD-003)

An `Account` SHALL carry a deductible percentage from 0 to 100, defaulting
to 100, and a private-share account. The chart of accounts detail page
SHALL show and edit both, and a percentage below 100 MUST NOT be saved
without a private-share account.

#### Scenario: A sole trader sets the business share of car costs

- GIVEN the bookkeeper of Bakkerij De Korenbloem on the detail page of account 4520 Autokosten
- WHEN they set the deductible percentage to 70 and the private-share account to 1810 Privé-opnames
- THEN the account page shows 70 percent deductible with private share to 1810
