# Subject cost aggregation

## Purpose

Turn the hours booked against one domain object — a procest case today, any
case/matter object tomorrow — into an employer cost.

Per hydra ADR-081 the split is fixed: the domain app **classifies** (it may
show a sum of hours, because hours are effort rather than currency) and
Shillinq **aggregates**, because Shillinq owns the general ledger. The wage
half of an hour's cost is hrmq's and is served by
`POST /api/employees/cost-rate`; the ledger-derived additions are Shillinq's.

`UrenRegistratie.subjectApp` / `subjectId` are what make an hour attributable
to a domain object at all.

## Requirements

### Requirement: A cost is published only when every hour in it could be priced

The aggregator SHALL group an hour set by `personId` and cost each person's
hours at that person's own rate.

Where ANY person with hours has no resolvable rate, it MUST NOT return a
total. `complete` SHALL be false, the cost SHALL be null, and the unpriced
people SHALL be named so a caller can say what is missing.

This is the requirement the capability exists to protect. A cost that silently
omits someone's hours is worse than no cost at all: it is plausible, it is
**always lower** than the truth, and nothing about it looks wrong on a case
page. A caller renders "hours known, cost unavailable" rather than a number
that reads as authoritative.

Hours SHALL be returned whether or not a cost could be computed.

#### Scenario: One unpriced person withholds the whole total
- **GIVEN** hours booked by two people and a rate for only one of them
- **WHEN** the subject is aggregated
- **THEN** the cost is null, `complete` is false, the unpriced person is named,
  and the total hours still cover both
- @e2e exclude pure aggregation policy — asserted by PHPUnit

### Requirement: Money is integer and rounded once per person

All monetary values SHALL be integer cents. A float total accumulated over
many rows drifts, and a ledger that drifts is not a ledger.

Rounding SHALL happen once per PERSON, after their hours are summed — never
per row. Three rows of one-third hour at €10.00 is 1000 cents when summed per
person and 999 when each row is rounded first.

#### Scenario: Per-row rounding does not leak into the total
- **GIVEN** three rows of one-third hour for the same person at 1000 cents/hour
- **WHEN** the subject is aggregated
- **THEN** the cost is exactly 1000 cents
- @e2e exclude arithmetic — asserted by PHPUnit

### Requirement: Unusable hours are rejected rather than coerced

A row whose `hours` is not numeric SHALL be skipped, not cast. PHP coerces
`''` and `'n/a'` to `0.0` without complaint, which invents a person who
"worked no hours" — and, having no rate, would flip an otherwise complete cost
to incomplete.

Hours carrying no `personId` SHALL still count toward total hours, under a
reserved key, but can never be priced: the effort happened even though nobody
owns it.

#### Scenario: Junk rows neither count nor invent people
- **GIVEN** an hour set containing non-numeric hours for people who have no
  other rows, plus one valid priced row
- **WHEN** the subject is aggregated
- **THEN** only the valid row appears in the breakdown, the cost is complete,
  and no person was invented by coercion
- @e2e exclude input validation — asserted by PHPUnit
