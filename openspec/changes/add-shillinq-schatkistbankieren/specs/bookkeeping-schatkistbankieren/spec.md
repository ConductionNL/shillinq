# Spec: bookkeeping-schatkistbankieren

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-general-ledger (T1)

## ADDED Requirements

### Requirement: REQ-SBK-001 — The system SHALL support schatkistbankieren flows without a parallel ledger

Shillinq MUST support schatkistbankieren — banking with the Treasury's Agentschap per Wet Fido (Wet financiering decentrale overheden) — via T1's regular GL postings for administrations of type `gemeente`, `provincie`, or `waterschap`. There MUST NOT be a parallel schatkist ledger; schatkist deposits and withdrawals post to flagged T1 `Account` records and the "schatkist position" is a derived aggregation (per ADR-022 + ADR-031).

Statutory basis: Wet Fido art. 2c + ministerial Regeling
schatkistbankieren decentrale overheden.

#### Scenario: A non-municipal admin does not see schatkist

- **GIVEN** an administration with `administrationType: "mkb"`
- **WHEN** the dashboard renders
- **THEN** the schatkist menus MUST NOT appear.

#### Scenario: Reviewer confirms no parallel ledger

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `schatkist_`,
  `treasury_`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-SBK-002 — T1 `Account` SHALL carry an `isSchatkistAccount` flag

The T1 `Account` schema (bookkeeping-chart-of-accounts) MUST be
extended (additive — no breaking change) with an optional
`isSchatkistAccount` boolean field (default `false`). Operators
flip this flag on the relevant bank accounts (typically the
operational Treasury deposit account + the working-capital
counterpart).

Per ADR-022, this is a property on the existing register, NOT a
new "schatkist accounts" link table.

#### Scenario: A treasury-officer flags an account

- **GIVEN** account `1110 Treasury Deposit` exists
- **WHEN** the operator flips `isSchatkistAccount: true`
- **THEN** the save MUST succeed AND the schatkist aggregation
  (REQ-SBK-004) MUST include this account on next read.

### Requirement: REQ-SBK-003 — The `SchatkistPosition` schema SHALL declare a daily-aggregated derived position

The `SchatkistPosition` schema MUST declare a daily-aggregated derived position with Schema.org annotation `schema:MonetaryAmount` (the record models a daily balance/position amount rather than the underlying account; the account itself is the T1 `Account` record flagged via REQ-SBK-002).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to gemeente/provincie/waterschap |
| `positionDate` | date | Yes | Business day of the position |
| `openingBalance` | number | Yes | Aggregated from prior day's closing |
| `deposits` | number | Yes | SUM of credit lines on schatkist accounts for the day |
| `withdrawals` | number | Yes | SUM of debit lines on schatkist accounts for the day |
| `closingBalance` | number | Yes | Derived: `openingBalance + deposits - withdrawals` |
| `drempelbedrag` | number | Yes | The current statutory drempelbedrag (per `schatkist-thresholds.json` seed) |
| `aboveDrempel` | boolean | Yes | Derived: `closingBalance > drempelbedrag` (signals operator action needed) |

`SchatkistPosition` is a **snapshot record** generated daily by a
scheduled workflow; it MUST NOT be hand-edited by operators (the
underlying source is GL postings).

#### Scenario: A daily position aggregates correctly

- **GIVEN** `gem-a` has schatkist accounts with €5.000.000 prior
  closing AND today has €200.000 deposits + €150.000 withdrawals
- **WHEN** today's `SchatkistPosition` is generated
- **THEN** `openingBalance: 5000000`, `deposits: 200000`,
  `withdrawals: 150000`, `closingBalance: 5050000` MUST be set.

### Requirement: REQ-SBK-004 — The daily position SHALL be a declarative aggregation, not a service-based recompute

The `deposits` / `withdrawals` / `closingBalance` fields MUST be
populated via `x-openregister-aggregations` over `GLLine` (T1)
joined with `Account` (T1) filtered by `isSchatkistAccount = true`
for the position's `positionDate`. Per ADR-031, no
`SchatkistAggregationService`.

The aggregation MUST distinguish `side: debit` (withdrawals) from
`side: credit` (deposits) per the T1 convention (REQ-GL-003).

#### Scenario: An aggregation excludes non-schatkist postings

- **GIVEN** `gem-a` has both schatkist account `1110` and regular
  bank account `1100` with postings on the same day
- **WHEN** the aggregation runs for that day
- **THEN** only `1110`'s postings MUST contribute to `deposits`
  and `withdrawals`.

### Requirement: REQ-SBK-005 — The drempelbedrag SHALL ship as versioned seed data

`lib/Settings/seeds/schatkist-thresholds.json` MUST hold the
drempelbedrag formula per administration size. Per the current
ministerial regeling:

| Record | Threshold formula | Source |
|---|---|---|
| `small-gemeente` | 0.75% × begroting (begrotingstotaal ≤ €500M) | Regeling schatkistbankieren art. 2 |
| `large-gemeente` | 0.5% × begroting (begrotingstotaal > €500M) | Regeling schatkistbankieren art. 2 |
| `provincie` | 0.5% × begroting | same |
| `waterschap` | 1.0% × begroting | same |

The seed records carry `effectiveFrom`/`effectiveTo` windows. The
operator's administration record carries the
`begrotingstotaal` (the per-administration begrotingsbeslag for
the year); shillinq computes the per-admin drempelbedrag as
`begrotingstotaal × percentage` and stores the result on the
daily `SchatkistPosition.drempelbedrag` field.

#### Scenario: A small gemeente has a 0.75% drempelbedrag

- **GIVEN** `gem-a` is a `small-gemeente` with
  `begrotingstotaal: 100000000` (€100M)
- **WHEN** today's `SchatkistPosition` is generated
- **THEN** `drempelbedrag` MUST equal €750.000 (0.75% × €100M).

### Requirement: REQ-SBK-006 — Threshold-crossing events SHALL emit notifications via `x-openregister-notifications`

A notification MUST fire via `x-openregister-notifications` to operators with role `treasury-officer` when `closingBalance` crosses the `drempelbedrag` (transitions from `aboveDrempel: false → true`). The notification text MUST cite the current amount over the drempel. Per ADR-022, no app-local notification service.

#### Scenario: Crossing the drempel notifies the treasury officer

- **GIVEN** `gem-a` had `closingBalance: 700000` (below the
  €750.000 drempel) yesterday
- **WHEN** today's aggregation puts `closingBalance: 800000`
  (above the drempel)
- **THEN** an NC notification MUST appear for every user holding
  `treasury-officer` on `gem-a` with text of the form
  "Schatkistpositie €800.000 overschrijdt drempel €750.000 met
  €50.000".

### Requirement: REQ-SBK-007 — The daily aggregation SHALL be an OR `ScheduledWorkflow`, not a custom job

The daily-position aggregation MUST be declared as an OR
`ScheduledWorkflow` (per ADR-031 §"Background jobs that walk an
object queue" path 2). The cron MUST default to once-per-business-
day (operator-configurable). shillinq MUST NOT author a PHP
`*Job` class for this — the workflow shape is exactly what OR's
scheduled workflow supports.

#### Scenario: The daily workflow generates a position for each schatkist admin

- **GIVEN** the cron fires on a business day
- **WHEN** the workflow runs
- **THEN** for each administration with at least one
  `isSchatkistAccount: true` account, a `SchatkistPosition`
  record MUST be created for that day.

### Requirement: REQ-SBK-008 — A schatkist-position widget SHALL be declared via `x-openregister-widgets`

A widget MUST be declared via `x-openregister-widgets` on
`SchatkistPosition` showing the current balance, the drempel, and
the over/under status (with green/yellow/red bands). Consumable
by `CnDashboardPage` per ADR-024. No bespoke Vue.

#### Scenario: The dashboard renders the schatkist widget

- **GIVEN** a `treasury-officer` opens the shillinq dashboard
- **WHEN** the page renders
- **THEN** the widget MUST display today's closingBalance, the
  drempelbedrag, and the over/under indicator.

### Requirement: REQ-SBK-009 — Schatkist data SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Overheid >
Schatkist-positie` with:

- A `type: dashboard` page for the current state widget.
- A `type: index` page binding to `SchatkistPosition` (historical
  daily positions).

Visibility predicated on `administrationType ∈ {gemeente,
provincie, waterschap}`.

#### Scenario: A treasury officer drills into a historical day

- **GIVEN** a treasury-officer opens the schatkist index
- **WHEN** they click on a row from a prior day
- **THEN** the detail page MUST render via `CnDetailPage` showing
  that day's deposits, withdrawals, opening/closing balance, and
  the contributing GL transactions (drill-through).

### Requirement: REQ-SBK-010 — Transfers to and from the Treasury SHALL be modelled as `JournalEntry` records, not bespoke transfers

Treasury deposits and withdrawals MUST be modelled as `JournalEntry` records (per T1 `bookkeeping-journal-entries`) of sub-type `manual` — typically dual-line (`debit: 1110 Treasury Deposit`, `credit: 1100 Working Capital`). The operator authors the journal; the system MUST NOT auto-issue treasury transfers.

#### Scenario: A treasury deposit is a regular journal entry

- **GIVEN** the operator transfers €100.000 from working capital
  to treasury
- **WHEN** they create the corresponding `JournalEntry`
- **THEN** the entry MUST post via the standard T1 lifecycle (no
  special "schatkist-transfer" sub-type) AND the daily
  aggregation MUST pick up the change automatically on next run.

### Requirement: REQ-SBK-011 — Audit trail and retention SHALL be consumed from OR's abstractions

Every `SchatkistPosition` and `Account` (flag change) operation MUST be audited via OR's audit-trail-immutable (ADR-022). Retention MUST be declared via `x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }` (financial records — 7 years).

#### Scenario: A historical position is queryable

- **GIVEN** a `SchatkistPosition` from 2021
- **WHEN** queried in 2026 (within 7-year retention)
- **THEN** the record MUST be returned with audit trail intact.
