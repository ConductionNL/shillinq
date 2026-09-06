# Subject cost aggregation

## Purpose

Turn the hours booked against one domain object into an employer cost. Today
that object is a procest case. Tomorrow it is any case or matter object.

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
- @e2e exclude pure aggregation policy, asserted by PHPUnit

### Requirement: Money is integer and rounded once per person

All monetary values SHALL be integer cents. A float total accumulated over
many rows drifts, and a ledger that drifts is not a ledger.

Rounding SHALL happen once per PERSON, after their hours are summed, never
per row. Three rows of one-third hour at €10.00 is 1000 cents when summed per
person and 999 when each row is rounded first.

#### Scenario: Per-row rounding does not leak into the total
- **GIVEN** three rows of one-third hour for the same person at 1000 cents/hour
- **WHEN** the subject is aggregated
- **THEN** the cost is exactly 1000 cents
- @e2e exclude arithmetic, asserted by PHPUnit

### Requirement: Unusable hours are rejected rather than coerced

A row whose `hours` is not numeric SHALL be skipped, not cast. PHP coerces
`''` and `'n/a'` to `0.0` without complaint, which invents a person who
"worked no hours". Having no rate, that invented person flips an otherwise
complete cost to incomplete.

Hours carrying no `personId` SHALL still count toward total hours, under a
reserved key, but can never be priced: the effort happened even though nobody
owns it.

#### Scenario: Junk rows neither count nor invent people
- **GIVEN** an hour set containing non-numeric hours for people who have no
  other rows, plus one valid priced row
- **WHEN** the subject is aggregated
- **THEN** only the valid row appears in the breakdown, the cost is complete,
  and no person was invented by coercion
- @e2e exclude input validation, asserted by PHPUnit

### Requirement: A subject cost is reachable over HTTP

The capability SHALL be exposed as `GET /api/subject-cost`, taking
`subjectApp` and `subjectId` and returning the aggregate for that domain
object. Both are required; a request missing either SHALL be refused with 400.

This requirement exists because the capability was built without it. The
aggregator and the wage-rate adapter were implemented, spec-tagged and
unit-tested, and nothing could call either one. No route reached them, no
service composed them, and no code read an hour set for a subject. A
capability with no door is not delivered, however green its tests are.

The endpoint SHALL be available to any authenticated user. It SHALL refuse a
caller who belongs to no administration.

A Nextcloud admin SHALL read every administration, as it already does in
`CBSSubmissionController` and `BookingNotificationController`. Back-office
admins hold no `AdministrationMembership` of their own, so without the bypass
the endpoint refuses its own operator.

#### Scenario: The endpoint answers for a subject with booked hours
- **GIVEN** hours booked against a domain object, in an administration the
  caller belongs to
- **WHEN** the caller requests `GET /api/subject-cost` for that subject
- **THEN** the response is 200 and reports the subject's total hours

#### Scenario: A request without a subject is refused
- **GIVEN** an authenticated caller
- **WHEN** `subjectApp` or `subjectId` is missing
- **THEN** the response is 400 and no aggregate is returned

### Requirement: Hours the caller cannot reach are never counted

Rows SHALL be included only when their `administrationId` is one the caller
can reach. A row carrying no `administrationId` SHALL be excluded rather than
assumed in scope.

An excluded row SHALL be counted in `unscopedRowsExcluded`. A subject whose
hours cannot be attributed to any administration is a data defect, and a
caller that cannot see the count would read a short total as the whole.

Naming an `administrationId` the caller cannot reach SHALL be masked as 404,
never widened to everything the caller can reach. Those are two different
answers and the caller asked for one of them. 404 rather than 403 is the house
IDOR rule (REQ-MA-001): a resource the caller may not reach must look the same
as one that is not there.

#### Scenario: An unattributable row is excluded and counted
- **GIVEN** an hour set for one subject in which one row has no administration
- **WHEN** the subject is aggregated
- **THEN** that row is absent from the total and `unscopedRowsExcluded` is 1
- @e2e exclude scoping arithmetic, asserted by PHPUnit

#### Scenario: An administration the caller cannot reach is masked
- **GIVEN** an authenticated caller and an administration they do not belong to
- **WHEN** they request that administration by id
- **THEN** the response is 404
- @e2e exclude authorisation branch, asserted by PHPUnit
