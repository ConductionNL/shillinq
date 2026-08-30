# Spec: bookkeeping-general-ledger

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (foundation)
**Depends on:** bookkeeping-chart-of-accounts

## ADDED Requirements

### Requirement: REQ-GL-001: The system SHALL store general-ledger postings as a header (`GLTransaction`) plus N balanced lines (`GLLine`)

The general ledger MUST be expressed as two registers declared in
`lib/Settings/shillinq_register.json`: `GLTransaction` (the header,
owns the lifecycle and the balance invariant) and `GLLine` (the
debit-or-credit row). One `GLTransaction` MUST own N ≥ 2 `GLLine`
rows whose sum of `debit` equals sum of `credit` in the
administration's base currency (per REQ-GL-005). The header/line
split is required for the balance constraint to be expressible
declaratively (per design.md Decision D2).

This requirement supersedes the flat `GeneralLedgerEntry` shape
documented in `openspec/architecture/adr-000-data-model.md`; a
reconciliation note is added to ADR-000 during the implementing
cycle. No custom database tables — both registers are
OpenRegister-managed objects per ADR-022 / ADR-024.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `gl_`,
  `general_ledger_`, or `posting_`
- **THEN** no such classes SHALL exist; all GL data flows through
  the OR object API.

### Requirement: REQ-GL-002: The `GLTransaction` schema SHALL declare a fixed minimum field set

The `GLTransaction` schema MUST carry the Schema.org annotation
`schema:AccountingTransaction`: a GL posting is a recorded financial
transaction and maps cleanly to that vocabulary term.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `transactionNumber` | string | Yes | Sequential transaction number unique per administration + fiscal year |
| `postingDate` | date | Yes | Effective accounting date of the posting |
| `periodId` | string | Yes | FK to the `FiscalPeriod` record (declared in T3; stub string acceptable until then) |
| `currency` | string (ISO 4217) | Yes | The administration's base currency for this posting (multi-currency translation is T5) |
| `description` | string | Yes | Human-readable summary of the posting |
| `sourceReference` | string | No | External document number (invoice no., bank statement ref, asset repair ID, etc.) |
| `state` | enum | Yes | One of `draft`, `posted`, `reversed` |
| `journalEntryId` | string | No | Back-reference to the `JournalEntry` that materialised this posting, if any |
| `administrationId` | string | Yes | FK to the administration owning the posting |

#### Scenario: A draft posting can be created without lines

- **GIVEN** the schema is loaded
- **WHEN** a `GLTransaction` with `state: draft` and no lines is
  created
- **THEN** the save MUST succeed (lines may be added incrementally
  before the posting is transitioned to `posted`).

### Requirement: REQ-GL-003: The `GLLine` schema SHALL declare a fixed minimum field set and encode sign in `side`

The `GLLine` schema MUST carry the Schema.org annotation
`schema:MonetaryAmount`: a GL line is the canonical record of a
currency-typed amount and maps cleanly to that vocabulary term.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `transactionId` | string | Yes | FK to the parent `GLTransaction.id` |
| `lineNumber` | integer | Yes | Stable ordering within the transaction (1-based) |
| `accountNumber` | string | Yes | FK to `Account.accountNumber` |
| `side` | enum | Yes | `debit` or `credit` |
| `amount` | number ≥ 0 | Yes | Non-negative amount in the transaction's currency |
| `currency` | string (ISO 4217) | Yes | The line's transaction currency (T1 single-currency invariants below; multi-currency rules per `bookkeeping-multi-currency` spec) |
| `periodId` | string | Yes | Resolved at posting time per REQ-GL-006; the equality invariant against the parent is checked on the `post` transition only (see below) |
| `subLedgerType` | enum | No | `ap`, `ar`, `project`, `none` (T2 owns the actual sub-ledger registers) |
| `subLedgerRef` | string | No | FK identifier into the sub-ledger when `subLedgerType` ≠ `none` |
| `costCenter` | string | No | Cost-center / department code for allocation reporting |
| `description` | string | No | Line-level description |

`amount` MUST be non-negative; the debit/credit polarity MUST live in
the `side` enum (per design.md Decision D2 — encoding sign in a
separate enum makes the balance aggregation a single SQL `SUM(CASE
WHEN side='debit' THEN amount END) = SUM(CASE WHEN side='credit' THEN
amount END)` and avoids negative-zero edge cases).

**Single-currency invariant (T1 scope).** In the absence of the
multi-currency extension (per `bookkeeping-multi-currency` spec),
`GLLine.currency` MUST equal the parent `GLTransaction.currency`. The
multi-currency extension supersedes this invariant by introducing
`transactionCurrency` and `baseCurrency` fields; consult that spec
when it is in force. This T1 spec stays correct in isolation when
multi-currency is not installed.

**`periodId` post-transition invariant.** `GLLine.periodId` is
auto-resolved at posting time by the lifecycle engine per REQ-GL-006
(against the parent's `postingDate` and the active `FiscalPeriod`).
The equality rule "`GLLine.periodId` MUST equal the parent
`GLTransaction.periodId`" is enforced as a precondition on the
`post` state transition (per REQ-GL-004), NOT as a write-time
validation on `draft` lines. A `draft` line MAY temporarily carry a
mismatched or absent `periodId`; the `draft → posted` transition
runs the auto-resolution first, then the equality check is the gate
that lets the transition succeed.

#### Scenario: Negative amounts are rejected

- **GIVEN** the schema
- **WHEN** a `GLLine` with `amount: -100` is saved
- **THEN** the save MUST fail with a "amount must be non-negative"
  validation error.

#### Scenario: Line currency must match parent (T1 single-currency)

- **GIVEN** the multi-currency extension is NOT installed AND a
  parent transaction with `currency: "EUR"`
- **WHEN** a `GLLine` with `currency: "USD"` is created against it
- **THEN** the save MUST fail with a "currency mismatch with parent
  transaction" error.

#### Scenario: Draft line with mismatched periodId is accepted

- **GIVEN** a parent transaction with `periodId: "2026-Q1"` in
  `state: draft`
- **WHEN** a `GLLine` is saved against it with `periodId: "2026-Q2"`
  (or with `periodId` unset)
- **THEN** the save MUST succeed; the mismatch is only flagged at
  `post`-transition time.

#### Scenario: Post transition rejects line whose periodId does not match parent's

- **GIVEN** a draft transaction with `periodId: "2026-Q1"` and a
  child `GLLine` whose `periodId` is `"2026-Q2"`
- **WHEN** the operator transitions the transaction to `posted`
- **THEN** the transition MUST fail with a "line periodId does not
  match parent" error.

### Requirement: REQ-GL-004: `GLTransaction` SHALL declare a declarative draft → posted → reversed lifecycle

The `GLTransaction` schema MUST declare an `x-openregister-lifecycle`
block with the following states:

- `draft` — under construction; lines may be added/edited/deleted
- `posted` — immutable on header and lines; appears in trial balance
  (T3), reportable
- `reversed` — superseded by an inverse posting; remains queryable
  for audit but excluded from balance aggregations

Transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `posted` | operator action (or journal-entry post) | balance invariant per REQ-GL-005 + all referenced accounts in REQ-CoA-005 `active` state |
| `posted` | `reversed` | operator action | a new compensating `GLTransaction` exists referencing this one as its `reversesTransactionId` |

Per ADR-031, no PHP service implements transitions; the lifecycle is
declared in the schema. Audit-trail-immutable per ADR-022 records
every transition with actor, before/after, hash chain.

#### Scenario: Posting a draft with all guards green succeeds

- **GIVEN** a balanced draft `GLTransaction` with all-`active`
  account references
- **WHEN** the operator transitions it to `posted`
- **THEN** the state MUST become `posted`; **AND** an immutable
  audit event MUST be appended; **AND** the lines MUST become
  immutable (subsequent edit attempts MUST fail).

#### Scenario: Reversing requires a compensating posting

- **GIVEN** a posted transaction `T1`
- **WHEN** the operator attempts to transition `T1` directly to
  `reversed` without a compensating posting
- **THEN** the transition MUST be rejected.

### Requirement: REQ-GL-005: The system SHALL enforce the balance invariant declaratively as a precondition on `GLTransaction.post`

`GLTransaction.post` MUST NOT succeed unless the sum of `GLLine.amount`
WHERE `side='debit'` equals the sum of `GLLine.amount` WHERE
`side='credit'` for all lines whose `transactionId` matches, computed
in the parent transaction's currency. The constraint MUST be expressed
as an `x-openregister-lifecycle.requires` precondition (per ADR-031).
**If** OR's lifecycle engine cannot express cross-line aggregations
inside `requires`, the implementing cycle MAY reference a single-method
PHP guard (`OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced(string $transactionId): bool`)
from `requires` per ADR-031.

The balance is computed at the cent level (2 decimal places for EUR,
appropriate scale per ISO 4217); rounding tolerance MUST be zero —
unbalanced postings differing by €0.01 MUST fail.

#### Scenario: A balanced 2-line transaction posts

- **GIVEN** a draft transaction with lines `{accountNumber:"1000", side:"debit", amount:100}` and `{accountNumber:"4100", side:"credit", amount:100}`
- **WHEN** the operator transitions to `posted`
- **THEN** the transition MUST succeed.

#### Scenario: An unbalanced 2-line transaction is rejected

- **GIVEN** a draft transaction with lines summing to debit `100`,
  credit `99.99`
- **WHEN** the operator transitions to `posted`
- **THEN** the transition MUST fail with a "transaction is not
  balanced" error.

#### Scenario: A balanced N-line transaction posts

- **GIVEN** a draft transaction with N ≥ 3 lines whose `side=debit`
  amounts sum equal to the `side=credit` amounts sum
- **WHEN** the operator transitions to `posted`
- **THEN** the transition MUST succeed.

#### Scenario: Posting against a blocked account fails

- **GIVEN** a balanced draft transaction whose first line references
  account `4100` and that account is in lifecycleState `blocked`
- **WHEN** the operator transitions the transaction to `posted`
- **THEN** the transition MUST fail with an "account is blocked"
  error.

### Requirement: REQ-GL-006: `GLLine.periodId` SHALL be auto-resolved against the active fiscal-period record on the `post` transition, and the resolved value MUST equal the parent's

`GLLine.periodId` is owned by the lifecycle engine, not by write-time
validation. The contract is two-phase:

1. **Auto-resolution (lifecycle hook on `post`).** When the parent
   `GLTransaction` transitions `draft → posted`, the lifecycle engine
   MUST, for every child `GLLine`, set `periodId` to the period whose
   date range contains the parent's `postingDate`. If the line
   already carries a `periodId`, the engine MUST overwrite it with
   the resolved value.
2. **Post-transition equality invariant.** After auto-resolution,
   every `GLLine.periodId` MUST equal the parent
   `GLTransaction.periodId`. The `post` transition MUST fail if any
   line's resolved `periodId` differs from the parent's.

T3 owns the `FiscalPeriod` register; T1 accepts a string field with
no FK validation until T3 lands. Per design.md Decision D3, this
stamping is the single mechanism by which T3's trial-balance
aggregation operates.

#### Scenario: Draft lines accept any periodId (or none)

- **GIVEN** a parent transaction with `periodId: "2026-Q1"` in
  `state: draft`
- **WHEN** a `GLLine` is created with `periodId: "2026-Q2"`
- **THEN** the save MUST succeed.

#### Scenario: Post transition auto-resolves and stamps lines

- **GIVEN** a parent transaction with `postingDate: 2026-02-15`,
  `periodId: "2026-Q1"`, and 3 child lines with mixed `periodId` values
- **WHEN** the operator transitions to `posted`
- **THEN** the engine MUST set each line's `periodId` to `"2026-Q1"`;
  **AND** the transition MUST succeed.

### Requirement: REQ-GL-007: General ledger SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
General Ledger`) with a `type: index` page binding to the
`GLTransaction` register and a `type: detail` page that renders the
header fields from REQ-GL-002 alongside a grid of the child
`GLLine` rows. Rendering MUST use `@conduction/nextcloud-vue`'s
generic `CnIndexPage` / `CnDetailPage` components — no bespoke Vue
files (per ADR-024).

#### Scenario: Index page lists postings with state

- **GIVEN** the manifest declares the General Ledger pages
- **WHEN** an operator opens the General Ledger index
- **THEN** `CnIndexPage` MUST render columns including
  `transactionNumber`, `postingDate`, `description`, `state`.

#### Scenario: Detail page renders header + lines

- **GIVEN** a `GLTransaction` with 3 lines
- **WHEN** the operator drills in
- **THEN** the detail page MUST render the header fields **AND** a
  grid of the 3 lines showing `accountNumber`, `side`, `amount`.

### Requirement: REQ-GL-008: Sub-ledger references SHALL be plain foreign-key strings; the actual sub-ledger registers are out of T1 scope

`GLLine.subLedgerType` and `GLLine.subLedgerRef` MUST be plain
string/enum fields with no schema-level FK validation against
sub-ledger registers in T1 (those registers ship with T2's AP / AR /
project capabilities). T1 only allocates the fields so T2 can attach
without a destructive migration. Posting a line with
`subLedgerType: "ap"` and a `subLedgerRef` that won't resolve until
T2 lands MUST succeed in T1.

#### Scenario: AP sub-ledger reference is accepted in T1

- **GIVEN** T1 is live and T2 has not yet shipped
- **WHEN** a `GLLine` is posted with
  `subLedgerType: "ap"`, `subLedgerRef: "INV-2026-0001"`
- **THEN** the save MUST succeed; OR MUST NOT attempt to
  resolve the reference.

### Requirement: REQ-GL-009: Posted lines and headers SHALL be immutable; corrections happen through reversing + new postings

Once a `GLTransaction` transitions to `posted`, neither the header
fields nor any child `GLLine` rows MAY be edited or deleted.
Corrections MUST be made by posting a compensating transaction.
The immutability MUST be enforced by OR's lifecycle engine; a
write attempt against a posted transaction MUST fail with an
"object is locked / posted" error.

#### Scenario: Edit on posted header fails

- **GIVEN** a posted `GLTransaction`
- **WHEN** an operator attempts to update its `description`
- **THEN** the save MUST fail with a posted-immutability error.

#### Scenario: Delete on posted line fails

- **GIVEN** a posted `GLLine`
- **WHEN** any actor attempts to delete it
- **THEN** the delete MUST fail; the line remains queryable.

### Requirement: REQ-GL-010: Asset repair linked GL transactions SHALL use sub-ledger reference and posting date fields

Asset repair module may link GL transactions using the `subLedgerType: "ar"`
and `subLedgerRef: <asset-repair-id>` fields. The `postingDate` field
permits the linked GL entry to use a completion date different from the
current date, addressing market demand for asset repair reconciliation.

#### Scenario: Asset repair GL link is recorded

- **GIVEN** an asset repair completed on 2026-02-15
- **WHEN** the GL posting is created with `postingDate: "2026-02-15"`,
  `subLedgerType: "ar"`, `subLedgerRef: <repair-id>`
- **THEN** the posting MUST succeed; **AND** the repair module MAY
  query GL lines by `subLedgerRef` for reconciliation reporting.
