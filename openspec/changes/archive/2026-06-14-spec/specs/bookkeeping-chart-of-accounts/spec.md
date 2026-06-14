# Spec: bookkeeping-chart-of-accounts

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (foundation)
**Depends on:** none

## ADDED Requirements

### Requirement: REQ-CoA-001: The system SHALL store the chart of accounts as an OpenRegister-managed `Account` register

The chart of accounts MUST be declared as a register in
`lib/Settings/shillinq_register.json` per ADR-024, with the `Account`
schema as the canonical entity. No custom PHP model, no custom database
table, no parallel link table (per ADR-022 anti-pattern list). The
register is exposed through OpenRegister's generic CRUD HTTP surface;
shillinq adds no per-app endpoint.

#### Scenario: Operator inspects the chart of accounts via the OpenRegister API

- **GIVEN** shillinq is installed and the repair step has seeded the
  `Account` register with the RGS 3.5 SMB template
- **WHEN** an authenticated operator calls
  `GET /index.php/apps/openregister/api/objects/shillinq/Account`
- **THEN** the response MUST list the seeded `Account` records,
  paginated per OR's standard list contract, with no shillinq-side
  controller in the call path.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml`
  table declarations naming `accounts` / `chart_of_accounts`
- **THEN** no such classes or declarations SHALL exist.

### Requirement: REQ-CoA-002: The `Account` schema SHALL declare a fixed minimum field set

The `Account` schema MUST declare the following fields with the typing
below. Additional fields MAY be added later (additive only).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `accountNumber` | string | Yes | RGS-style account code (e.g. `1000`, `4100`) |
| `name` | string | Yes | Human-readable account name |
| `accountType` | enum | Yes | One of `assets`, `liabilities`, `equity`, `revenue`, `expenses` |
| `currency` | string (ISO 4217) | Yes | Account's base currency (e.g. `EUR`) |
| `parentAccountNumber` | string | No | FK to parent `Account.accountNumber` for hierarchy |
| `isClosingAccount` | boolean | No (default `false`) | Designates this as the administration's closing account (see REQ-CoA-009) |
| `administrationId` | string | Yes | FK to the administration owning the account |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` (see REQ-CoA-005) |
| `description` | string | No | Operator-authored description |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `createdAt`,
`updatedAt`, `owner`, `auditTrail`, `relations`, …) are not redeclared
per `adr-000-data-model.md`'s top-of-file note.

#### Scenario: Schema validator accepts a minimal RGS account

- **GIVEN** the `Account` schema is loaded
- **WHEN** an object `{accountNumber: "1000", name: "Cash", accountType: "assets", currency: "EUR", administrationId: "adm-1", lifecycleState: "active"}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an unknown accountType

- **GIVEN** the schema
- **WHEN** an object with `accountType: "magic"` is validated
- **THEN** validation MUST fail with an enum-violation error.

### Requirement: REQ-CoA-003: The `Account` schema SHALL declare a self-relation for hierarchy via `x-openregister-relations`

Account hierarchy MUST be declared as an `x-openregister-relations`
self-relation: `parentAccountNumber` points at another
`Account.accountNumber`. The relation MUST be traversable by
OpenRegister's relation engine; no app-local join code.

#### Scenario: A sub-account references its parent

- **GIVEN** parent account `1000 Cash` and child account `1010 Petty Cash`
- **WHEN** the child's `parentAccountNumber` is set to `1000`
- **THEN** OpenRegister's relation engine MUST resolve the child's
  parent on read; **AND** querying `1000`'s children MUST return at
  least `1010`.

#### Scenario: An orphaned child fails the relation guard

- **GIVEN** a child account references a parent `9999` that does not
  exist in the same administration
- **WHEN** the object is saved
- **THEN** OR's relation validator SHOULD reject the save with a
  resolvable error message naming the missing parent.

### Requirement: REQ-CoA-004: The `Account` schema SHALL declare a canonical Schema.org annotation

For interoperability with shared catalogues and the MCP discovery
endpoint, the schema MUST carry a Schema.org type annotation (per
shillinq config.yaml `rules.specs`). The canonical mapping is
`schema:DefinedTerm` for ledger codes (an `Account` is a coded
financial classifier, not a transaction); the existing ADR-000 entry
for `GeneralLedgerAccount` uses `schema:Product` and SHALL be
reconciled to `schema:DefinedTerm` in the data-model ADR update task.

#### Scenario: Schema annotation surfaces in the MCP discovery output

- **GIVEN** the `Account` schema is loaded
- **WHEN** OR's MCP discovery endpoint is queried
- **THEN** the schema's Schema.org type MUST be exposed as
  `schema:DefinedTerm` (or, if the reconciliation lands later, the
  ADR-000-aligned value once agreed).

### Requirement: REQ-CoA-005: Accounts SHALL have a declarative active/blocked/archived lifecycle

The `Account` schema MUST declare an `x-openregister-lifecycle` block
with the following states and transitions (per ADR-031):

- `active` — fully usable for new postings (default on create)
- `blocked` — readable, referenceable by existing postings, but
  rejected as the target of new GL lines
- `archived` — historical only; no new postings, no edits; remains
  queryable

Transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `active` | `blocked` | operator action | none |
| `blocked` | `active` | operator action | none |
| `active` | `archived` | operator action | the account has no open balance (sum of debit – credit lines = 0) |
| `blocked` | `archived` | operator action | same |

The "no open balance" precondition SHOULD be declared via OR's
aggregation extension (`x-openregister-aggregations` summing the
account's `GLLine` rows). If the aggregation engine cannot express
the sum across schemas, a thin PHP guard (single method) MAY be
referenced from `requires`, per ADR-031 §"PHP guards remain a
legitimate seam".

#### Scenario: Blocking an account prevents new postings against it

- **GIVEN** account `4100 Sales` in state `active`
- **AND** the operator transitions it to `blocked`
- **WHEN** a new `GLLine` is attempted with `accountNumber: "4100"`
- **THEN** the save MUST fail with an "account blocked" error
  surfaced from the OR lifecycle precondition on `GLLine`.

#### Scenario: Archiving an account with non-zero balance fails

- **GIVEN** account `4100 Sales` has open postings with a non-zero
  balance
- **WHEN** the operator attempts the `archived` transition
- **THEN** the transition MUST be rejected with a "non-zero balance"
  error and the account state MUST remain `active`/`blocked`.

### Requirement: REQ-CoA-006: The system SHALL ship RGS template seed data for SMB, ZZP, and government variants

Three seed files under `lib/Settings/seeds/` MUST be shipped:
`rgs-3.5-mkb.json`, `rgs-3.5-zzp.json`, `rgs-bbv.json`. Each is a
JSON array of `Account` records conforming to the schema declared in
REQ-CoA-002, carries an `_meta` block identifying its RGS variant +
version, and starts with an SPDX header per
`feedback_spdx-in-docblock.md`.

Templates MAY evolve over time; filename version pinning
(`rgs-3.5-*` vs `rgs-4.0-*`) allows side-by-side coexistence.

#### Scenario: Each seed file parses as JSON and validates

- **GIVEN** any of the three seed files
- **WHEN** parsed as JSON
- **THEN** parsing MUST succeed; **AND** every record in the array
  MUST validate against the `Account` schema.

#### Scenario: The bookkeeper persona recognises the template

- **GIVEN** a competent Dutch SMB bookkeeper persona reads
  `rgs-3.5-mkb.json`
- **THEN** the structure (asset / liability / equity / revenue /
  expense top-level groupings) SHALL match the RGS 3.5 SMB reference
  chart documented at `https://www.referentiegrootboekschema.nl`.

### Requirement: REQ-CoA-007: The repair step SHALL seed the selected RGS template on first install, idempotently

The shillinq repair step (or migration class) MUST extend
`ConfigurationService::importFromApp()` to load the selected RGS
template into the `Account` register on first install. The seed
operation MUST be idempotent: re-running the repair step MUST NOT
duplicate seeded records, and MUST NOT overwrite operator edits to
seeded records (per the per-administration override allowance).

#### Scenario: First-install seed populates the register

- **GIVEN** a fresh shillinq install with the SMB template selected
- **WHEN** the repair step runs
- **THEN** the `Account` register MUST contain the ~150 SMB template
  records.

#### Scenario: Repair re-run does not overwrite operator edits

- **GIVEN** the template is seeded and the operator has renamed
  account `4100` from `Sales` to `Omzet binnenland`
- **WHEN** the repair step is re-run (e.g. after an app upgrade)
- **THEN** account `4100`'s name MUST remain `Omzet binnenland`;
  the seed step MUST NOT revert operator edits.

### Requirement: REQ-CoA-008: Chart of accounts SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
Chart of Accounts` or top-level — exact placement settled in the
implementing cycle's UX review) with a `type: index` page binding to
the `Account` register and a `type: detail` page for individual
accounts. Both pages MUST be rendered by the generic
`@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage`
components driven by manifest config — no bespoke Vue files (per
ADR-024 Tier-4 + the existing `customComponents.js` "empty on
purpose" convention).

#### Scenario: The index page lists accounts

- **GIVEN** the manifest declares the Chart of Accounts pages
- **WHEN** an operator opens `/index.php/apps/shillinq/chart-of-accounts`
- **THEN** the page MUST render via `CnIndexPage` showing the
  seeded accounts with default columns (accountNumber, name,
  accountType, lifecycleState).

#### Scenario: The detail page renders an account

- **GIVEN** an account exists
- **WHEN** the operator drills into it
- **THEN** the detail page MUST render via `CnDetailPage` showing
  fields from REQ-CoA-002 and the lifecycle-state actions allowed
  by REQ-CoA-005.

### Requirement: REQ-CoA-009: Each administration SHALL designate exactly one account as the closing account

The `isClosingAccount` boolean field MAY be true on at most one
`Account` record per administration (uniqueness constraint scoped to
`administrationId`). This account is used by T3's period-close
capability to absorb period-end results into equity. The
constraint MUST be enforced declaratively by OR's uniqueness or
single-true field validator if supported, otherwise by a thin
lifecycle precondition on `Account.save`.

#### Scenario: Designating a second closing account fails

- **GIVEN** account `8990 Resultaat lopend boekjaar` in
  administration `adm-1` already has `isClosingAccount: true`
- **WHEN** the operator tries to save another account in the same
  administration with `isClosingAccount: true`
- **THEN** the save MUST fail with a "closing account already
  designated" error naming the existing closing account.

#### Scenario: Re-designating the closing account requires explicit clearing

- **GIVEN** the closing-account designation is on `8990`
- **WHEN** the operator first clears `isClosingAccount` on `8990`,
  then sets it on a different account
- **THEN** the second save MUST succeed.
