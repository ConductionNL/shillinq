# Spec: bookkeeping-bank-reconciliation

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 `bookkeeping-general-ledger`, T2 `bookkeeping-document-attachment-integration`

Bank reconciliation is the daily-operations loop that connects the
bank statement (external reality) to the internal ledger. This capability
is in the top-3 customer-asked capabilities in the intelligence-db
`competitor_features` cluster with `app_slug=shillinq`.

T2 supports CAMT.053 + MT940 + manual CSV import. PSD2 live-feed
connectors (auto-import on bank webhook) are explicitly T4.

## ADDED Requirements

### Requirement: REQ-BR-001 — The system SHALL store bank statements, statement lines, matching rules, and reconciliation matches as OpenRegister-managed registers

Four registers MUST be declared in `lib/Settings/shillinq_register.json`:
`BankStatement`, `BankStatementLine`, `MatchingRule`,
`ReconciliationMatch`. No parallel PHP Mapper classes, no custom DB
tables (per ADR-022 anti-pattern list). OR's generic CRUD HTTP surface
exposes all four.

#### Scenario: Reviewer confirms no parallel bank-rec storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `bank_statement`,
  `bank_statement_line`, `matching_rule`, or `reconciliation_match`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-BR-002 — The `BankStatement` schema SHALL declare a fixed minimum field set

The `BankStatement` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `statementId` | string | Yes | Unique statement identifier (e.g. `BS-2026-001`) |
| `bankAccountIban` | string | Yes | IBAN of the reconciled bank account |
| `administrationId` | string | Yes | FK to the owning Administration |
| `periodStart` | date | Yes | First date covered by the statement |
| `periodEnd` | date | Yes | Last date covered by the statement |
| `openingBalance` | number | Yes | Opening balance per the bank (EUR) |
| `closingBalance` | number | Yes | Closing balance per the bank (EUR) |
| `currency` | string | Yes | ISO 4217 currency code (default `EUR`) |
| `importFormat` | enum | Yes | One of `camt053`, `mt940`, `csv`, `manual` |
| `fileChecksum` | string | Yes | SHA-256 hash of imported file for deduplication |
| `lifecycleState` | enum | Yes | One of `imported`, `in-progress`, `reconciled`, `audit-locked` |
| `sourceDocumentUri` | string | No | Docudesk FK URI for the original bank statement file |

#### Scenario: Schema validator accepts a minimal BankStatement

- **GIVEN** the `BankStatement` schema is loaded
- **WHEN** an object with required fields and `lifecycleState: "imported"` is validated
- **THEN** validation MUST pass.

### Requirement: REQ-BR-003 — The system SHALL declare `BankStatementLine` and support CAMT.053, MT940, and manual import

The `BankStatementLine` schema MUST declare:

| Field | Type | Required | Description |
|---|---|---|---|
| `lineId` | string | Yes | Unique line identifier within the statement |
| `statementId` | string | Yes | FK to `BankStatement.statementId` |
| `valueDate` | date | Yes | Value date (boekingsdatum) of the transaction |
| `transactionDate` | date | No | Transaction date if different from value date |
| `amount` | number | Yes | Transaction amount (positive = credit, negative = debit, in EUR) |
| `currency` | string | Yes | ISO 4217 currency code |
| `remittanceInfo` | string | No | Payment reference / omschrijving |
| `counterpartyName` | string | No | Name of counterparty |
| `counterpartyIban` | string | No | IBAN of counterparty |
| `endToEndRef` | string | No | SEPA end-to-end reference |
| `status` | enum | Yes | One of `unmatched`, `matched`, `routed-to-suspense` |
| `reconciliationMatchId` | string | No | FK to `ReconciliationMatch.matchId` once matched |

Parsing CAMT.053 (XML) and MT940 (structured text) into
`BankStatementLine` records MUST be declared as either:
(a) an `x-openregister-calculations` extension if the OR engine
supports XML/structured-text parsing primitives, or
(b) a single-method PHP guard
(`OCA\Shillinq\Lifecycle\StatementParser`) per ADR-031 exception.
The guard has exactly one method:
`parse(string $contents, string $format): array`. Resolution is
deferred to `opsx-ff` discovery during the implementing cycle.

#### Scenario: CAMT.053 file is parsed into BankStatementLine records

- **GIVEN** a valid CAMT.053 XML file with 10 transaction entries
- **WHEN** the bank statement is imported
- **THEN** exactly 10 `BankStatementLine` records MUST be created,
  each with `statementId` set to the parent statement, `status:
  "unmatched"`, and `valueDate`, `amount`, `remittanceInfo`,
  `counterpartyName` populated from the XML.

#### Scenario: MT940 file is parsed into BankStatementLine records

- **GIVEN** a valid MT940 file with 8 transaction entries
- **WHEN** the bank statement is imported
- **THEN** exactly 8 `BankStatementLine` records MUST be created.

### Requirement: REQ-BR-004 — The `BankStatement` register SHALL declare an `imported → in-progress → reconciled → audit-locked` lifecycle

Per ADR-031, the bank statement lifecycle MUST be declared as an
`x-openregister-lifecycle` block with:

| From | To | Trigger | Guard / Action |
|---|---|---|---|
| `imported` | `in-progress` | operator opens for reconciliation | none |
| `in-progress` | `reconciled` | operator confirms reconciliation complete | all lines MUST be in `matched` or `routed-to-suspense` status (no `unmatched` lines remain) |
| `reconciled` | `audit-locked` | auditor action | irreversible; writes audit event |
| `reconciled` | `in-progress` | operator reopens | elevated role required; records reopen in audit trail |
| `audit-locked` | *(any)* | — | **FORBIDDEN** — `audit-locked` is terminal |

#### Scenario: Reconciliation blocked when unmatched lines remain

- **GIVEN** `BankStatement` in state `in-progress` with 2
  `BankStatementLine` records in `unmatched` status
- **WHEN** the operator attempts the `reconciled` transition
- **THEN** the transition MUST be rejected with an "unmatched lines
  remain" error listing the unmatched line IDs.

#### Scenario: Audit-locked statement cannot be reopened

- **GIVEN** `BankStatement` in state `audit-locked`
- **WHEN** any user attempts any lifecycle transition
- **THEN** the transition MUST be rejected with an "audit-locked,
  irreversible" error.

### Requirement: REQ-BR-005 — The `MatchingRule` schema SHALL declare predicate-based matching rules

The `MatchingRule` schema MUST declare:

| Field | Type | Required | Description |
|---|---|---|---|
| `ruleId` | string | Yes | Unique rule identifier |
| `name` | string | Yes | Human-readable rule name (e.g. `Exacte factuurreferentie`) |
| `administrationId` | string | Yes | FK to the owning Administration |
| `priority` | integer | Yes | Lower number = higher priority (evaluated in order) |
| `isActive` | boolean | Yes | Whether rule is active |
| `predicates` | array | Yes | Array of predicate objects (see below) |

Each predicate in `predicates` MUST declare:
- `field` (string) — which `BankStatementLine` field to match on
  (e.g. `remittanceInfo`, `amount`, `counterpartyIban`,
  `counterpartyName`)
- `operator` (enum) — one of `exact`, `contains`, `regex`,
  `amount-range`
- `value` (string or number) — the match target or pattern
- `matchTarget` (enum) — one of `ar-invoice`, `ap-invoice`,
  `journal` — which register to match against

#### Scenario: Exact-amount + exact-reference rule matches an AR invoice

- **GIVEN** a `MatchingRule` with predicates:
  `{field: "amount", operator: "exact", matchTarget: "ar-invoice"}`
  and
  `{field: "remittanceInfo", operator: "contains", value: "2026-0042", matchTarget: "ar-invoice"}`
- **AND** a `BankStatementLine` with `amount: 1210.00` and
  `remittanceInfo: "Betaling factuur 2026-0042"`
- **AND** an `ARInvoice` `2026-0042` with `grossAmount: 1210.00`
- **WHEN** the matching aggregation runs
- **THEN** a `ReconciliationMatch` candidate MUST be emitted linking
  the bank line to the AR invoice.

### Requirement: REQ-BR-006 — Bank line matching SHALL be declared as an `x-openregister-aggregations` query emitting `ReconciliationMatch` candidates

Per ADR-031, the matching logic MUST be declared as an
`x-openregister-aggregations` query that consumes `MatchingRule`
predicates and emits `ReconciliationMatch` records for operator
confirmation. No PHP `ReconciliationMatchService`, no PHP rule-engine
class SHALL be created.

The `ReconciliationMatch` schema MUST declare:

| Field | Type | Required | Description |
|---|---|---|---|
| `matchId` | string | Yes | Unique match identifier |
| `bankStatementLineId` | string | Yes | FK to `BankStatementLine.lineId` |
| `matchType` | enum | Yes | One of `ar-invoice`, `ap-invoice`, `journal`, `suspense` |
| `matchedObjectId` | string | Yes | UUID of the matched AR/AP invoice or journal |
| `matchedAmount` | number | Yes | Amount matched |
| `confidence` | enum | Yes | One of `auto` (rule-matched), `manual` (operator-created) |
| `status` | enum | Yes | One of `pending`, `confirmed`, `rejected` |
| `confirmedBy` | string | No | UUID of confirming operator |
| `confirmedAt` | datetime | No | Confirmation timestamp |

#### Scenario: Reviewer confirms no PHP rule-engine service for matching

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes naming `Match*`,
  `Reconcil*`, or `Rule*`
- **THEN** no such classes SHALL exist (other than at-most-1
  `StatementParser` guard, if applicable per ADR-031 exception).

### Requirement: REQ-BR-007 — Unmatched bank statement lines SHALL be routable to a suspense account

Unmatched bank statement lines MUST be routable to a designated suspense account so the bank balance can still tie out. When an operator determines that a `BankStatementLine` cannot be
matched to an AR/AP invoice or journal entry, the line MUST be
routable to a designated suspense account. The suspense account is
either:
(a) flagged by `Account.isSuspenseAccount: true` (additive field
on T1's `Account` schema, per Decision D10 in `design.md`), or
(b) configured as an administration setting.

Routing to suspense MUST post a `GLTransaction` against the suspense
account and set the `BankStatementLine.status` to `routed-to-suspense`.
This routing MUST be declarative (lifecycle action + calculation),
not a PHP service.

#### Scenario: Unmatched line is routed to the suspense account

- **GIVEN** a `BankStatementLine` in `unmatched` status
- **AND** a suspense account `9998 Tussenrekening bank` designated
  (`isSuspenseAccount: true`)
- **WHEN** the operator routes the line to suspense
- **THEN** a balanced `GLTransaction` MUST be posted against account
  `9998`; the `BankStatementLine.status` MUST become
  `routed-to-suspense`.

### Requirement: REQ-BR-008 — Duplicate bank statement import SHALL be rejected

Duplicate bank-statement imports MUST be rejected via a schema-level uniqueness rule on `(administrationId, fileChecksum)`. The `BankStatement.fileChecksum` field (SHA-256 of the uploaded
file) MUST be used to prevent duplicate imports. The uniqueness
constraint MUST be declared as a schema-level uniqueness rule on
`(administrationId, fileChecksum)` — NOT as a PHP service check.
A second import of the same file (same checksum, same administration)
MUST be rejected with a "duplicate statement" error naming the
existing `BankStatement` record.

#### Scenario: Importing the same CAMT.053 file twice fails

- **GIVEN** `BankStatement` `BS-2026-001` already exists with
  `fileChecksum: "abc123..."`
- **WHEN** the operator imports the same CAMT.053 file again
  (same checksum) for the same administration
- **THEN** the import MUST be rejected with "duplicate statement"
  and the ID of the existing record.

### Requirement: REQ-BR-009 — PSD2 live-feed bank connectors are explicitly deferred to T4

The T2 spec MUST NOT declare any PSD2 webhook, open-banking connector, or live-feed service; PSD2 live-feed bank connectors are explicitly deferred to T4. T2 bank reconciliation supports CAMT.053 + MT940 + manual CSV import
only. Automated bank-feed integration via PSD2 webhooks or open-banking
APIs is explicitly T4 (`add-shillinq-bookkeeping-advanced` /
`bookkeeping-bank-connectors`). The T2 spec MUST NOT declare any
PSD2 route, webhook handler, or live-feed service.

#### Scenario: No PSD2 webhook route exists in T2

- **GIVEN** `appinfo/routes.php`
- **WHEN** scanned for route paths matching `/psd2/webhook`,
  `/bank/feed`, or `/bank/connect`
- **THEN** no such routes SHALL exist.

### Requirement: REQ-BR-010 — Bank reconciliation SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:
- `Bookkeeping > Bank Reconciliation` — `type: index` + `type: detail`
  for `BankStatement`; detail page surfaces lifecycle action buttons
  (Open for Reconciliation, Confirm Reconciled, Audit Lock), an
  import action (CAMT.053 / MT940 / CSV upload), and the list of
  `BankStatementLine` records with their match status.
- `Bookkeeping > Matching Rules` — `type: index` + `type: detail`
  for `MatchingRule`; allows operators to configure and prioritise
  matching rules.

No bespoke Vue components are authored (per ADR-024).

#### Scenario: Bank Reconciliation manifest entries exist and validate

- **GIVEN** `src/manifest.json` is loaded
- **WHEN** `node tests/validate-manifest.js` is run
- **THEN** the script MUST exit 0 and both Bank Reconciliation and
  Matching Rules entries MUST be present.
