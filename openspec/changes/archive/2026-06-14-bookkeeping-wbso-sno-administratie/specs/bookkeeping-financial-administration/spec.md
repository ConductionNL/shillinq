# Spec: bookkeeping-financial-administration

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (Foundation)
**Depends on:** bookkeeping-multi-administratie (Administration entity)

## ADDED Requirements

### Requirement: REQ-WBSO-001: Account Register Schema

The app **MUST** declare an `Account` register conforming to the RGS (Referentie
Grootboek Schema) standard for Dutch chart-of-accounts.

#### Scenario: Bookkeeper creates an account in the chart-of-accounts

- **GIVEN** a Dutch SME bookkeeping administration `adm-consultancy-nl`
- **WHEN** a bookkeeper saves an `Account` with `accountNumber=1000`,
  `name="Kas en bank"`, `accountType=assets`, `parentAccountNumber="1"`,
  `status=active`, `currency=EUR`, `administrationId=adm-consultancy-nl`
- **THEN** the record is persisted, the `(accountNumber, administrationId)` pair
  is unique, and `accountType` is one of `assets|liabilities|equity|revenue|expenses`.

#### Scenario: Hierarchy depth is capped at five levels

- **GIVEN** an existing chain of four ancestor accounts already declared
- **WHEN** a fifth nested child is created with `parentAccountNumber` resolving
  to depth five
- **THEN** the create succeeds, **AND** a sixth-level create is rejected by the
  `x-openregister-constraint` declared on the schema.

#### Scenario: Circular parent references are forbidden

- **GIVEN** account `4100` with `parentAccountNumber=4`
- **WHEN** an update sets `parentAccountNumber` to `4100` (self) or to a
  descendant
- **THEN** the update is rejected with a validation error.

### Requirement: REQ-WBSO-002: Transaction Register Schema

The app **MUST** declare a `Transaction` register to record financial events.

#### Scenario: Transaction is persisted with required fields

- **GIVEN** a bookkeeper logged in to `adm-consultancy-nl`
- **WHEN** a `Transaction` is created with `transactionNumber=INV-2026-001`,
  `transactionType=invoice`, `transactionDate=2026-01-15`, `amount=1500.00`,
  `description="Invoice ABC"`, `status=draft`, `administrationId=adm-consultancy-nl`
- **THEN** the record is persisted, the `(transactionNumber, administrationId)`
  pair is unique, and the initial state is `draft`.

#### Scenario: Negative amounts are rejected

- **GIVEN** a draft transaction
- **WHEN** the saved `amount` is negative
- **THEN** the save **MUST** be rejected by schema validation
  (`amount >= 0`, two decimal places).

### Requirement: REQ-WBSO-003: Document Register Schema

The app **MUST** declare a `Document` register to track bookkeeping documents
attached to transactions and accounts.

#### Scenario: Document is persisted with required fields

- **GIVEN** a bookkeeper attaching an invoice PDF
- **WHEN** the `Document` is saved with `documentType=invoice`,
  `documentNumber=DOC-INV-2026-001`, `documentDate=2026-01-15`, `status=draft`,
  `administrationId=adm-consultancy-nl`
- **THEN** the record is persisted, `(documentNumber, administrationId)` is
  unique, and the initial state is `draft`.

### Requirement: REQ-WBSO-004: Audit-Trail Immutability

The app **MUST** declare audit-trail-immutable behaviour on all three schemas
(`Account`, `Transaction`, `Document`) per ADR-022.

#### Scenario: Posted transaction is immutable

- **GIVEN** a `Transaction` with `status=posted`
- **WHEN** any user attempts to mutate the record (rename, re-date, change
  amount, etc.)
- **THEN** the write is rejected; reversals are recorded as new transactions
  (REQ-WBSO-008).

### Requirement: REQ-WBSO-005: RBAC on Financial Data

The app **MUST** declare role-based access control on all three schemas per
ADR-023 with the following minimum mapping:

| Role          | Account | Transaction (draft) | Transaction (posted) | Document (draft) | Document (filed/archived) |
|---------------|---------|---------------------|----------------------|------------------|---------------------------|
| bookkeeper    | r       | crud                | r                    | crud             | r                         |
| auditor       | r       | r                   | r                    | r                | r (archive approver)      |
| administrator | crud    | crud                | r + reverse          | crud             | r + archive               |

#### Scenario: Auditor cannot create accounts

- **GIVEN** a user with the `auditor` role
- **WHEN** the user attempts to `POST /api/wbso-sno/accounts`
- **THEN** the response is `403 Forbidden`.

### Requirement: REQ-WBSO-006: Account Hierarchy Navigation

The app **MUST** support querying and displaying the chart-of-accounts hierarchy.

#### Scenario: Tree view returns nested children

- **GIVEN** five RGS accounts in `adm-consultancy-nl` with a 2-level hierarchy
- **WHEN** a bookkeeper requests `GET /api/wbso-sno/accounts/hierarchy?administration_id=adm-consultancy-nl`
- **THEN** the response is a tree where each parent embeds its `children[]`,
  every node exposes `accountNumber`, `name`, `accountType`, `status`, and the
  hierarchy depth is at most five.

### Requirement: REQ-WBSO-007: Document Filing Workflow

The app **MUST** support a `draft → filed → archived` lifecycle on `Document`
with optional approval-workflow.

#### Scenario: Filing requires a fileReference

- **GIVEN** a `Document` in `draft` with no `fileReference`
- **WHEN** a user requests the `file` transition
- **THEN** the request is rejected; once `fileReference` is set, the transition
  succeeds and `filedAt` is captured server-side.

### Requirement: REQ-WBSO-008: Transaction Post and Reversal Workflow

The app **MUST** support `draft → posted` and `posted → reversed` transitions
with approval gates per ADR-022.

#### Scenario: Reversal creates a linked negating record

- **GIVEN** a `Transaction` in `posted` with `amount=1500.00`
- **WHEN** an administrator requests a reversal with a reason
- **THEN** a new `Transaction` is created with `status=reversed`,
  `amount=-1500.00`, `description` prefixed by `"Reversal of "`, and the audit
  trail links both records.

### Requirement: REQ-WBSO-009: Document Archive Workflow (7-Year Retention)

The app **MUST** enforce 7-year retention per Archiefwet 1995. A scheduled
background job **MUST** trigger the archive transition with approval.

#### Scenario: Document older than seven years is auto-archived

- **GIVEN** a `Document` in `filed` with `documentDate` more than seven years
  ago
- **WHEN** the nightly archival job runs
- **THEN** the job triggers an approval-workflow; upon approval the document
  transitions to `archived` and the audit trail records the archival.

### Requirement: REQ-WBSO-010: Manifest Navigation Entry

The app **MUST** register a navigation entry for Bookkeeping with three
sub-pages: Chart of Accounts, Transactions, Documents.

#### Scenario: Navigation appears for authenticated users

- **GIVEN** the app is loaded in Nextcloud
- **WHEN** an authenticated bookkeeper opens Shillinq
- **THEN** the side menu shows a "Bookkeeping" entry behind feature flag
  `featureFlags.bookkeeping` (default enabled) with the three child routes
  `/bookkeeping/chart-of-accounts`, `/bookkeeping/transactions`,
  `/bookkeeping/documents`.

### Requirement: REQ-WBSO-011: Seed Data for Testing

The app **MUST** provide synthetic seed data covering each register so demo and
test environments are populated by the `SettingsLoadService::load()` repair
step.

#### Scenario: Seed data is loaded on repair

- **GIVEN** a freshly installed shillinq instance
- **WHEN** the `InitializeSettings` repair step runs
- **THEN** at least three sample `Account` rows, two `Transaction` rows (one
  `draft`, one `posted`, one `reversed`), and one `Document` row in
  `filed` are present, each tagged synthetic so demo instances can purge them.
