# Spec: bookkeeping-bank-reconciliation

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`./bookkeeping-document-attachment-integration/spec.md` (docudesk FK contract for statement archival)

## ADDED Requirements

### Requirement: REQ-BR-001: Bank reconciliation SHALL be declared as `BankStatement` + `BankStatementLine` + `ReconciliationMatch` + `MatchingRule` registers, not parallel storage

Bank reconciliation MUST be expressed as four new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `BankStatement` — per-import header (bank account, period
  covered, opening + closing balance, source file URI to
  docudesk).
- `BankStatementLine` — per-transaction row (date, counterparty,
  amount, reference, raw description).
- `MatchingRule` — operator-authored rule declaring predicates
  for auto-matching against AP / AR / other.
- `ReconciliationMatch` — produced match record linking one or
  more `BankStatementLine` to one or more
  `APInvoice` / `ARInvoice` / `GLTransaction`.

No custom database tables. Per ADR-022, all four consume OR's
audit-trail-immutable abstraction.

#### Scenario: Reviewer confirms no parallel bank-rec storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `bank_statement`, `bank_line`, `reconciliation_*`, or
  `match_rule`
- **THEN** no such classes SHALL exist.

#### Scenario: All four registers carry audit-on

- **GIVEN** `lib/Settings/shillinq_register.json`
- **WHEN** the four bank-rec schemas are inspected
- **THEN** each MUST carry `x-openregister-audit: true` per
  REQ-AT-001.

### Requirement: REQ-BR-002: Bank statement import SHALL accept CAMT.053, MT940, and manual CSV upload; live PSD2 connectors are T4

The `BankStatement` register MUST support import from three
sources:

| Format | File extension | Mechanism |
|---|---|---|
| CAMT.053 (ISO 20022) | `.xml` | Operator uploads via the manifest's "Import statement" action; the file is parsed by the `bankStatementParse` calculation per REQ-BR-003 |
| MT940 (SWIFT) | `.sta` / `.txt` / `.940` | Same upload path; parsed by the same calculation |
| Manual CSV | `.csv` | Operator uploads a CSV matching the documented column shape (`date, counterparty, amount, reference, description`); parsed directly |

The original uploaded file MUST be archived via docudesk per the
`bookkeeping-document-attachment-integration` contract — the
`BankStatement.sourceDocumentUri` field carries the docudesk URI.

PSD2 live-feed connectors (auto-import on bank webhook, real-time
balance polling) are **explicitly deferred to T4**. T2 declares
the registers + import flow only.

#### Scenario: CAMT.053 import produces a BankStatement + lines

- **GIVEN** a valid CAMT.053 XML file containing 25 transactions
- **WHEN** an operator uploads it via the manifest's "Import
  statement" action
- **THEN** one `BankStatement` MUST be created with the
  statement-header fields populated; **AND** 25
  `BankStatementLine` records MUST be created against it;
  **AND** the original XML MUST be archived to docudesk and the
  URI persisted in `sourceDocumentUri`.

#### Scenario: MT940 import produces equivalent output

- **GIVEN** an MT940 statement covering the same 25 transactions
- **WHEN** the operator uploads it
- **THEN** the resulting `BankStatement` + `BankStatementLine`
  records MUST be functionally equivalent to the CAMT.053 case
  (same line count, same amounts, same dates).

#### Scenario: T2 does not auto-import via PSD2 webhook

- **GIVEN** T2 is live
- **WHEN** scanned for `lib/Controller/*Psd2*.php`,
  `lib/Service/*Psd2*.php`, or `appinfo/routes.php` entries
  matching `/psd2/webhook`
- **THEN** no such files or routes SHALL exist (T4 will add them).

### Requirement: REQ-BR-003: CAMT.053 / MT940 parsing SHALL be declarative (`x-openregister-calculations`) when OR supports structured-text parsing; otherwise a single-method PHP guard per ADR-031 exception

The bank-statement parser MUST be expressed as an
`x-openregister-calculations` field consuming the uploaded file
and emitting structured `BankStatementLine` records, IF OR's
calculation extension supports XML / structured-text parsing.

If OR's calculation extension does NOT yet support the required
parsing primitives, the shape-neutral fallback per ADR-031
exception is a single-method
`OCA\Shillinq\Lifecycle\StatementParser` called *by* the
calculation engine (single method `parse(string $contents, string
$format): array`, ~50 LOC, no state, no orchestration).

The choice is documented in `bookkeeping-bank-reconciliation/design.md`
discovery. The spec is shape-neutral.

#### Scenario: Reviewer confirms parser shape

- **GIVEN** the shillinq codebase
- **WHEN** scanned
- **THEN** EITHER a `x-openregister-calculations` declaration
  referencing the parse function MUST exist in the register file,
  OR a single-method `StatementParser` MUST exist with an
  ADR-031-exception annotation in its file header; not both.

#### Scenario: Parser handles a CAMT.053 statement with 25 transactions

- **GIVEN** a valid CAMT.053 file
- **WHEN** the parser runs against it
- **THEN** the result MUST be an array of 25 records each with
  `{date, counterparty, amount, reference, description}`;
  amount signs MUST be preserved (debits negative, credits
  positive per CAMT convention).

### Requirement: REQ-BR-004: `MatchingRule` SHALL declare match predicates as schema metadata; rule evaluation is an aggregation, not a service

`MatchingRule` MUST be a register declaring rule predicates as
schema metadata consumed by an
`x-openregister-aggregations` query that emits candidate
`ReconciliationMatch` records. No `MatchingService.php`,
`RuleEngine.php`, or similar PHP service. Per ADR-031.

Each rule MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `ruleName` | string | Yes | Human-readable name |
| `priority` | integer | Yes | Lower = earlier evaluation |
| `targetType` | enum | Yes | One of `ap-invoice`, `ar-invoice`, `gl-transaction`, `customer`, `vendor` |
| `predicates` | array of object | Yes | List of predicate objects (per REQ-BR-005) |
| `autoConfirm` | boolean | Yes (default false) | If true, matches are auto-confirmed; else operator-confirmed |
| `administrationId` | string | Yes | FK to administration |
| `lifecycleState` | enum | Yes | One of `active`, `disabled`, `archived` |

#### Scenario: Reviewer confirms no rule-engine service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Match*.php`,
  `lib/Service/*RuleEngine*.php`, `lib/Service/*Reconcil*.php`
- **THEN** no such files SHALL exist.

#### Scenario: Reordering rules by priority changes match precedence

- **GIVEN** two rules both targeting `ar-invoice` — rule A with
  `priority: 10`, rule B with `priority: 20`
- **WHEN** a bank line could match both
- **THEN** rule A's match MUST be emitted; rule B MUST NOT
  produce a duplicate match for the same line.

### Requirement: REQ-BR-005: Predicates SHALL include exact-amount, amount-range, reference-match, counterparty-fuzzy, and date-window

The supported predicate shapes (T2 baseline; extensible in later
tiers) MUST include:

| Predicate | Shape | Semantics |
|---|---|---|
| `exact-amount` | `{op: "exact-amount", amount: <number>}` | Line amount equals the predicate amount |
| `amount-range` | `{op: "amount-range", min: <n>, max: <n>}` | Line amount within range |
| `reference-match` | `{op: "reference-match", pattern: "<regex>"}` | Line reference matches the regex |
| `counterparty-fuzzy` | `{op: "counterparty-fuzzy", name: "<string>", threshold: <0-1>}` | Levenshtein similarity ≥ threshold |
| `date-window` | `{op: "date-window", days: <integer>}` | Line date within N days of the target object's `invoiceDate` / `dueDate` |

Predicates in the same rule MUST be combined with logical AND.
Cross-rule combinations are handled by REQ-BR-004's priority
ordering.

#### Scenario: Exact-amount + reference-match rule matches an AR invoice

- **GIVEN** an AR invoice for €500 with `invoiceNumber:
  INV-C-2026-0001` and a bank line of €500 with reference
  containing `INV-C-2026-0001`
- **AND** a `MatchingRule` with predicates `[{op:
  "exact-amount", amount: 500}, {op: "reference-match", pattern:
  "INV-C-2026-0001"}]`
- **WHEN** the matching aggregation runs
- **THEN** a `ReconciliationMatch` MUST be emitted linking the
  bank line to the AR invoice.

#### Scenario: Counterparty fuzzy match handles typos

- **GIVEN** a `MatchingRule` with `counterparty-fuzzy:
  {name: "Acme BV", threshold: 0.85}`
- **AND** a bank line with counterparty `"Acme B.V."` (similarity
  0.91)
- **WHEN** the aggregation runs
- **THEN** a candidate match MUST be emitted; **AND** because
  `autoConfirm` is `false`, the operator MUST confirm before the
  match is finalised per REQ-BR-006.

### Requirement: REQ-BR-006: Confirmed matches SHALL emit lifecycle events that AP/AR consume to transition `posted → paid` (or `partially-paid`)

When a `ReconciliationMatch` is confirmed (either auto via
`autoConfirm: true` or operator-confirmed via UI), the match
engine MUST emit a CloudEvent (or OR-native equivalent) that the
matched AP/AR invoice's lifecycle consumes per REQ-AP-008 /
REQ-AR-007. No shillinq matcher service forwards the event;
declarative emission is OR's responsibility.

Partial matches (bank line amount < invoice total) MUST emit an
event tagged `partial: true`; AP/AR specs transition to
`partially-paid` (AR) or remain `posted` with a partial-payment
audit event (AP) per their respective REQ-*-004/008/007.

Multi-line matches (one bank line covering multiple invoices, or
multiple bank lines covering one invoice) MUST be supported — the
`ReconciliationMatch` schema MUST carry `bankLineRefs: array<string>`
and `targetRefs: array<string>` with N×M cardinality.

#### Scenario: Auto-confirmed match marks invoice paid

- **GIVEN** a `MatchingRule` with `autoConfirm: true`
- **AND** a candidate match is emitted per REQ-BR-005
- **WHEN** the matching aggregation runs
- **THEN** the `ReconciliationMatch` MUST be created with
  `confirmedAt` set; **AND** the matched AR invoice MUST
  transition to `paid` per REQ-AR-007.

#### Scenario: Operator-confirmed match marks invoice paid

- **GIVEN** an unconfirmed candidate `ReconciliationMatch`
- **WHEN** the operator opens the bank-rec detail page and
  confirms the match
- **THEN** the match MUST be confirmed; **AND** the matched
  invoice MUST transition per REQ-AP-008 / REQ-AR-007.

#### Scenario: Multi-line aggregate match

- **GIVEN** three bank lines of €100, €200, €700 with the same
  customer reference and an AR invoice for €1 000 from the same
  customer
- **WHEN** an operator manually creates a `ReconciliationMatch`
  with `bankLineRefs: [line1, line2, line3]` and `targetRefs: [invoice1]`
- **THEN** the match MUST be confirmed; **AND** the AR invoice
  MUST transition to `paid`.

### Requirement: REQ-BR-007: Unmatched lines SHALL route to a designated suspense account; the assignment is a lifecycle action, not a service

The administration MUST designate exactly one `Account` (or one
per bank account) as the "bank reconciliation suspense account"
— per T1 REQ-CoA-009's closing-account pattern, declared either
by an additive `isSuspenseAccount` boolean on T1's `Account`
schema or by an administration-settings field. The choice is
documented in `bookkeeping-bank-reconciliation/design.md`
discovery; the spec is shape-neutral.

When the operator marks a `BankStatementLine` as "unmatched and
final" (no more matching attempts), the line's lifecycle action
MUST materialise a `GLTransaction` debiting (or crediting,
depending on the bank line's sign) the suspense account against
the bank account's GL account. The materialisation is declarative
per the same T1 REQ-JE-007 pattern, not a PHP service.

The suspense balance is itself reportable through trial balance
(per REQ-TB-001); a non-zero suspense balance is a follow-up flag
the administration tracks until resolved.

#### Scenario: Marking a line unmatched posts to suspense

- **GIVEN** a `BankStatementLine` of €50 credit (incoming) that
  the operator marks "unmatched final"
- **WHEN** the lifecycle action fires
- **THEN** a balanced `GLTransaction` MUST be materialised
  debiting the bank-account GL (€50) and crediting the
  designated suspense account (€50); **AND** the line MUST be
  marked `routed-to-suspense`.

#### Scenario: Suspense balance is visible in trial balance

- **GIVEN** five unmatched lines totalling €250 routed to the
  suspense account over a period
- **WHEN** the period's trial balance is requested per REQ-TB-002
- **THEN** the suspense account row MUST show a €250 closing
  balance; **AND** the auditor MUST see this flagged via the
  bank-rec UI's outstanding-suspense indicator.

### Requirement: REQ-BR-008: `BankStatement` SHALL declare an imported → in-progress → reconciled → audit-locked lifecycle

`BankStatement` MUST declare an `x-openregister-lifecycle` block
with the following transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `imported` | `in-progress` | first matching action (auto or operator) | none |
| `in-progress` | `reconciled` | operator action | every `BankStatementLine` on the statement MUST be matched OR `routed-to-suspense` |
| `reconciled` | `audit-locked` | auditor sign-off (role `auditor`) | none |
| `imported` | `imported` | re-import attempt for the same file | rejected — duplicate import detection via file checksum + statement-period overlap |

Per ADR-031, no PHP service implements transitions; the lifecycle
is declared in the schema. Audit per ADR-022.

#### Scenario: Reconcile fails with unmatched lines

- **GIVEN** a `BankStatement` with 25 lines of which 23 are
  matched and 2 are still unmatched (not routed to suspense)
- **WHEN** the operator attempts the `in-progress → reconciled`
  transition
- **THEN** the transition MUST fail with a "2 lines outstanding
  — match or route to suspense first" error.

#### Scenario: Audit-lock is irreversible

- **GIVEN** a `BankStatement` in state `audit-locked`
- **WHEN** any actor attempts the `audit-locked → reconciled`
  transition
- **THEN** the transition MUST be rejected — audit-locked
  statements are immutable per the same REQ-PC-003 pattern.

### Requirement: REQ-BR-009: Duplicate import detection SHALL be a declarative uniqueness constraint, not a PHP service

When an operator uploads a bank statement, the system MUST
reject the import if (a) a `BankStatement` with the same file
checksum already exists in the administration, OR (b) a
`BankStatement` with overlapping bank-account + period exists.

The constraint MUST be declared as an OR uniqueness rule on
`BankStatement` (composite key on `administrationId + fileChecksum`
and a range overlap on `bankAccount + periodFrom + periodTo`).
If OR's uniqueness validator cannot express composite-key + range-
overlap shapes, a thin lifecycle precondition on
`BankStatement.create` MAY enforce it per ADR-031 exception.

#### Scenario: Re-uploading the same CAMT file fails

- **GIVEN** a CAMT.053 file was imported on Monday producing
  `BankStatement BS-001`
- **WHEN** the same file is uploaded again on Tuesday
- **THEN** the import MUST fail with a "duplicate statement —
  file already imported on Monday as BS-001" error referencing
  the existing statement.

#### Scenario: Overlapping-period import fails

- **GIVEN** `BankStatement BS-001` covers `2026-04-01` to `2026-04-30`
  on bank account `NL00ABNA0123456789`
- **WHEN** the operator imports a different file covering
  `2026-04-15` to `2026-05-15` on the same account
- **THEN** the import MUST fail with a "overlapping statement
  period" error naming the conflicting statement.

### Requirement: REQ-BR-010: Bank reconciliation SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Bookkeeping > Bank Reconciliation` — `type: index` +
  `type: detail` on `BankStatement`. Detail page MUST surface
  the lines grid, the candidate matches sub-grid, the
  manual-match action, the route-to-suspense action, and the
  lifecycle transition buttons.
- `Bookkeeping > Matching Rules` — `type: index` +
  `type: detail` on `MatchingRule` for operator authoring of
  rules.

Rendering MUST use `@conduction/nextcloud-vue` generic components
per ADR-024 Tier-4 — no bespoke Vue files (with the standard
caveat that the manifest may bind a `CnGridPage` or similar if
the lines grid needs specialised affordances; bespoke Vue is
out of scope).

#### Scenario: Bank reconciliation index lists statements

- **GIVEN** the manifest declares the Bank Reconciliation pages
- **WHEN** an operator opens
  `/index.php/apps/shillinq/bank-reconciliation`
- **THEN** `CnIndexPage` MUST render columns including
  `bankAccount`, `periodFrom`, `periodTo`, `state`, line count,
  matched count.

#### Scenario: Statement detail surfaces lines + candidate matches

- **GIVEN** a `BankStatement` with 25 lines, 18 matched, 5
  unmatched candidates, 2 routed-to-suspense
- **WHEN** an operator opens the detail page
- **THEN** the page MUST render the lines grid with the match
  state per line; **AND** a candidate-matches sub-grid showing
  the 5 unmatched candidates with one-click confirm /
  route-to-suspense actions.
