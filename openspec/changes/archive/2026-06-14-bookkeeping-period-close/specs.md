# Specification — Period Close

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** bookkeeping-general-ledger, bookkeeping-trial-balance
**Primary features:** AI-powered Close Assistant, Guided Period Close

## Glossary

- **Period Close**: the monthly or quarterly close workflow that transitions a
  `PeriodClose` from `open` to `closed` state, freezing the period for audit.
- **Reopen**: the workflow that transitions a `closed` period back to `open`,
  requiring elevated role + audit-trailed reason.
- **Audit Lock**: the workflow that transitions a `closed` period to
  `audit-locked` state (irreversible), performed by auditors.
- **Close Reason**: operator-supplied text documenting *why* a period was
  reopened (e.g., "Posted correction for invoice #2026-0042").
- **Task Flag**: AI-detected warning (e.g., "5 open AP invoices not yet paid")
  surfaced in the pre-close checklist.

## REQ-PC-001: PeriodClose Register Schema

The `PeriodClose` register shall define the following schema (per ADR-011
schema-org standards):

```
Schema: PeriodClose
Type: schema:Event
Description: An accounting period with lifecycle state, close audit-trail, and
             task checklist for guided month-end/quarter-end close workflows.

Fields:
- periodId (string, required): Unique identifier for the period (e.g., "2026-01")
- administrationId (string, required): FK to Administration
- startDate (date, required): First day of the period
- endDate (date, required): Last day of the period
- fiscalYear (integer, required): Fiscal year (e.g., 2026)
- state (enum, required): One of open, closing, closed, audit-locked
- closedAt (datetime, optional): Timestamp when period was closed
- closedBy (string, optional): UserId who closed the period
- auditLockedAt (datetime, optional): Timestamp when period was audit-locked
- auditLockedBy (string, optional): UserId who audit-locked the period
- closeReason (text, optional): Reason for reopen (only set on transition
                                closed → open)
- reopenedHistory (array, optional): Append-only list of {closedAt, closedBy,
                                     reopenedAt, reopenedBy, closeReason}
- taskChecklistItems (array, optional): [{id, category, description, resolved,
                                          resolvedAt, resolvedBy}]
- aiFlags (array, optional): [{id, severity, message, category, detectedAt}]
```

#### Scenario: Period created on administration setup

```gherkin
GIVEN a new Administration is created with startDate=2026-01-01, endDate=2026-12-31
WHEN the administration is saved
THEN a PeriodClose record is created with:
  periodId = "2026-01"
  state = "open"
  closedAt = null
  aiFlags = []
  taskChecklistItems = []
```

## REQ-PC-002: Period Lifecycle Declaration

Per ADR-031, the `PeriodClose` register shall declare an `x-openregister-lifecycle`
state machine with the following states and transitions:

```
States: open, closing, closed, audit-locked

Transitions:
- open → closing: initiated by operator with period-closer role
- closing → closed: automatic transition (no delay) on completion of close task checklist
- closed → open: initiated by operator with period-closer role + close reason required
- closed → audit-locked: initiated by auditor with auditor role (irreversible)

Preconditions:
- open → closing: no open period in closing state for same administration
- closing → closed: all mandatory checklist items marked resolved (AP invoices, AR invoices)
- closed → open: close reason supplied (audit-trailed)
- closed → audit-locked: no open GL transactions dated after period endDate

Side effects:
- → closed: set closedAt=now, closedBy=current user
- → audit-locked: set auditLockedAt=now, auditLockedBy=current user (irreversible)
- closed → open: append {closedAt, closedBy, reopenedAt, reopenedBy, closeReason} to reopenedHistory
```

#### Scenario: Operator closes a period

```gherkin
GIVEN a PeriodClose in state "open"
AND all mandatory checklist items resolved (AP count = 0, AR count = 0)
WHEN the operator clicks "Close Period" with their period-closer role
THEN the state transitions open → closing → closed (atomic)
AND closedAt is set to now
AND closedBy is set to the operator's userId
AND audit trail logs the transition
```

#### Scenario: Auditor audit-locks a closed period

```gherkin
GIVEN a PeriodClose in state "closed"
WHEN an auditor with auditor role clicks "Lock for Audit"
THEN the state transitions closed → audit-locked
AND auditLockedAt is set to now
AND auditLockedBy is set to the auditor's userId
AND the transition is irreversible
```

#### Scenario: Operator reopens a closed period

```gherkin
GIVEN a PeriodClose in state "closed" (NOT audit-locked)
AND the operator has period-closer role
WHEN the operator clicks "Reopen" with a close reason (e.g., "Posted correction")
THEN the state transitions closed → open
AND reopenedHistory is appended with {closedAt, closedBy, reopenedAt, reopenedBy, closeReason}
AND the audit trail logs the reopen + reason
```

## REQ-PC-003: Closed-Period Posting Rejection

T1's `GLTransaction.post` precondition list shall be additively augmented with
the closed-period rejection clause per ADR-031:

```
New precondition:
- If the transaction's GL lines include a periodId that resolves to a
  PeriodClose record in state "closed" OR "audit-locked", then reject the
  posting with HTTP 403 + message "Period {periodId} is closed; reopen required
  for corrections."
```

#### Scenario: Posting rejected against closed period

```gherkin
GIVEN a PeriodClose record for "2026-01" in state "closed"
AND a new GLTransaction with GL lines dated 2026-01-15, periodId="2026-01"
WHEN the transaction is posted
THEN the posting is rejected with HTTP 403
AND the message is "Period 2026-01 is closed; reopen required for corrections"
AND the audit trail logs the rejection
```

#### Scenario: Posting accepted against open period

```gherkin
GIVEN a PeriodClose record for "2026-01" in state "open"
AND a new GLTransaction with GL lines dated 2026-01-15, periodId="2026-01"
WHEN the transaction is posted
THEN the posting succeeds with HTTP 201
```

## REQ-PC-004: AI Close Assistant Task Detection

The `PeriodCloseAssistantService` shall implement automated detection of
incomplete pre-close tasks:

```
Task categories to detect and flag:
1. Open AP Transactions: invoices/dunning notices dated ≤ period endDate,
   status != "paid"
2. Open AR Transactions: invoices/credit notes dated ≤ period endDate,
   status != "collected"
3. Unreconciled Bank Receipts: bank deposits with no GL posting match,
   dated ≤ period endDate
4. Outstanding Expense Claims: submitted claims with status in
   [submitted, approved, pending-reimbursement]

Detection logic:
- Query via ObjectService for each task category
- Summarize results (e.g., "5 open AP invoices, total €2,450")
- Call Claude API (ChatService) with: summary + period metadata
  → return AI-generated narrative (e.g., "Outstanding invoices from
  vendors Smith & Co (€800) and Green Services (€1,650) require
  payment before close")
- Format as array of {severity, message, category, detectedAt}
```

#### Scenario: AI flags detect open AP invoices

```gherkin
GIVEN a PeriodClose for "2026-01" in state "open"
AND 3 open AP invoices dated 2026-01-28, totaling €5,200
WHEN the close detail page is opened
AND the AI close assistant runs
THEN aiFlags array contains:
  {severity: "warning", category: "ap",
   message: "3 outstanding AP invoices totaling €5,200 require payment or
             adjustment before period close"}
AND the flag is displayed inline in the AP checklist section
```

## REQ-PC-005: Guided Close Workflow UI

The `PeriodCloseDetail.vue` Vue component shall render the guided close workflow:

```
Page layout:
- Header: Period {periodId} ({startDate} – {endDate}), state badge
- Section 1: Period Metadata
  - Administration: {name}
  - Fiscal Year: {year}
  - Period dates: {startDate} – {endDate}
- Section 2: Lifecycle Action Buttons (conditional per state)
  - If state="open": "Start Close" button → state → closing → closed
  - If state="closed": "Reopen" button (modal for close reason), "Lock for Audit" button
  - If state="audit-locked": (read-only, no buttons)
- Section 3: Task Checklist (expandable sections)
  - AP Invoices: count, total, expandable list
  - AR Invoices: count, total, expandable list
  - Bank Reconciliation: unreconciled count, expandable list
  - Expense Claims: outstanding count, expandable list
  - Each item: checkbox (mark resolved), AI-generated flag inline
- Section 4: AI Close Assistant
  - Display all aiFlags in one consolidated warning panel
  - Each flag: severity (warning/info), message, category
- Section 5: Trial Balance Preview
  - Link to trial-balance detail page for this period
- Section 6: Close Audit Trail
  - Log of close/reopen/audit-lock transitions with timestamps, users, reasons
```

#### Scenario: Operator views period close detail page

```gherkin
GIVEN a PeriodClose for "2026-01" in state "closing"
WHEN the operator opens the detail page
THEN the page displays:
  - Period metadata (dates, fiscal year, administration)
  - "Start Close" button (disabled, period is closing)
  - Task checklist with 2 AP, 1 AR, 0 bank, 1 expense claim
  - AI flags panel with "3 open AP invoices" warning
  - Trial balance preview link
  - Close audit trail (empty, no transitions yet)
```

## REQ-PC-006: Reopen Workflow with Audit Trail

When an operator reopens a closed period, the system shall capture and persist
the close reason:

```
Reopen workflow:
1. Operator clicks "Reopen" button on detail page (state="closed")
2. Modal dialog opens: "Reason for reopen: [text field]"
3. On submit:
   - Validate close reason is non-empty
   - Transition state: closed → open
   - Append to reopenedHistory: {closedAt, closedBy, reopenedAt, reopenedBy, closeReason}
   - Audit trail logs the transition + reason
4. Page refreshes; state="open"
```

#### Scenario: Reopen captures audit trail

```gherkin
GIVEN a PeriodClose for "2026-01" in state "closed"
AND closedAt="2026-02-05 17:30:00", closedBy="alice@org.nl"
WHEN the operator alice reopens with reason "Posted correction for invoice #123"
THEN reopenedHistory is appended with:
  {closedAt: "2026-02-05 17:30:00",
   closedBy: "alice@org.nl",
   reopenedAt: "2026-02-06 09:15:00",
   reopenedBy: "alice@org.nl",
   closeReason: "Posted correction for invoice #123"}
AND the audit trail logs the reopen event
```

## REQ-PC-007: Manifest Navigation Entry

The `src/manifest.json` shall declare one new navigation entry + detail page
binding:

```
Manifest entries:
- Menu: Bookkeeping > Period Close (icon: calendar-lock or similar)
  - Route: /period-close/{periodId}
  - Component: PeriodCloseDetail.vue
  - Bound to: PeriodClose register

Navigation:
- Main menu item: "Period Close" under Bookkeeping section
- Click → list view of all PeriodClose records for the current administration
- Click period row → detail page for that period
```

## REQ-PC-008: Authorization and Role Gates

Period close actions shall enforce role-based authorization:

```
Roles:
- period-closer: can transition open → closing, closed → open
- auditor: can transition closed → audit-locked
- admin: can do all transitions (per Nextcloud admin default)

Enforcement:
- open → closing: requires period-closer role (checked in precondition)
- closed → open: requires period-closer role + close reason
- closed → audit-locked: requires auditor role (checked in precondition)

Frontend:
- Hide "Start Close" button if user lacks period-closer role
- Hide "Reopen" button if user lacks period-closer role
- Hide "Lock for Audit" button if user lacks auditor role
```

## REQ-PC-009: Idempotent Seed Data Generation

The repair step shall create `PeriodClose` records for every open/future period,
idempotently:

```
Backfill algorithm:
1. For each Administration record:
2. For each distinct period in the fiscal year:
   - Query: PeriodClose where administrationId=X and periodId=Y
   - If not found: create new record with state="open"
   - If found: skip (idempotent)
3. Mark periods already in closed/audit-locked state (from production data)
   and preserve their state

Result: All periods have a PeriodClose record; reopens idempotent
```

## Summary of New Entities

| Entity | Register | Schema | Fields | Primary Spec |
|--------|----------|--------|--------|--------------|
| PeriodClose | shillinq | PeriodClose | periodId, administrationId, startDate, endDate, fiscalYear, state, closedAt, closedBy, auditLockedAt, auditLockedBy, closeReason, reopenedHistory, taskChecklistItems, aiFlags | bookkeeping-period-close |

## Summary of Modified Entities

| Entity | Modification | Primary Spec |
|--------|--------------|--------------|
| GLTransaction | Additive precondition: reject posting if periodId resolves to closed/audit-locked PeriodClose | bookkeeping-period-close |
