# bookkeeping-multi-currency Specification (delta)

## Purpose

A foreign-currency transaction is booked at the exchange rate of its date,
and the ledger line shows which rate that was. From shillinq matrix row
`bnk-fx-rates`.

## ADDED Requirements

### Requirement: A foreign-currency line is booked at the rate of its date (REQ-BFX-001)

When a transaction posts, every line whose currency differs from the
administration's base currency SHALL be booked at the newest `FxRate` for
that currency pair dated on or before the booking date. The posted
`GLLine` SHALL carry the transaction amount and currency, the base amount
and currency, the rate, its source and its date. An administration-scoped
rate SHALL win over a shared one on the same date.

#### Scenario: A bookkeeper posts a USD supplier invoice on a Sunday

- GIVEN a USD to EUR rate of 0.9150 dated Friday 2026-09-25 and no later one
- WHEN a bookkeeper posts a journal entry dated Sunday 2026-09-27 for USD 1,000.00
- THEN the general ledger detail page shows the line at EUR 915.00 with rate 0.9150, source manual and rate date 2026-09-25

### Requirement: No foreign-currency line posts without a usable rate (REQ-BFX-002)

The posting SHALL be refused when no rate exists for the currency pair on
or before the booking date, or when the newest one is older than the
configured maximum age (five calendar days by default). The refusal MUST
name the currency pair and the booking date and point to the FX rates page.

#### Scenario: A GBP entry with a stale rate is refused

- GIVEN the newest GBP to EUR rate is dated 2026-09-18
- WHEN the bookkeeper posts a GBP entry dated 2026-09-27
- THEN the post is refused with a message naming GBP to EUR and 2026-09-27
- AND the entry stays unposted

### Requirement: A rate typed on the entry wins and is recorded as manual (REQ-BFX-003)

A journal entry line SHALL accept a rate and a reason. A line with a typed
rate SHALL be booked at that rate with source manual, and a typed rate
without a reason MUST be refused.

#### Scenario: A bookkeeper books at the rate agreed with the bank

- GIVEN a journal entry line of USD 20,000.00 with typed rate 0.9102 and reason Termijncontract ING 2026-114
- WHEN the entry posts
- THEN the ledger line shows EUR 18,204.00, rate 0.9102 and source manual

### Requirement: The FX rates page says where rates come from (REQ-BFX-004)

The FX rates page SHALL state whether rates arrive from the rates feed or
are entered by hand, and SHALL show the date of the newest rate per
currency pair.

#### Scenario: A controller sees that rates are manual

- GIVEN the rates feed is not connected
- WHEN a controller opens the FX rates page
- THEN it states that rates are entered by hand
- AND it lists USD to EUR with newest date 2026-09-25
