---
status: in-progress
---

# recurring-revenue-recognition Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- `order-revenue-recognition`
- `order-revenue-recognition-engine`

## Purpose

Defines the booking-term data model (`SalesOrder` + `SalesOrderLine`) and the
**recognized recurring revenue per period** metric (IFRS 15 / ASC 606 over-time recognition),
replacing the retired run-rate MRR approach. The order is the actual booking term; recognition
is prorated to the overlap of each recurring line's term with the reporting period, with line
`amount` normalized to a monthly rate by `frequentie`. One-off lines (implementation/setup) are
recognized separately and never counted as recurring revenue. An optional `contractId` string
references the legal agreement without modeling a Contract entity.

The schemas are fully declarative OpenRegister config (ADR-001, ADR-031). The recognition
computation is an ADR-031 exception service (`RevenueRecognitionService`) — the grammar cannot
express runtime-period-parameterized interval-overlap proration — delivered by the chained
`order-revenue-recognition-engine` change (kind: code, ADR-032). The pipelinq dashboard widget
consumes the recognition endpoint downstream.
## Requirements

The full requirement set is authored as a delta in
`openspec/changes/order-revenue-recognition/specs/recurring-revenue-recognition/spec.md` and is
folded into this file at archive time. Until then, refer to that change for the normative
requirements and scenarios:

- SalesOrder SHALL model the booking term as a first-class declarative schema.
- SalesOrderLine SHALL declare line nature (`RECURRING` | `ONE_OFF`) and recognition method
  (`OVER_TIME` | `POINT_IN_TIME`) declaratively, with term-inheritance from the order.
- Recognized recurring revenue for a period SHALL be the term-overlap-prorated, frequency-
  normalized sum of `RECURRING` lines, excluding one-off lines.
- The recognition computation SHALL be an ADR-031 exception service in the chained code change.
- The recognition arithmetic, one-off split, ARR view and the RBAC-guarded read endpoint
  (`GET /api/recognition/recurring-revenue`) SHALL be realized by the `order-revenue-recognition-engine`
  change (kind: code) — see its delta for the normative engine requirements and scenarios.

### Requirement: Recognized recurring revenue is computed from booking terms, not run-rate MRR

The capability SHALL model booking terms declaratively (`SalesOrder` +
`SalesOrderLine`) and SHALL report recognized recurring revenue per period as
the term-overlap-prorated, frequency-normalized sum of `RECURRING` lines,
excluding one-off lines — realized by the two in-flight changes listed above,
whose deltas carry the normative per-requirement scenarios and are folded in
here at archive time.

#### Scenario: Period recognition excludes one-off lines

- GIVEN a SalesOrder whose lines include `RECURRING` and `ONE_OFF` natures
- WHEN recognized recurring revenue is computed for a reporting period
- THEN only `RECURRING` lines contribute, prorated to the overlap of each line's term with the period and normalized to a monthly rate by `frequentie`
- @e2e exclude pure backend recognition arithmetic with no browser surface; normative scenarios live in the in-flight change deltas (order-revenue-recognition / order-revenue-recognition-engine) and their PHPUnit coverage

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
### Requirement: RevenueRecognitionService SHALL compute recognized recurring revenue for a runtime period

The system SHALL provide an `OCA\Shillinq\Recognition\RevenueRecognitionService` (an ADR-031
exception service, ADR-022 consuming OpenRegister's ObjectService — no app-owned tables, no direct
SQL) that, for a given `administrationId` and reporting period `[from, to]`, computes recognized
**recurring** revenue as:

```
recognizedRecurringRevenue([from, to]) =
  Σ over RECURRING lines L of ( monthlyRate(L) × overlapMonths(termOf(L), [from, to]) )
```

where `monthlyRate(L) = amount(L) × frequencyFactor(frequentie(L))` normalizes the per-interval
amount to a per-month rate:

| `frequentie`    | frequencyFactor (per month) |
|-----------------|-----------------------------|
| `MAANDELIJKS`   | 1                           |
| `KWARTAALS`     | 1/3                         |
| `JAARLIJKS`     | 1/12                        |
| `WEKELIJKS`     | 52/12                       |
| `TWEEWEKELIJKS` | 26/12                       |

`termOf(L)` SHALL inherit the parent `SalesOrder`'s `[termStart, termEnd]` for any null line bound;
an open-ended `termEnd` (null) SHALL extend to `to` for the overlap computation. `overlapMonths`
SHALL count **whole calendar months** of intersection (D5 — whole-month proration) and SHALL return
0 when the term and period do not overlap. Monetary arithmetic SHALL be performed in integer
euro-cents internally and rounded once at the boundary to avoid IEEE-754 drift. A `RECURRING` line
with a null `frequentie` SHALL contribute 0 and be logged (fail-closed); the service SHALL NOT throw
to the caller.

#### Scenario: Annual subscription recognized over a partial period (full whole months)

- **GIVEN** a `RECURRING JAARLIJKS` line with `amount` 12000 (monthlyRate 1000) and term
  `[2026-01-01, 2026-12-31]`
- **WHEN** recognized recurring revenue is computed for `[2026-01-01, 2026-03-31]`
- **THEN** this line's contribution MUST be 3000 (monthlyRate 1000 × 3 whole months)

#### Scenario: Mid-month start still counts the whole month (whole-month rounding)

- **GIVEN** a `RECURRING MAANDELIJKS` line with monthlyRate 1000 and term `[2026-01-15, 2026-12-31]`
- **WHEN** recognized recurring revenue is computed for `[2026-01-01, 2026-03-31]`
- **THEN** the contribution MUST be 3000 (January, February, March all count in full — the mid-month
  start does NOT reduce the month count, per the whole-month decision)

#### Scenario: Frequency normalization to a monthly rate

- **GIVEN** a `RECURRING KWARTAALS` line with `amount` 3000 and a `RECURRING JAARLIJKS` line with
  `amount` 12000, both with term covering the period
- **WHEN** their monthly rates are computed
- **THEN** each MUST normalize to monthlyRate 1000 (3000 × 1/3, 12000 × 1/12)

#### Scenario: A line whose term does not overlap the period contributes zero

- **GIVEN** a `RECURRING MAANDELIJKS` line with term `[2026-06-01, 2026-12-31]`
- **WHEN** recognized recurring revenue is computed for `[2026-01-01, 2026-03-31]`
- **THEN** this line's contribution MUST be 0 (no term/period overlap)

#### Scenario: No lines yields zero, not an error

- **GIVEN** a `SalesOrder` for the administration with no `SalesOrderLine`s (or no orders at all)
- **WHEN** recognized recurring revenue is computed for any period
- **THEN** the recognized recurring figure MUST be 0 and no exception is thrown

### Requirement: One-off lines SHALL be recognized separately and excluded from the recurring figure

The service SHALL compute recognition for `ONE_OFF` lines separately from the recurring fold and
SHALL NEVER include them in the recognized recurring figure. A `POINT_IN_TIME` one-off line SHALL be
recognized in full (`amount`) when its `recognitionDate` falls within `[from, to]`, else 0. An
`OVER_TIME` one-off line SHALL be prorated across the line's own term (reusing `termOf(L)` — there
are no separate delivery-window fields): `amount × overlapMonths(termOf(L), [from, to]) /
totalTermMonths(L)`, contributing 0 when the total term length is 0. The one-off recognition MAY be
returned as a distinct value but SHALL be reported separately from recurring revenue.

#### Scenario: One-off point-in-time fee inside the period is recognized in full, not as recurring

- **GIVEN** a `ONE_OFF POINT_IN_TIME` line with `amount` 5000 and `recognitionDate` 2026-01-15, and
  a `RECURRING MAANDELIJKS` line with monthlyRate 1500
- **WHEN** recognition is computed for `[2026-01-01, 2026-01-31]`
- **THEN** the recurring figure MUST be 1500 (the monthly line only) AND the one-off figure MUST be
  5000, reported separately — the 5000 MUST NOT be added to the recurring figure

#### Scenario: One-off point-in-time fee outside the period contributes zero

- **GIVEN** a `ONE_OFF POINT_IN_TIME` line with `amount` 5000 and `recognitionDate` 2026-01-15
- **WHEN** recognition is computed for `[2026-02-01, 2026-02-28]`
- **THEN** the one-off figure for that period MUST be 0 and the recurring figure MUST be unaffected

### Requirement: An ARR secondary view SHALL be derived alongside the recognized figure

The service SHALL derive an annualized run-rate (`arr`) as `12 × Σ monthlyRate(L)` over the
`RECURRING` lines whose term contains `to` (in-term at the period end). `arr` is an informational
secondary view and SHALL be distinct from the period `recognized` figure.

#### Scenario: ARR annualizes the current in-term monthly rate

- **GIVEN** two in-term `RECURRING` lines with monthly rates 1000 and 1500 at the period end
- **WHEN** `arr` is derived
- **THEN** `arr` MUST be 30000 (12 × 2500) and MUST be reported separately from `recognized`

### Requirement: The recognized figure SHALL be exposed via an authenticated, RBAC-guarded read endpoint

The system SHALL expose a read-only HTTP endpoint, declared in `appinfo/routes.php` (ADR-016) on a
thin `OCA\Shillinq\Controller\RecognitionController` (ADR-003):
`GET /api/recognition/recurring-revenue?administrationId=<id>&from=<date>&to=<date>` returning
`{ "recognized": <number>, "arr": <number>, "currency": "EUR", "lineCount": <n> }` where `recognized`
is the recurring figure. The endpoint method SHALL carry `#[NoAdminRequired]` (ADR-005) and SHALL
NOT be admin-only; it SHALL reject unauthenticated callers (HTTP 401) and validate `administrationId`
(`^[A-Za-z0-9_.\-]{1,64}$`) and `from`/`to` (ISO `YYYY-MM-DD`, with `from <= to`), returning HTTP
400 on a missing or malformed parameter and HTTP 500 (without a stack trace) on an unexpected
failure. Reads SHALL be scoped to the requested `administrationId` via OpenRegister's ObjectService
so an authenticated user cannot read another administration's orders (ADR-005 Rule 3 / no-admin-idor
— no per-object IDOR). Any user-facing string SHALL be i18n-keyed in English (ADR-007).

#### Scenario: Authenticated user reads the recognized figure for their administration

- **WHEN** an authenticated user requests
  `GET /api/recognition/recurring-revenue?administrationId=<ADMIN>&from=2026-01-01&to=2026-03-31`
  for the seed order `ORDER-2026-0001`
- **THEN** the response MUST be HTTP 200 with `recognized` = 7500 (Line A 3000 + Line C 4500),
  `arr` = 30000, `currency` = `EUR`, `lineCount` = 2, and the one-off 5000 MUST NOT be part of
  `recognized`

#### Scenario: Unauthenticated or malformed request is rejected before the data layer

- **WHEN** the endpoint is called without an authenticated user, or with a missing/malformed
  `administrationId` or non-ISO `from`/`to`
- **THEN** the response MUST be HTTP 401 (unauthenticated) or HTTP 400 (malformed) and MUST NOT read
  any `SalesOrder` data

#### Scenario: A user cannot read another administration's recognition (no IDOR)

- **WHEN** an authenticated user passes an `administrationId` they are not scoped to
- **THEN** the ObjectService-scoped read MUST NOT return that administration's orders, so the
  recognized figure MUST NOT leak cross-administration data (ADR-005 Rule 3)

### Requirement: The recognition computation SHALL remain an isolated ADR-031 exception (no OR-core change)

The recognition arithmetic SHALL be confined to the shillinq `RevenueRecognitionService` and SHALL
NOT introduce a per-row interval-overlap reducer into OpenRegister's aggregation core. The
`SalesOrder` / `SalesOrderLine` schemas SHALL remain fully declarative `config` (owned by the head
change); only the recognition arithmetic and its endpoint are `code`. The exception SHALL be
documented as removable if OpenRegister later provides both missing primitives (a runtime date-window
usable in date-diff arithmetic and an `overlap_days × rate` reducer). This boundary mirrors the
existing `EmuCalculator` guard precedent in the same register.

#### Scenario: Schemas stay declarative; only recognition is code

- **WHEN** this engine change is merged
- **THEN** `SalesOrder` / `SalesOrderLine` storage, enums, lifecycle, audit and RBAC MUST remain
  declarative OpenRegister schema with zero shillinq PHP, AND only the recognition arithmetic +
  read endpoint MUST be PHP, AND no per-row overlap reducer MUST be added to OpenRegister's
  aggregation core

