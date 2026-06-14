# Spec: bookkeeping-period-close

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `./bookkeeping-trial-balance/spec.md` (T2 trial-balance — pre-close
preview), `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL)

## ADDED Requirements

### Requirement: REQ-PC-001: The system SHALL declare a `FiscalPeriod` register replacing T1's stub-string `periodId`

T1 REQ-GL-006 stubbed `GLLine.periodId` as a free-form string with
no FK validation. T2 promotes `FiscalPeriod` to a full register
declared in `lib/Settings/shillinq_register.json` per ADR-024.
Once T2 lands, T1's `GLLine.periodId` field MUST gain an
`x-openregister-relations` block validating the value against the
`FiscalPeriod` register. The change to T1's schema is additive —
existing string values resolve against the new records by exact
match, no data migration.

#### Scenario: Reviewer confirms no parallel period storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or
  `appinfo/info.xml` table declarations naming `fiscal_period`,
  `accounting_period`, or `period_*`
- **THEN** no such classes or declarations SHALL exist; periods
  live in the `FiscalPeriod` register per ADR-022.

#### Scenario: T1's GLLine.periodId resolves against the new register

- **GIVEN** T2 is live and `FiscalPeriod` has records `2026-Q1`,
  `2026-Q2`
- **WHEN** a `GLLine` with `periodId: "2026-Q1"` is saved
- **THEN** the save MUST succeed; **AND** OR's relation engine
  MUST resolve `periodId` to the `FiscalPeriod` record.

### Requirement: REQ-PC-002: The `FiscalPeriod` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `periodId` | string | Yes | Stable identifier (e.g. `2026-Q1`, `2026-M03`, `2026-W12`) — administration MAY choose calendar quarter, calendar month, broken fiscal month, or 13-period retail |
| `name` | string | Yes | Human-readable name (e.g. `Q1 2026`, `March 2026`) |
| `startDate` | date | Yes | Inclusive start of the period |
| `endDate` | date | Yes | Inclusive end of the period |
| `fiscalYear` | string | Yes | The fiscal year this period belongs to (e.g. `FY2026`) |
| `administrationId` | string | Yes | FK to the administration owning the period |
| `state` | enum | Yes | One of `open`, `closing`, `closed`, `audit-locked` (per REQ-PC-004) |
| `closedAt` | datetime | No | Timestamp of `closed` transition |
| `closedBy` | string | No | Actor user ID of the close operator |
| `auditLockedAt` | datetime | No | Timestamp of `audit-locked` transition |
| `auditLockedBy` | string | No | Actor user ID of the auditor who locked |
| `closeReason` | string | No | Optional operator-authored note |
| `reopenedHistory` | array | No | Append-only list of `{reopenedAt, reopenedBy, reason, reclosedAt}` records (per REQ-PC-006) |

Schema.org annotation: `schema:DateRange` (per shillinq config.yaml
`rules.specs`).

#### Scenario: Schema validator accepts a valid quarterly period

- **GIVEN** the schema is loaded
- **WHEN** `{periodId: "2026-Q1", name: "Q1 2026", startDate: "2026-01-01", endDate: "2026-03-31", fiscalYear: "FY2026", administrationId: "adm-1", state: "open"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Overlapping periods within one administration are rejected

- **GIVEN** period `2026-Q1` exists with `startDate: 2026-01-01`, `endDate: 2026-03-31`
- **WHEN** another period `2026-M01` is saved with `startDate: 2026-01-15`, `endDate: 2026-02-15` in the same administration
- **THEN** the save MUST fail with an "overlapping period" error
  surfaced from an OR schema-level uniqueness/range constraint or,
  if not expressible declaratively, a single-method PHP guard per
  ADR-031 exception.

### Requirement: REQ-PC-003: `FiscalPeriod` SHALL declare a declarative open → closing → closed → audit-locked lifecycle

The schema MUST declare an `x-openregister-lifecycle` block with
the following states:

- `open` — postings allowed in this period
- `closing` — preview state: trial balance previewed, postings
  still allowed BUT every new posting triggers a notification to
  the closing operator (so the close report reflects the latest
  state)
- `closed` — postings against this period are rejected (per
  REQ-PC-005); reopen requires elevated role + audit-trailed
  reason (per REQ-PC-006)
- `audit-locked` — irreversible; an auditor has signed off and
  the period is permanently frozen; even the reopen workflow
  rejects this state

Transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `open` | `closing` | operator action (role `bookkeeper`) | none |
| `closing` | `open` | operator action | none — operator may abort the close preview |
| `closing` | `closed` | operator action (role `bookkeeper`) | trial balance per REQ-TB-003 evaluates true |
| `closed` | `open` | reopen workflow per REQ-PC-006 (role `controller` or `administrator`) | not yet `audit-locked` |
| `closed` | `audit-locked` | auditor sign-off (role `auditor`) | none — auditor confirms acceptance |

No PHP service implements transitions; the lifecycle is declared
in the schema. Audit-trail-immutable per ADR-022 records every
transition with actor, before/after, hash chain.

#### Scenario: Closing a period requires the trial balance invariant to hold

- **GIVEN** a period in state `closing` whose underlying trial
  balance fails REQ-TB-003's invariant (hypothetical corrupted
  state)
- **WHEN** the operator attempts to transition to `closed`
- **THEN** the transition MUST be rejected with a "trial balance
  is not balanced" error reporting the delta per REQ-TB-003.

#### Scenario: Audit-locked period is irreversible

- **GIVEN** a period in state `audit-locked`
- **WHEN** any actor (including administrator) attempts to
  transition it back to `closed` or `open`
- **THEN** the transition MUST be rejected with an "audit-locked
  period is immutable" error.

### Requirement: REQ-PC-004: Closed periods SHALL reject new postings via a precondition added to `GLTransaction.post`

T1 REQ-GL-004 declared a `draft → posted` lifecycle on
`GLTransaction` with a balance precondition + all-active-account
precondition. T2 adds a third precondition: the
`GLTransaction.periodId` MUST resolve to a `FiscalPeriod` in state
`open` or `closing`. Postings against `closed` or `audit-locked`
periods MUST be rejected.

The precondition MUST be declared declaratively in
`x-openregister-lifecycle.requires` on `GLTransaction.post`. The
change to T1's schema is additive — existing periodId-as-string
values that don't resolve to a `FiscalPeriod` MUST be treated as
`open` for backwards compatibility during T2 rollout; once every
`GLLine` references a real `FiscalPeriod`, the additive guard
becomes effective.

#### Scenario: Posting against a closed period fails

- **GIVEN** `FiscalPeriod 2026-Q1` in state `closed`
- **WHEN** an operator posts a `GLTransaction` with `periodId: "2026-Q1"`
- **THEN** the post transition MUST fail with a "period is
  closed; reopen first" error.

#### Scenario: Posting against an audit-locked period fails

- **GIVEN** `FiscalPeriod 2025-Q4` in state `audit-locked`
- **WHEN** an operator posts a `GLTransaction` with `periodId: "2025-Q4"`
- **THEN** the post transition MUST fail with a "period is
  audit-locked; correction requires a new period or compensating
  posting in an open period" error.

#### Scenario: Posting against an open period succeeds

- **GIVEN** `FiscalPeriod 2026-Q2` in state `open`
- **WHEN** an operator posts a balanced `GLTransaction` with
  `periodId: "2026-Q2"` and all-active-account references
- **THEN** the transition MUST succeed per T1 REQ-GL-004.

### Requirement: REQ-PC-005: Backdating across closed periods SHALL be prevented at posting time

A `GLTransaction.postingDate` falling within a `closed` or
`audit-locked` period MUST be rejected even if the operator did
NOT explicitly set `periodId`. The implementing engine MUST
resolve `periodId` from `postingDate` (by date range against
`FiscalPeriod`) at posting time and apply REQ-PC-004's precondition
on the resolved period.

#### Scenario: Operator posts in current period with a backdated date in closed period

- **GIVEN** `FiscalPeriod 2026-Q1` is `closed`, `FiscalPeriod 2026-Q2` is `open`
- **WHEN** an operator creates a `GLTransaction` with
  `postingDate: 2026-02-15` (within Q1) without setting
  `periodId`
- **THEN** the engine MUST resolve `periodId` to `2026-Q1`, **AND**
  the post MUST fail with a "backdated posting into closed period"
  error referencing both the resolved period and the close date.

#### Scenario: Same posting in current open period succeeds

- **GIVEN** the same setup
- **WHEN** the operator changes `postingDate` to `2026-05-15`
  (within Q2)
- **THEN** `periodId` resolves to `2026-Q2`, the period is `open`,
  and the post MUST succeed.

### Requirement: REQ-PC-006: Reopening a closed period SHALL require elevated role + audit-trailed reason

The `closed → open` transition MUST require:

1. Actor in role `controller` or `administrator` (per OR's RBAC,
   per ADR-022 — no app-local role table).
2. A `reason` parameter (non-empty string) supplied with the
   transition request.

On successful reopen:

- The `state` returns to `open`.
- A new record is appended to `reopenedHistory`:
  `{reopenedAt: <timestamp>, reopenedBy: <actor>, reason: <text>, reclosedAt: null}`.
- The OR audit-trail-immutable abstraction records the transition
  with actor, reason, timestamp, hash chain (per ADR-022).
- Notifications fire to every administration-`auditor` role member
  per OR's notification engine.

When the period is subsequently reclosed, the matching
`reopenedHistory` record's `reclosedAt` MUST be populated. Periods
in state `audit-locked` MUST NOT be reopenable.

#### Scenario: Reopen without reason fails

- **GIVEN** a `closed` period
- **WHEN** an actor in role `controller` attempts the reopen
  transition with empty reason
- **THEN** the transition MUST fail with a "reopen reason
  required" validation error.

#### Scenario: Reopen by non-elevated role fails

- **GIVEN** a `closed` period
- **WHEN** an actor in role `bookkeeper` attempts the reopen
  transition with a valid reason
- **THEN** the transition MUST fail with an "insufficient
  privilege" error referencing the required roles.

#### Scenario: Successful reopen appends history and notifies auditors

- **GIVEN** a `closed` period and an actor in role `controller`
- **WHEN** they reopen with reason "Correct misclassified
  €1 234 invoice posted before close"
- **THEN** the period MUST return to `open`; **AND** a new
  `reopenedHistory` record MUST be appended; **AND** every
  administration `auditor` MUST receive a notification.

#### Scenario: Reopen of audit-locked period fails

- **GIVEN** an `audit-locked` period
- **WHEN** an administrator attempts to reopen
- **THEN** the transition MUST be rejected per REQ-PC-003 — the
  audit lock is irreversible.

### Requirement: REQ-PC-007: Period close SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
Period Close`) with a `type: index` page binding to the
`FiscalPeriod` register and a `type: detail` page rendering the
fields from REQ-PC-002 alongside actions for each lifecycle
transition allowed by REQ-PC-003. The detail page MUST surface
the trial-balance preview (REQ-TB-005) for the period before
allowing the `closing → closed` transition.

Rendering MUST use `@conduction/nextcloud-vue`'s generic
`CnIndexPage` / `CnDetailPage` components — no bespoke Vue files
(per ADR-024 Tier-4).

#### Scenario: Index page lists periods with state

- **GIVEN** the manifest declares the Period Close pages
- **WHEN** an operator opens
  `/index.php/apps/shillinq/period-close`
- **THEN** `CnIndexPage` MUST render columns including
  `periodId`, `name`, `startDate`, `endDate`, `fiscalYear`,
  `state`.

#### Scenario: Detail page links to trial-balance preview

- **GIVEN** a `FiscalPeriod` in state `closing`
- **WHEN** the operator opens the detail page
- **THEN** the page MUST surface a link to the trial-balance
  filtered to that period (per REQ-TB-004 drill-through pattern)
  **AND** the "Close" action MUST be disabled until the trial
  balance loads and reports balanced.

### Requirement: REQ-PC-008: Year-end close SHALL be explicitly out of scope; T3 owns opening-balance journal generation

T2 declares the period-close lifecycle as a *monthly / quarterly*
mechanism. The year-end concerns — generating the opening-balance
journal for the next fiscal year (per T1 REQ-GL-008's opening
balance shape), rolling retained earnings into the closing
account designated by T1 REQ-CoA-009, and producing a year-end
financial-statement set — are deferred to a future
`add-shillinq-bookkeeping-year-end-close` change.

T2's period lifecycle still operates on year-end periods (a
calendar Q4 / December / month-13 period closes the same way),
but the year-rollover automation does not ship in T2.

#### Scenario: Closing a year-end period does not automatically generate opening balances

- **GIVEN** the last period of a fiscal year (`2025-Q4`) is
  transitioned to `closed`
- **WHEN** the transition completes
- **THEN** no opening-balance journal MUST be auto-generated for
  `FY2026`; the operator MUST author the opening balance journal
  manually per T1 REQ-GL-008 until the year-end-close capability
  ships (T3).
