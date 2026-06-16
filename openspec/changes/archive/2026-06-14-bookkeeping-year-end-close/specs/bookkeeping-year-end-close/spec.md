# Specification: Fiscal Year-End Close

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-base (advanced engine features)
**Kind:** config

## Overview

This specification declares the fiscal year-end close capability for
Shillinq, defining the schemas, lifecycles, checklist validations,
and closing-entry generation rules that operationalise the transition
from one fiscal year to the next.

**Depends on:**
- `bookkeeping-general-ledger` (T1) — for GL transactions and journal
  entries
- `bookkeeping-chart-of-accounts` (T1) — for the chart of accounts
  and account properties
- `bookkeeping-compliance` (T2) — for trial balance, period-close
  machinery, and immutable-period enforcement
- `bookkeeping-fixed-assets-depreciation` (T4-base) — for automated
  asset depreciation during closing (optional; required only if
  fixed-asset depreciation is enabled)

## Requirements

### Requirement: REQ-YEC-001: Declare ClosingEntry register

The `ClosingEntry` register (schema.org: `schema:Thing`) tracks manual
and automated closing entries, reversals, and accrual postings during
the year-end close process.

**Properties:**
- `closingEntryNumber` (string, required) — unique identifier (e.g.,
  CE-2025-001)
- `fiscalYearId` (string, required) — FK to FiscalYear
- `entryDate` (date, required) — posting date of the closing entry
- `entryType` (enum, required) — one of: revenue-closing,
  expense-closing, accrual-reversal, depreciation, retained-earnings,
  opening-balance, manual
- `description` (text, optional) — operator-authored description
- `automationTemplate` (string, optional) — FK to ClosingEntryTemplate
  if generated automatically
- `amount` (MonetaryAmount, required) — total closing-entry amount
- `glTransactionId` (string, optional) — FK to GLTransaction if
  materialised as GL posting
- `approvalStatus` (enum, required) — one of: draft, pending-approval,
  approved, posted, reversed
- `approvedBy` (string, optional) — FK to Person who approved
- `approvedAt` (datetime, optional) — approval timestamp
- `administrationId` (string, required) — FK to Administration

**Relations:**
- → FiscalYear (many-to-one)
- → ClosingEntryTemplate (many-to-one)
- → GLTransaction (one-to-one)
- → Administration (many-to-one)

#### Scenario: Create a manual closing entry

**GIVEN** a fiscal year in "in-progress" state, an operator has
financial-officer role, and the chart of accounts is loaded with
revenue accounts 4000–4999 and the closing account 9900.

**WHEN** the operator creates a `ClosingEntry` with `entryType:
revenue-closing`, `amount: 125000 EUR`, and `description: "Close
revenues for FY 2025"`.

**THEN** the closing entry is created with `approvalStatus: draft`,
and a notification is sent to the CFO for approval. The entry does not
yet materialise as a GL transaction.

#### Scenario: Reverse a prior-year accrual

**GIVEN** FY 2024 is closed, FY 2025 is open, and the operator needs
to reverse the FY 2024 accrual for accounts payable (account 9700).

**WHEN** the operator creates a `ClosingEntry` with `entryType:
accrual-reversal`, `automationTemplate: "Accrual Reversal"`, FY 2024
source entries, `amount: 45000 EUR`.

**THEN** the system generates the reversal entry (debit 5900 –
adjustments, credit 9700 – accruals payable) and posts it to FY 2025
GL.

---

### Requirement: REQ-YEC-002: Declare RetainedEarnings register

The `RetainedEarnings` register (schema.org: `schema:Thing`) tracks
the retained-earnings account balance, rollfroward across fiscal
years, and distributions.

**Properties:**
- `retainedEarningsId` (string, required) — unique identifier
- `fiscalYearId` (string, required) — FK to FiscalYear
- `openingBalance` (MonetaryAmount, required) — retained earnings at
  start of FY
- `netIncome` (MonetaryAmount, required) — net income for the FY
  (revenue minus expenses)
- `distributions` (MonetaryAmount, optional) — dividends or
  distributions paid in the FY
- `closingBalance` (MonetaryAmount, required) — retained earnings at
  end of FY
- `closingEntryId` (string, optional) — FK to ClosingEntry that
  materialised the closing entry
- `administrationId` (string, required) — FK to Administration

**Relations:**
- → FiscalYear (many-to-one)
- → ClosingEntry (one-to-one)
- → Administration (many-to-one)

#### Scenario: Roll forward retained earnings

**GIVEN** FY 2024 closed with retained earnings of €500,000, FY 2025
is newly open with opening balance €500,000, and FY 2025 net income
is calculated as €250,000 (revenues €1,000,000 – expenses €750,000).

**WHEN** the closing lifecycle for FY 2025 executes the
retained-earnings closing entry.

**THEN** the system calculates: FY 2025 closing retained earnings =
€500,000 (opening) + €250,000 (net income) = €750,000, materialises
the closing entry (debit 9900 – closing account €250,000, credit 9750
– retained earnings €250,000), and updates the `RetainedEarnings`
record with all four balances.

---

### Requirement: REQ-YEC-003: Declare ClosingAccount register

The `ClosingAccount` register (schema.org: `schema:Thing`) designates
the single closing/income-summary account that all revenue and expense
accounts close through during year-end close.

**Properties:**
- `accountNumber` (string, required) — FK to Account (typically 9900
  or 9999)
- `administrationId` (string, required) — FK to Administration (one
  closing account per administration)
- `isActive` (boolean, optional, default true) — whether this is the
  active closing account
- `effectiveFrom` (date, optional) — if multiple accounts exist, which
  is active in which period

**Relations:**
- → Account (many-to-one)
- → Administration (many-to-one)

#### Scenario: Configure the closing account

**GIVEN** the chart of accounts includes account 9900 – Income Summary
(type: equity), and the administration has never designated a closing
account.

**WHEN** the administration setup wizard or manifest entry selects
account 9900 as the closing account for year-end close.

**THEN** the system records the `ClosingAccount` entry, and all
subsequent closing-entry templates default to closing through account
9900.

---

### Requirement: REQ-YEC-004: Declare ClosingEntryTemplate register

The `ClosingEntryTemplate` register (schema.org: `schema:Thing`)
defines the rules that generate closing entries automatically,
specifying which account groups close to the closing account and
whether they reverse.

**Properties:**
- `templateId` (string, required) — unique identifier (e.g.,
  revenue-closing, expense-closing, accrual-reversal)
- `templateName` (string, required) — display name
- `description` (text, optional) — operator documentation
- `accountPattern` (string, required) — regex or range (e.g.,
  "4000-4999" for revenue accounts)
- `closingAccountNumber` (string, required) — FK to Account (the
  target closing account, e.g., 9900)
- `reverseNextPeriod` (boolean, optional) — if true, reverse this
  entry in the next FY
- `automationTrigger` (enum, optional) — one of: manual, on-close,
  on-check (during checklist)
- `administrationId` (string, required) — FK to Administration
- `lifecycleState` (enum, optional, default: active) — active,
  paused, archived
- `createdAt` (datetime, required)
- `modifiedAt` (datetime, optional)

**Relations:**
- → Account (many-to-one, closingAccountNumber)
- → Administration (many-to-one)

#### Scenario: Auto-generate revenue closing entries

**GIVEN** FY 2025 is in "in-progress" state, the
"Revenue Closing" template is active with `accountPattern: "4000-4999"`
and `closingAccountNumber: "9900"`, and the GL contains
posted revenue transactions totalling €1,000,000 across accounts
4100–4950.

**WHEN** the operator initiates the closing workflow and the system
auto-generates closing entries based on active templates.

**THEN** the system creates a `ClosingEntry` of type
`revenue-closing`, calculates the total revenue (€1,000,000),
generates the closing entry GL lines (debit 9900 – income summary
€1,000,000, credit 4100–4950 aggregate €1,000,000), and marks it as
pending approval.

---

### Requirement: REQ-YEC-005: FiscalYear lifecycle — open → in-progress → closed

The `FiscalYear` register (from T2) extends with a lifecycle that
controls the transition from open to in-progress to closed. The
in-progress state executes the closing checklist and triggers
closing-entry generation.

**Lifecycle states:**
- `open` — fiscal year is active, GL transactions posted normally
- `in-progress` — GL is still writable; closing process has begun;
  checklist is being verified
- `closed` — GL becomes immutable for this FY; closing entries
  materialised; opening balances seeded for next FY

**Lifecycle transitions:**
- `open → in-progress` — requires operator with financial-officer role;
  optional transition (operator can post GL transactions while FY is
  open)
- `in-progress → closed` — requires all checklist items passed OR
  operator override with CFO role; materialises closing entries,
  retained earnings, and opening balances for next FY; marks the FY as
  immutable

#### Scenario: Begin year-end close process

**GIVEN** FY 2025 is open, the trial balance is reconciled, and the
financial officer is ready to begin closing.

**WHEN** the operator transitions FY 2025 from `open` to `in-progress`.

**THEN** the system: (1) records the transition timestamp and
operator, (2) executes the closing checklist (see REQ-YEC-006), and
(3) displays the checklist status (passed/failed/in-progress).

---

### Requirement: REQ-YEC-006: Closing checklist with declarative preconditions

The closing checklist is expressed as declarative preconditions
(`x-openregister-lifecycle.requires`) on the `in-progress → closed`
transition. Each checklist item validates a specific condition before
allowing the close.

**Checklist items (all default to required; operators can selectively
override for emergency close):**

1. **Trial Balance Verified** — aggregation check: sum(debit GL lines)
   = sum(credit GL lines) for FY, and the imbalance is <= 0.01 EUR
   (rounding tolerance)
2. **Accruals Recorded** — if any accrual accounts (9700–9799) exist,
   at least one `ClosingEntry` of type `accrual` or
   `accrual-reversal` is posted
3. **Depreciation Posted** — if fixed assets exist, the asset
   depreciation closing entry is posted (from T4-base
   `bookkeeping-fixed-assets-depreciation`)
4. **FX Gains/Losses Declared** — if the administration uses
   multi-currency, each foreign-currency account has a closing FX
   entry
5. **Related-Party Transactions Reviewed** — if any related-party
   transactions are marked, the financial officer has acknowledged
   them (via flag on `GLTransaction.relatedPartyAcknowledged`)

Each precondition returns pass/fail + messaging (e.g., "Trial balance
imbalance: 0.05 EUR" or "All checks passed").

#### Scenario: Checklist blocks close due to trial balance imbalance

**GIVEN** FY 2025 GL has a debit/credit imbalance of 50 EUR (missing a
reconciliation), and the operator attempts to transition `in-progress →
closed`.

**WHEN** the lifecycle engine evaluates the "Trial Balance Verified"
precondition.

**THEN** the transition fails with message "Trial Balance Verified:
FAIL — imbalance 50.00 EUR". The operator is instructed to post the
correcting entry and try again. The `FiscalYear` remains in
`in-progress`.

#### Scenario: CFO override for emergency close

**GIVEN** the trial balance has a 0.02 EUR rounding error that cannot
be resolved, and the CFO requires the year to close immediately.

**WHEN** the CFO transitions `in-progress → closed` with
`overrideChecklist: true` flag.

**THEN** the system: (1) records the override in audit trail with CFO
name and reason memo, (2) logs a management alert, (3) waives the
trial-balance check, (4) proceeds with closing.

---

### Requirement: REQ-YEC-007: Archive-period locking on close

Once `FiscalYear` transitions to `closed`, the period becomes
immutable: GL transactions, GL lines, and related accounts for that FY
become read-only. New transactions can only be posted to open/in-progress
FY.

The immutability is enforced at the OR layer via
`x-openregister-lifecycle` immutable-period flag.

#### Scenario: Prevent posting to closed period

**GIVEN** FY 2025 is closed and read-only, and an operator attempts to
post a correction invoice to FY 2025 GL.

**WHEN** the system evaluates the lifecycle state of the `FiscalYear` in
the GL posting request.

**THEN** the posting is rejected with message "Fiscal Year 2025 is
closed and immutable. To post corrections, unclose the period, post the
entry, and re-close." The GL remains unchanged.

---

### Requirement: REQ-YEC-008: Balance-carryforward validation

On successful close of FY N, the next FY N+1 is auto-seeded with
opening balances equal to FY N closing balances (carried forward from
the balance sheet). The opening-balance seeding is validated as a
precondition before the new FY becomes active.

**Validation rules:**
- For each account with closing balance in FY N ≠ 0, a corresponding
  opening-balance GL entry exists in FY N+1
- The account codes match between FY N and FY N+1
- The sum of opening balances in FY N+1 = sum of closing balances in
  FY N (after rounding tolerance)

#### Scenario: Validate opening balances after close

**GIVEN** FY 2024 is closed with balances: Asset 100,000 EUR, Liability
50,000 EUR, Equity 50,000 EUR. FY 2025 is newly created with opening
balances seeded as part of the close process.

**WHEN** the operator activates FY 2025 (transitions it from
`open-unvalidated` to `open`).

**THEN** the system validates: (1) opening balance total (€100,000) =
FY 2024 closing balance total (€100,000), (2) no accounts are mismatched,
(3) validation passes. The FY 2025 is marked `open` and ready for
posting.

---

### Requirement: REQ-YEC-009: Closing-entry materialization as GL transactions

When a `ClosingEntry` is approved and posted, it materialises as a
balanced `GLTransaction` with two or more `GLLine` entries via the same
materialisation extension T1 uses for `JournalEntry` (per ADR-031).

#### Scenario: Post approved closing entry to GL

**GIVEN** a `ClosingEntry` (revenue closing, €1,000,000) is approved
and the operator clicks "Post to GL".

**WHEN** the lifecycle action on the `ClosingEntry` (approve) triggers
materialisation.

**THEN** the system creates a `GLTransaction` with two balanced lines:
(1) debit 9900 – income summary €1,000,000, (2) credit 4000–4999
(revenue accounts) €1,000,000. The transaction is posted with
`source: closing-entry` and linked back to the `ClosingEntry` record.
The `ClosingEntry` status transitions to `posted`.

---

### Requirement: REQ-YEC-010: Automated closing-entry generation on transition

When `FiscalYear` transitions to `in-progress → closed`, the system
automatically generates closing entries based on active
`ClosingEntryTemplate` records.

**Generation process:**
1. Query all active `ClosingEntryTemplate` records for the administration
2. For each template, iterate the GL lines in the source FY matching the
   account pattern
3. Calculate the total amount for matched accounts
4. Generate a `ClosingEntry` record with `automationTemplate` reference
5. If the template has `reverseNextPeriod: true`, generate a
   corresponding accrual-reversal entry for the next FY
6. Mark each generated entry as pending approval

#### Scenario: Auto-generate closing entries on FY transition

**GIVEN** FY 2025 `in-progress → closed` transition is initiated, three
active templates (revenue, expense, accrual-reversal) are configured,
and the GL has:
- Revenue (4000–4999): €1,000,000 (total)
- Expenses (5000–6999): €750,000 (total)
- Accruals (9700–9799): €25,000 (total)

**WHEN** the lifecycle action executes closing-entry generation.

**THEN** three `ClosingEntry` records are auto-generated with
`approvalStatus: pending-approval`:
1. Revenue closing: debit 9900, credit 4000–4999, €1,000,000
2. Expense closing: debit 5000–6999, credit 9900, €750,000
3. Accrual reversal (for next FY 2026): debit 5900, credit 9700–9799,
   €25,000 (pending FY 2026 activation)

---

### Requirement: REQ-YEC-011: Manifest navigation entries

Two manifest navigation entries are added to expose the year-end close
UI:

1. **Year-End Close Checklist** (`type: index`) — displays the current FY
   status, checklist items, and transition controls
2. **Closing Entries** (`type: index`) — displays all `ClosingEntry`
   records for the current/selected FY, with approval workflow and
   posting controls

Both entries use the generic `CnIndexPage` / `CnDetailPage` renderers
(per ADR-017) and are wired to the T4 navigation root.

#### Scenario: Access closing UI from manifest menu

**GIVEN** the operator has logged in and the T4-base capabilities are
loaded in manifest.

**WHEN** the operator clicks `Bookkeeping > Year-End Close Checklist` in
the navigation menu.

**THEN** the system renders the year-end close index page, displaying:
- Current FY status (open, in-progress, closed)
- Checklist items with pass/fail icons
- "Begin Close Process" button (if FY is open)
- "Complete Close" button (if FY is in-progress and checklist passed)

---

### Requirement: REQ-YEC-012: Seed data — closing-entry templates

Three default `ClosingEntryTemplate` seed records are loaded on install:

1. **Revenue Closing**
   - `templateId: revenue-closing`
   - `accountPattern: 4000-4999`
   - `closingAccountNumber: 9900`
   - `reverseNextPeriod: false`
   - `automationTrigger: on-close`
   - `lifecycleState: active`

2. **Expense Closing**
   - `templateId: expense-closing`
   - `accountPattern: 5000-6999`
   - `closingAccountNumber: 9900`
   - `reverseNextPeriod: false`
   - `automationTrigger: on-close`
   - `lifecycleState: active`

3. **Accrual Reversal** (for next period)
   - `templateId: accrual-reversal`
   - `accountPattern: 9700-9799`
   - `closingAccountNumber: 5900` (audit/adjustments)
   - `reverseNextPeriod: true`
   - `automationTrigger: on-close`
   - `lifecycleState: active`

---

## Implementation Notes

- **Depends on:** T1 general ledger materialization extension, T2
  closing-workflow (or single-method guard per ADR-031 exception).
- **Extends:** T2 `FiscalYear` schema with lifecycle declarations
  (non-breaking, additive).
- **No PHP code in spec scope:** All requirements are expressed via
  schemas, lifecycles, aggregations, and templates. Implementation
  cycle may introduce a single-method guard if OR's closing-workflow
  extension is not ready.
- **Audit trail:** All lifecycle transitions and closing-entry operations
  are auto-logged via T2 audit-trail mechanism.
- **Multi-currency:** T4 handles single-currency close; T5 attaches
  multi-currency consolidation and FX revaluation.
- **Immutability:** Closed FY is read-only; corrections require explicit
  unclose (audit-trailed).

## Tests (per company-wide ADR-009)

- Closing checklist logic: each precondition evaluated correctly
  (trial balance, accruals, depreciation, FX, related-party)
- Closing-entry generation: templates matched correctly, amounts
  calculated, entries generated with correct GL lines
- Retained-earnings calculation: net income, opening/closing balances
  validated
- Balance carryforward: opening balances of next FY match closing
  balances of prior FY
- Archive-period locking: GL posting to closed FY rejected; reading
  allowed
- Manifest navigation: pages render without error; index and detail
  views work
- Persona tests (`/test-persona-janwillem` for SMB): closing workflow
  matches Dutch SMB year-end practice

## Open Questions

1. Multi-period closing: should the operator be able to close multiple
   periods (e.g., Jan–Nov) in one batch? Or one at a time? Default: one
   at a time. Specialist batching deferred to T4-specialized.
2. Unclose control: who can unclose a period and when? Default: CFO-only,
   requires memo. Governance review settles final policy.
3. Emergency-close severity: should emergency close via CFO override
   trigger escalation to external auditor (via notification)? Settles
   in implementing cycle.
