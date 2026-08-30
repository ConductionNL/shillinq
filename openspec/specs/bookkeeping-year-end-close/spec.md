---
status: done
---

# Spec: bookkeeping-year-end-close

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-period-close (T3)

## Purpose

This specification defines the requirements for bookkeeping year end close in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

@e2e exclude pure backend/data: year-end close postings, reversals and lock behaviour are schema + service + ledger behaviour — not browser-testable

## Requirements

### REQ-YEC-001: The fiscal-year close SHALL be expressed as a declarative lifecycle transition on a `FiscalYear` register

A fiscal year MUST be represented as a `FiscalYear` OpenRegister
record (declared in `lib/Settings/shillinq_register.json` per
ADR-024). The year-end close MUST be expressed as an
`x-openregister-lifecycle` transition `open → closing → closed →
reopened` on that record, per ADR-031. No PHP `YearEndCloseService`
or `JaarafsluitingService` orchestrates the close; the action
side-effects (opening-balance journal, retained-earnings transfer,
dimensional rollover) MUST be declared on the transition.

#### Scenario: Reviewer confirms no year-end-close service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes with method names
  matching `*YearEnd*` / `*Jaarafsluiting*` / `*CloseFiscalYear*`
- **THEN** no such classes SHALL exist; the close logic MUST be
  schema-declared.

### REQ-YEC-002: The `FiscalYear` register SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `FiscalYear` register SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `yearNumber` | integer | Yes | Calendar year reference (e.g. `2026`) — MUST be unique per administration |
| `startDate` | date | Yes | First day of the fiscal year (typically `YYYY-01-01` but supports broken fiscal years) |
| `endDate` | date | Yes | Last day of the fiscal year |
| `state` | enum | Yes | One of `open`, `closing`, `closed`, `reopened` |
| `closingJournalId` | string | No | FK to the `JournalEntry` that posted the year-end retained-earnings transfer |
| `openingJournalId` | string | No | FK to the `JournalEntry` that posted the next-year opening balances |
| `closedAt` | date-time | No | When the close completed (required when `state = closed`) |
| `closedBy` | string | No | NC user id of the actor who completed the close (required when `state = closed`) |
| `reopenedAt` | date-time | No | When a reopen happened (required when `state = reopened`) |
| `reopenedBy` | string | No | NC user id of the reopener (required when `state = reopened`) |
| `reopenReason` | string | No | Operator-supplied justification for the reopen (required when `state = reopened`) |
| `administrationId` | string | Yes | FK to the administration |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `createdAt`,
`updatedAt`, `owner`, `auditTrail`) are not redeclared.

#### Scenario: Year numbers are unique per administration

- **GIVEN** a `FiscalYear` record exists for `yearNumber: 2026,
  administrationId: adm-1`
- **WHEN** another record with the same pair is saved
- **THEN** the save MUST fail with a uniqueness-violation error.

### REQ-YEC-003: The `closing` transition SHALL emit a balanced retained-earnings transfer journal

The `open → closing` lifecycle action MUST create exactly one
`JournalEntry` of sub-type `manual` whose `GLTransaction` posts:

- One credit per revenue account, summing the account's closing
  balance into the closing account
- One debit per expense account, summing the account's closing
  balance into the closing account
- A net debit-or-credit to the configured retained-earnings
  equity account, equal to the net result for the year, so the
  closing account is reset to zero

The closing account is the unique `Account` flagged
`isClosingAccount: true` per `REQ-CoA-009`. The action is
declarative — emitted via the lifecycle's CloudEvent consumed by
the OR engine — not by a PHP `transferRetainedEarnings()` method.

#### Scenario: A profitable year credits retained earnings

- **GIVEN** fiscal year `2026` with revenue closing balance €100,000
  and expense closing balance €70,000 (net profit €30,000)
- **WHEN** the operator triggers the `open → closing` transition
- **THEN** the emitted `JournalEntry` MUST debit each revenue
  account, credit each expense account, and credit the
  configured retained-earnings account €30,000; **AND** the
  `closingJournalId` field MUST point at the new entry.

#### Scenario: A loss-making year debits retained earnings

- **GIVEN** fiscal year `2026` with revenue €70,000 and expense
  €100,000 (net loss €30,000)
- **WHEN** the close is triggered
- **THEN** the emitted journal MUST debit retained-earnings
  €30,000 and the transaction MUST remain balanced.

### REQ-YEC-004: The `closed` transition SHALL emit an opening-balance journal in the next fiscal year

The `closing → closed` lifecycle action MUST create one
`JournalEntry` of sub-type `manual` in the *next* `FiscalYear`
record (looked up by `yearNumber + 1`, `administrationId`) whose
`GLTransaction` carries one balanced line per balance-sheet
account (assets, liabilities, equity), transferring the prior
year's closing balance as the new year's opening balance. P&L
accounts MUST NOT appear in this journal (they reset to zero per
REQ-YEC-003). The next-year `FiscalYear` record MUST be auto-created
in state `open` if it does not yet exist.

#### Scenario: Opening-balance journal carries only balance-sheet accounts

- **GIVEN** fiscal year `2026` closing with assets €500,000,
  liabilities €200,000, equity €300,000
- **WHEN** the close action completes
- **THEN** fiscal year `2027` MUST exist in state `open`; **AND**
  its `openingJournalId` MUST point at a `JournalEntry` with one
  line per asset / liability / equity account, no lines for any
  revenue or expense account.

### REQ-YEC-005: Dimensional rollover SHALL carry active cost centers / projects / kostendragers into the new fiscal year

The close action MUST scan every active dimension record
(`CostCenter`, `KostenDrager`, `Project`, custom dimensions per
`bookkeeping-cost-centers-dimensions`) and ensure they remain
referenceable in the next fiscal year. Dimensions in `archived`
state MUST NOT be rolled over. The rollover is declarative — the
lifecycle action emits CloudEvents consumed by the dimension
registers — no PHP `RolloverService`.

#### Scenario: Archived cost centers do not appear in the new year

- **GIVEN** cost centers `KC-100 (active)`, `KC-200 (archived)`,
  `KC-300 (blocked)` at year-end
- **WHEN** the year closes
- **THEN** in the new year, postings against `KC-100` and `KC-300`
  MUST be allowed (blocked stays blocked, but is still
  referenceable); **AND** postings against `KC-200` MUST fail per
  REQ-CoA-005's archived-rejects-new-postings rule.

### REQ-YEC-006: Reopening a closed year SHALL require an Admin role and SHALL emit a reverse-and-reopen audit chain

The system SHALL satisfy this requirement: Reopening a closed year SHALL require an Admin role and SHALL emit a reverse-and-reopen audit chain.

Per ADR-022 (apps consume OR's RBAC abstraction), the `closed →
reopened` lifecycle transition MUST declare an `admin` role guard
referencing OpenRegister's RBAC role definitions; the transition
MUST NOT be available to the `bookkeeper`, `approver`, or
`auditor` roles. The transition MUST require a non-empty
`reopenReason` and SHALL reverse the closing and opening
journals via two new `JournalEntry` records of sub-type
`reversing`, so the audit chain is fully traceable. The
re-opening MUST be recorded as a CloudEvent and surfaced in the
admin notifications stream.

#### Scenario: A non-admin role cannot reopen a closed year

- **GIVEN** fiscal year `2026` in state `closed`
- **AND** an authenticated operator with `bookkeeper` role
- **WHEN** the operator attempts the `closed → reopened` transition
- **THEN** the transition MUST fail with an authorization error
  naming the missing admin role; **AND** the year's state MUST
  remain `closed`.

#### Scenario: An admin reopening without a reason fails

- **GIVEN** an admin operator and a closed year
- **WHEN** the transition is attempted with no `reopenReason`
- **THEN** the transition MUST fail with a "reopenReason required"
  error.

#### Scenario: A successful reopen emits two reversing journals

- **GIVEN** an admin operator with a valid reason and a closed
  fiscal year `2026` (closing journal `JE-7001`, opening journal in
  2027 `JE-7002`)
- **WHEN** the reopen completes
- **THEN** two new `JournalEntry` records of sub-type `reversing`
  MUST exist referencing `JE-7001` and `JE-7002` respectively;
  **AND** fiscal year `2026` MUST be in state `reopened`; **AND**
  fiscal year `2027`'s `openingJournalId` MUST point at the
  reversed entry chain.

### REQ-YEC-007: Year-end close SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
Fiscal Years`) with a `type: index` page binding to the
`FiscalYear` register and a `type: detail` page for individual
years. The detail page MUST surface the available lifecycle
actions (close, reopen) gated by the operator's role per REQ-YEC-006.
Both pages MUST be rendered by the generic
`@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage`
components — no bespoke Vue files (per ADR-024 Tier-4).

#### Scenario: The detail page surfaces the close action when allowed

- **GIVEN** a `FiscalYear` in state `open`, all periods within it
  in state `closed` (per T3 `bookkeeping-period-close`), and an
  operator with the `approver` role
- **WHEN** the detail page renders
- **THEN** the `Close fiscal year` action MUST be visible.

#### Scenario: The detail page hides the reopen action from non-admins

- **GIVEN** a `FiscalYear` in state `closed` and an operator with
  the `bookkeeper` role
- **WHEN** the detail page renders
- **THEN** the `Reopen fiscal year` action MUST NOT be visible.
