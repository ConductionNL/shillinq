# Spec: recurring-revenue-recognition

**Status:** in-progress
**Scope:** shillinq
**Kind:** config (head of the `order-revenue-recognition` chain — ADR-032)

## ADDED Requirements

### Requirement: SalesOrder SHALL model the booking term as a first-class declarative schema

A new `SalesOrder` schema SHALL be declared in `lib/Settings/shillinq_register.json` per
ADR-001 (data lives in OpenRegister; no app-owned tables) and ADR-031 (declarative schema
over service classes). The order is the **actual booking term**; it MAY reference a legal
contract by id but SHALL NOT embed or require a modeled Contract entity.

`SalesOrder` SHALL declare:

| Field | Type | Required | Description |
|---|---|---|---|
| `orderId` | string | Yes | Unique identifier (business key) |
| `ondernemingId` | string | Yes | FK to the selling Corporation |
| `administrationId` | string | Yes | FK to the Administration tenant |
| `klantId` | string | Yes | FK to the customer |
| `orderDate` | date | Yes | Date the order was booked |
| `termStart` | date | Yes | Start of the booking term |
| `termEnd` | date (nullable) | No | End of the booking term; `null` = indefinite |
| `status` | enum | Yes | `active` \| `ended` (lifecycle of the booking) |
| `currency` | string | Yes | ISO 4217; default `EUR` |
| `contractId` | string (nullable) | No | **Plain string reference** to the legal agreement — NOT a modeled entity |

`SalesOrder` SHALL carry `x-openregister-audit-trail: { enabled: true }` per the
adr-000-data-model audit-trail-on-every-bookkeeping-register rule, and `administrationId`-scoped
RBAC consistent with the other shillinq bookkeeping registers.

#### Scenario: Schema validator accepts an order with an indefinite term and no contract

- **WHEN** a `SalesOrder` is saved with `termStart` set, `termEnd` = `null`, `status` = `active`,
  `currency` = `EUR`, and `contractId` omitted
- **THEN** validation MUST pass and the order is treated as an open-ended (indefinite) booking

#### Scenario: contractId is a reference, not a relation requiring a Contract object

- **WHEN** a `SalesOrder` is saved with `contractId` = `"CONTRACT-2026-0001"` where no
  `Contract` object with that id exists in any register
- **THEN** validation MUST pass — `contractId` is a free-text legal reference and MUST NOT
  trigger referential-integrity resolution against a Contract entity

### Requirement: SalesOrderLine SHALL declare line nature and recognition method declaratively

A new `SalesOrderLine` schema SHALL be declared in `lib/Settings/shillinq_register.json`,
keyed to its parent order by `orderId`. Each line declares whether it is recurring or one-off
and how it is recognized. Dutch field names + English schema name per the in-register
convention (cf. `CashflowRecurring`).

`SalesOrderLine` SHALL declare:

| Field | Type | Required | Description |
|---|---|---|---|
| `lineId` | string | Yes | Unique identifier |
| `orderId` | string | Yes | FK to the parent `SalesOrder` |
| `administrationId` | string | Yes | FK to the Administration tenant |
| `nature` | enum | Yes | `RECURRING` \| `ONE_OFF` |
| `label` | string | Yes | Human-readable line label |
| `amount` | number | Yes | Per-interval amount for `RECURRING`; total amount for `ONE_OFF` (EUR, `multipleOf` 0.01) |
| `frequentie` | enum (nullable) | No | `MAANDELIJKS` \| `KWARTAALS` \| `JAARLIJKS` \| `WEKELIJKS` \| `TWEEWEKELIJKS` — required for `RECURRING`, null for `ONE_OFF` |
| `recognitionMethod` | enum | Yes | `OVER_TIME` \| `POINT_IN_TIME` |
| `termStart` | date (nullable) | No | Line term start; inherits the order's `termStart` when null |
| `termEnd` | date (nullable) | No | Line term end; inherits the order's `termEnd` when null |
| `recognitionDate` | date (nullable) | No | Required for `POINT_IN_TIME` lines; the date the obligation is satisfied |
| `accountNumber` | string | No | GL account code (FK to `Account.accountNumber`) |

A `RECURRING` line SHALL carry a non-null `frequentie`. A `POINT_IN_TIME` line SHALL carry a
non-null `recognitionDate`. A line with null `termStart`/`termEnd` SHALL be evaluated against
the parent order's term.

#### Scenario: A recurring annual line inherits the order term

- **WHEN** a `SalesOrderLine` is saved with `nature` = `RECURRING`, `frequentie` = `JAARLIJKS`,
  `recognitionMethod` = `OVER_TIME`, `amount` = 12000, and `termStart`/`termEnd` both null
- **THEN** validation MUST pass and the line's effective term is the parent `SalesOrder`'s
  `[termStart, termEnd]`

#### Scenario: A one-off point-in-time line requires a recognitionDate

- **WHEN** a `SalesOrderLine` is saved with `nature` = `ONE_OFF`, `recognitionMethod` =
  `POINT_IN_TIME`, `amount` = 5000, and `recognitionDate` set
- **THEN** validation MUST pass, `frequentie` MUST be null, and the line MUST NOT be counted
  as recurring revenue in any period

### Requirement: SalesOrderLine SHALL declare a derived monthly-normalized `maandWaarde` for run-rate aggregation

`SalesOrderLine` SHALL declare a derived `maandWaarde` number property populated by an
`x-openregister-calculations.maandWaarde` calculation (declarative; ADR-031 — no service): the
monthly-normalized recurring amount `amount × frequencyFactor(frequentie)` using the same
frequency factors as the recognition metric (`MAANDELIJKS`=1, `KWARTAALS`=1/3, `JAARLIJKS`=1/12,
`WEKELIJKS`=52/12, `TWEEWEKELIJKS`=26/12), and **0 when `nature == 'ONE_OFF'`** (one-off lines
contribute nothing to a recurring run-rate). `maandWaarde` is derived and MUST NOT be set
directly. It exists so a downstream consumer (the pipelinq CRM run-rate tile) can `SUM`
`maandWaarde` filtered to `nature == 'RECURRING'` as a plain OpenRegister aggregation, without
the runtime-period overlap arithmetic the recognized-revenue metric requires.

#### Scenario: A monthly line normalizes to its own amount; a one-off normalizes to zero

- **GIVEN** a `RECURRING MAANDELIJKS` line with `amount` = 1500 and an `ONE_OFF POINT_IN_TIME`
  line with `amount` = 5000
- **THEN** the recurring line's `maandWaarde` MUST be 1500 and the one-off line's `maandWaarde`
  MUST be 0

#### Scenario: An annual recurring line normalizes to one-twelfth

- **GIVEN** a `RECURRING JAARLIJKS` line with `amount` = 12000
- **THEN** its `maandWaarde` MUST be 1000 (12000 × 1/12)

### Requirement: Recognized recurring revenue for a period SHALL be the term-overlap-prorated sum of RECURRING lines

The system SHALL define recognized recurring revenue for a reporting period as the
term-overlap-prorated, frequency-normalized sum of `RECURRING` lines, excluding `ONE_OFF` lines.
The recognized **recurring** revenue metric for a reporting period `[periodFrom, periodTo]`
MUST be computed as:

```
recognizedRecurringRevenue([periodFrom, periodTo]) =
  Σ over RECURRING lines L of ( monthlyRate(L) × overlapMonths(termOf(L), [periodFrom, periodTo]) )
```

where:

- `termOf(L)` = the line's `[termStart, termEnd]`, inheriting the order's term where a bound
  is null; an open-ended `termEnd` (null) extends to `periodTo` for the overlap computation.
- `overlapMonths(a, b)` = the length, in months, of the intersection of intervals `a` and `b`
  (zero when they do not overlap); period granularity (whole-month vs daily proration) is a
  documented decision in `design.md` and the engine change.
- `monthlyRate(L)` = `amount(L) × frequencyFactor(frequentie(L))`, normalizing each line to a
  monthly rate:

  | `frequentie` | frequencyFactor (per month) |
  |---|---|
  | `MAANDELIJKS` | 1 |
  | `KWARTAALS` | 1/3 |
  | `JAARLIJKS` | 1/12 |
  | `WEKELIJKS` | 52/12 |
  | `TWEEWEKELIJKS` | 26/12 |

`ONE_OFF` lines SHALL be EXCLUDED from this sum. They are recognized separately — point-in-time
at `recognitionDate`, or over-time across the line's delivery window — and surfaced as a
distinct "one-off / implementation" figure, never as recurring revenue.

A secondary **ARR / run-rate** view (annualized current monthly rate of in-term recurring
lines) MAY be derived when cheap, but is explicitly NOT the primary metric.

This requirement is realized by the chained `order-revenue-recognition-engine` change
(kind: code), because the computation is an ADR-031 exception (see the next requirement and
`design.md`). This head change only DECLARES the metric and the schemas it reads.

#### Scenario: Annual subscription recognized over a partial period

- **GIVEN** a `RECURRING` `JAARLIJKS` line with `amount` = 12000 (EUR/year → monthlyRate 1000)
  and term `[2026-01-01, 2026-12-31]`
- **WHEN** recognized recurring revenue is computed for the period `[2026-01-01, 2026-03-31]`
  (3 months overlap)
- **THEN** the recognized recurring revenue contribution of this line MUST be 3000 (1000 × 3)

#### Scenario: One-off implementation fee is excluded from recurring revenue

- **GIVEN** an order with one `RECURRING MAANDELIJKS` line (`amount` = 1500) and one `ONE_OFF
  POINT_IN_TIME` implementation line (`amount` = 5000, `recognitionDate` = 2026-01-15)
- **WHEN** recognized recurring revenue is computed for `[2026-01-01, 2026-01-31]`
- **THEN** the recurring figure MUST be 1500 (the monthly line only); the 5000 implementation
  fee MUST appear only in the separate one-off figure, recognized in January

#### Scenario: A line whose term does not overlap the period contributes zero

- **GIVEN** a `RECURRING MAANDELIJKS` line with term `[2026-06-01, 2026-12-31]`
- **WHEN** recognized recurring revenue is computed for `[2026-01-01, 2026-03-31]`
- **THEN** this line's contribution MUST be 0 (no term/period overlap)

### Requirement: The recognition computation SHALL be an ADR-031 exception, isolated behind a service in the chained code change

The recognition computation SHALL be implemented as an ADR-031 exception service
(`OCA\Shillinq\Recognition\RevenueRecognitionService`) delivered by the chained
`order-revenue-recognition-engine` change; the `SalesOrder` / `SalesOrderLine` schemas MUST
remain fully declarative `config`.
Because the recognition metric requires interval-overlap proration parameterized by a
**runtime** reporting period (`[periodFrom, periodTo]` chosen at dashboard time), and the
OpenRegister `x-openregister-aggregations` / `-calculations` grammar cannot express
runtime-period-parameterized overlap arithmetic (it prorates only against a *persisted* period
schema such as `CashflowWeek`, and `@params.*` is used for equality filtering, never date-diff
overlap — see `design.md`), the recognition computation SHALL be implemented as a thin
`OCA\Shillinq\Recognition\RevenueRecognitionService` per the ADR-031 exception path. The
schemas (`SalesOrder`, `SalesOrderLine`) remain fully declarative `config`; only the recognition
arithmetic is `code`. This boundary mirrors the existing `EmuCalculator` guard precedent in the
same register. The service and its read endpoint SHALL be delivered by the chained
`order-revenue-recognition-engine` change (kind: code), NOT by this head change.

#### Scenario: Schema-stored, code-recognized

- **WHEN** the head change is merged
- **THEN** `SalesOrder` and `SalesOrderLine` objects can be created, read, and audit-trailed
  through OpenRegister with zero shillinq PHP, AND the recognized-recurring-revenue figure is
  unavailable until the chained `-engine` change ships the `RevenueRecognitionService`

## Seed Data (ADR-001)

A realistic MKB / consultancy order with mixed lines SHALL be seeded so the recurring/one-off
split and a sample-period recognition are demonstrable. See `design.md` → Seed Data for the
canonical placeholder order (SaaS subscription `RECURRING JAARLIJKS` + implementation fee
`ONE_OFF POINT_IN_TIME` + monthly retainer `RECURRING MAANDELIJKS`).
