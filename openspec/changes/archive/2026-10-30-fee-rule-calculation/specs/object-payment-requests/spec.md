# object-payment-requests Delta: fee-rule-calculation

## ADDED Requirements

### Requirement: A fee rule has a name and a calculation

A fee schedule MAY carry a `name`, a `variantValue` and a `calculation`: `fixed`, `percentage` (with `percentage`,
`minimumAmount` and `baseProperty`), `per_unit` (with `unitPrice` and `baseProperty`) or `refund_percentage` (with
`percentage`). A schedule whose calculation lacks a field it needs SHALL be refused at save. Resolving for an object
SHALL prefer a schedule for the object's own variant over one without a variant, and SHALL work the amount out from the
object; when the object lacks the base value the amount SHALL be left off rather than be zero.

#### Scenario: Building costs

- **GIVEN** a percentage fee of 2.35 with a minimum of 125 on `bouwkosten`
- **WHEN** a case with `bouwkosten` 100000 is resolved
- **THEN** the amount is 2350.00; with `bouwkosten` 1000 it is 125.00

#### Scenario: Trees on the felling route

- **GIVEN** a type fee of 245 and a per-unit fee of 82.40 for variant "Kappen"
- **WHEN** a case on variant "Kappen" with 2 trees is resolved
- **THEN** the per-unit fee applies with 164.80; a case on another variant gets 245
