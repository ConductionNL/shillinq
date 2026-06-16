# Spec: bookkeeping-period-close

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T2 `bookkeeping-trial-balance`, T1 `bookkeeping-general-ledger`

## ADDED Requirements

### Requirement: REQ-PC-001 — The system SHALL promote `FiscalPeriod` from a stub to a full OpenRegister-managed register

T1's `GLLine.periodId` is a stub string. T2 MUST declare `FiscalPeriod`
as a full register in `lib/Settings/shillinq_register.json`. When
`FiscalPeriod` is declared, T1's `GLLine.periodId` field MUST gain an
additive `x-openregister-relations` block resolving against
`FiscalPeriod.periodId`. Existing GL lines with string-valued
`periodId` continue to resolve by exact match; no destructive
migration is required.

Per ADR-022, no parallel `fiscal_period` DB table or PHP Mapper class
SHALL be created. The register is exposed through OR's generic CRUD
HTTP surface.

#### Scenario: FiscalPeriod register exists in the register file

- **GIVEN** `lib/Settings/shillinq_register.json` is loaded
- **WHEN** scanned for schema definitions
- **THEN** a schema named `FiscalPeriod` MUST exist with the fields
  declared in REQ-PC-002.

#### Scenario: Reviewer confirms no parallel DB table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `fiscal_period`
- **THEN** no such class SHALL exist.

### Requirement: REQ-PC-002 — The `FiscalPeriod` schema SHALL declare a fixed minimum field set

The `FiscalPeriod` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `periodId` | string | Yes | Unique period code (e.g. `2026-01`, `2026-Q1`) |
| `name` | string | Yes | Human-readable label (e.g. `Januari 2026`) |
| `startDate` | date | Yes | First day of the period |
| `endDate` | date | Yes | Last day of the period |
| `fiscalYear` | string | Yes | Fiscal year this period belongs to (e.g. `2026`) |
| `administrationId` | string | Yes | FK to the owning Administration |
| `state` | enum | Yes | One of `open`, `closing`, `closed`, `audit-locked` |
| `closedAt` | datetime | No | Timestamp when state transitioned to `closed` |
| `closedBy` | string | No | UUID of user who closed the period |
| `auditLockedAt` | datetime | No | Timestamp when state transitioned to `audit-locked` |
| `auditLockedBy` | string | No | UUID of auditor who locked the period |
| `closeReason` | string | No | Operator-provided reason for closing |
| `reopenedHistory` | array | No | Array of `{reopenedAt, reopenedBy, reason}` objects tracking each reopen |

OpenRegister built-in fields are not redeclared per
`adr-000-data-model.md`.

#### Scenario: Schema validator accepts a minimal FiscalPeriod

- **GIVEN** the `FiscalPeriod` schema is loaded
- **WHEN** an object `{periodId: "2026-01", name: "Januari 2026", startDate: "2026-01-01", endDate: "2026-01-31", fiscalYear: "2026", administrationId: "adm-1", state: "open"}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an invalid state

- **GIVEN** the schema
- **WHEN** an object with `state: "archief"` is validated
- **THEN** validation MUST fail with an enum-violation error.

### Requirement: REQ-PC-003 — The `FiscalPeriod` register SHALL declare an `open → closing → closed → audit-locked` lifecycle

The `FiscalPeriod` schema MUST declare an `x-openregister-lifecycle`
block (per ADR-031) with the following states and transitions:

| From | To | Trigger | Guard / Action |
|---|---|---|---|
| `open` | `closing` | operator starts close | trial balance invariant verified (REQ-TB-003) |
| `closing` | `closed` | operator confirms close | all open AP/AR invoices in period are settled or explicitly acknowledged |
| `closed` | `open` | operator with elevated `period-closer` role | `closeReason` and audited reopen reason recorded |
| `closed` | `audit-locked` | auditor (`auditor` role) | irreversible; writes `auditLockedAt` + `auditLockedBy` |
| `audit-locked` | *(any)* | — | **FORBIDDEN** — `audit-locked` is a terminal state |

The `closing → closed` precondition SHOULD be declared via
`x-openregister-lifecycle.requires`. If the OR engine cannot express
the check declaratively, a single-method PHP guard
(`OCA\Shillinq\Lifecycle\PeriodCloseGuard`) MAY be referenced per
ADR-031 §"PHP guards remain a legitimate seam".

#### Scenario: Opening a closed period requires elevated role

- **GIVEN** period `2026-01` in state `closed`
- **WHEN** a user with only `bookkeeper` role attempts to reopen it
- **THEN** the lifecycle transition MUST be rejected with an
  "insufficient role" error.

#### Scenario: Audit-locked period cannot be reopened

- **GIVEN** period `2025-12` in state `audit-locked`
- **WHEN** any user — including `auditor` — attempts any lifecycle
  transition
- **THEN** the transition MUST be rejected with an "audit-locked,
  irreversible" error.

### Requirement: REQ-PC-004 — Postings against a closed `FiscalPeriod` SHALL be rejected by an OR lifecycle precondition

The `GLTransaction.post` precondition list (T1, REQ-GL-004) MUST
gain an additive closed-period rejection clause: if the target
`FiscalPeriod.state` is `closed` or `audit-locked`, the `post`
transition MUST be rejected. This clause MUST be declared in the
schema register (`x-openregister-lifecycle.requires` on
`GLTransaction.post`), not in a PHP service. Year-end close (T3)
may add a separate end-of-year-locked clause.

#### Scenario: Backdating a posting into a closed period fails

- **GIVEN** period `2026-01` in state `closed`
- **WHEN** an operator attempts to post a `GLTransaction` with a
  date of `2026-01-15`
- **THEN** the `post` transition MUST be rejected with a
  "period closed, posting not allowed" error.

#### Scenario: Posting into an open period succeeds normally

- **GIVEN** period `2026-02` in state `open`
- **WHEN** an operator posts a balanced `GLTransaction` dated
  `2026-02-10`
- **THEN** the posting MUST succeed without a period-close error.

### Requirement: REQ-PC-005 — The `FiscalPeriod` schema SHALL carry `x-openregister-audit: true`

The `FiscalPeriod` schema MUST carry `x-openregister-audit: true` so every lifecycle transition is captured in OR's immutable audit trail. Every `FiscalPeriod` lifecycle transition (open → closing, closing →
closed, closed → open, closed → audit-locked) MUST be recorded in
OR's immutable audit trail. No additional app-local audit table is
permitted per ADR-022.

#### Scenario: Closing a period writes an audit event

- **GIVEN** period `2026-01` transitions from `open` to `closing`
- **WHEN** the OR audit-log endpoint is queried for `FiscalPeriod`
  objects with the `2026-01` UUID
- **THEN** an audit event MUST exist recording the actor, the
  `open → closing` transition, and the timestamp.

### Requirement: REQ-PC-006 — Reopening a closed period SHALL record the actor and reason in the `reopenedHistory` array

Reopening a closed period MUST append an entry recording the actor, timestamp, and reason to the `reopenedHistory` array. When an operator with `period-closer` role transitions a `FiscalPeriod`
from `closed` back to `open`, the lifecycle MUST append an entry
`{reopenedAt: <iso-timestamp>, reopenedBy: <user-uuid>, reason: <string>}`
to `reopenedHistory`. The original `closedAt` and `closedBy` values
MUST be preserved on the object.

#### Scenario: Reopen history accumulates across multiple reopens

- **GIVEN** period `2026-01` has been closed and reopened twice
- **WHEN** the period object is inspected
- **THEN** `reopenedHistory` MUST contain exactly 2 entries, each
  with `reopenedAt`, `reopenedBy`, and a non-empty `reason`.

### Requirement: REQ-PC-007 — Period close SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a `Bookkeeping > Period Close`
navigation entry with:
- A `type: index` page listing all `FiscalPeriod` records for the
  current administration, sorted by `startDate` descending.
- A `type: detail` page surfacing the lifecycle action buttons
  (Start Close, Confirm Close, Reopen, Audit Lock) and a link to
  the trial-balance preview for the period.

Year-end close is explicitly deferred to T3 and MUST NOT appear in
the T2 manifest.

#### Scenario: Period Close index page lists fiscal periods

- **GIVEN** the manifest declares the Period Close pages
- **WHEN** an operator opens `Bookkeeping > Period Close`
- **THEN** the page MUST render via `CnIndexPage` showing periods
  with columns `periodId`, `name`, `fiscalYear`, `state`.

#### Scenario: Detail page surfaces lifecycle actions

- **GIVEN** period `2026-01` in state `open`
- **WHEN** the operator opens the detail page
- **THEN** the "Start Close" action button MUST be visible and
  enabled; "Audit Lock" MUST be visible but disabled (requires
  `closed` state as precondition).
