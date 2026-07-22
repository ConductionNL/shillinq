# Spec: recurring-revenue-recognition

**Status:** in-progress
**Scope:** shillinq
**Kind:** code (engine leg of the `order-revenue-recognition` chain — ADR-032; `depends_on: [order-revenue-recognition]`)

This delta realizes the recognition computation the head change `order-revenue-recognition`
**defined** but deferred (its requirement "The recognition computation SHALL be an ADR-031
exception, isolated behind a service in the chained code change"). It adds the normative behaviour
of `OCA\Shillinq\Recognition\RevenueRecognitionService` and the read endpoint. The data-model
requirements (`SalesOrder` / `SalesOrderLine` schemas) are owned by the head and are NOT modified.

## ADDED Requirements

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
