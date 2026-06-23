## MODIFIED Requirements

### REQ-AP-001: Accounts payable SHALL be declared as `Payee` + `APTransaction` + `DunningNotice` registers, not duplicates of GL

Accounts payable MUST be expressed as the canonical core registers in
`lib/Settings/register.d/bookkeeping-accounts-payable-core.json` (the
generated `shillinq_register.json` is regenerated from it) per ADR-024:

- `Payee` — the canonical "anyone we pay" master: suppliers, vendors, **and
  freelancers (Dutch ZZP'ers / sole traders)**. Carries name, address, KvK,
  BTW, `payeeType`, a structured `bankAccount` object
  `{ iban, bic, accountHolderName }` (the `bic` is the ISO 9362 / SWIFT code —
  the same identifier), default expense account, dunning policy reference,
  credit terms, and the top-level financial fields folded in from the retired
  `VendorFinancialProfile` (`creditLimit`, `apBalance`).
- `APTransaction` — sub-ledger invoice (payee reference, invoice number, due
  date, amount, line breakdown, attachment FK to docudesk). Unchanged by this
  change.
- `DunningNotice` — per-invoice dunning timeline record (reminder level +
  dispatched-at + acknowledged-at). Unchanged by this change.
- `PaymentRun` — a batch of approved `Payee` payments (see REQ-AP-012).

The legacy base-monolith AP schemas `VendorFinancialProfile` and the legacy
`PaymentRun` are RETIRED; no duplicate vendor master or AP module remains.

This capability establishes the foundational AP data model for vendor payment
tracking and cash-flow management. Posting an `APTransaction` MUST materialise
exactly one balanced `GLTransaction` per the T1 REQ-JE-007 pattern.
`GLLine.subLedgerType: "ap"` + `subLedgerRef: <APTransaction UUID>` resolves
to the materialised AP line (T1 REQ-GL-009 stub now backed by a real FK).

Peppol BIS 3.0 inbound e-invoicing is **explicitly deferred to T4**; this T2
capability ships internal AP posting + dunning + aging. Manual vendor invoice
upload (PDF/attachment) is the T2 intake path.

#### Scenario: Reviewer confirms no parallel AP table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `ap_transaction`,
  `payee`, `dunning_*`, or `accounts_payable_*`
- **THEN** no such classes SHALL exist.

#### Scenario: GLLine sub-ledger ref resolves to a real APTransaction

- **GIVEN** T2 is live and `APTransaction INV-V-2026-0001` is posted
- **WHEN** the materialised `GLLine` is inspected
- **THEN** the line MUST carry `subLedgerType: "ap"`,
  `subLedgerRef: "<UUID of INV-V-2026-0001>"`, **AND** the FK
  MUST resolve via OR's relation engine.

#### Scenario: No legacy duplicate AP schema remains

- **WHEN** the regenerated `shillinq_register.json` is inspected
- **THEN** it contains `Payee`, `APTransaction`, `DunningNotice`, and `PaymentRun`
- **AND** it contains no `VendorFinancialProfile` schema and no second `PaymentRun`.

## ADDED Requirements

### Requirement: REQ-AP-012: Payee SHALL be the canonical payee master with `payeeType`, a `bankAccount` object, and folded financial fields

The `Payee` schema SHALL be the single canonical master for any party the
administration pays. It SHALL carry a `payeeType` enum
`[supplier, vendor, freelancer, contractor, government, other]`, and the
financial fields folded in from the retired `VendorFinancialProfile` as
top-level fields: `creditLimit` (number) and `apBalance` (number). Its
`bankAccount` field SHALL be a structured object
`{ iban (string), bic (string), accountHolderName (string) }` into which the
retired profile's `iban`/`bic` fold — these SHALL NOT be added as separate
top-level `Payee` fields. The `bic` field is the bank's ISO 9362 identifier;
because a "SWIFT code" IS a BIC, no separate `swift` field SHALL be added.
Schema.org annotation: `schema:Organization`.

True non-SEPA / cross-border international payments (non-IBAN account numbers,
intermediary/correspondent banks) are out of scope — a future capability; the
`bankAccount` object models SEPA IBAN/BIC only.

#### Scenario: Payee validates a freelancer

- **GIVEN** the `Payee` schema
- **WHEN** `{vendorNumber:"V-010", name:"Jan de Vries", payeeType:"freelancer", paymentTermDays:14, administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: bankAccount object and folded financial fields validate

- **GIVEN** a `Payee` with `bankAccount:{iban:"NL00BANK0123456789", bic:"<BANKNL2A>", accountHolderName:"Jan de Vries"}`, top-level `creditLimit:5000` and `apBalance:1250`
- **WHEN** the record is saved
- **THEN** validation MUST pass and the `bankAccount` object plus both top-level financial fields persist
- **AND** no separate top-level `iban`, `bic`, or `swift` field is required or present.

### Requirement: REQ-AP-013: A `PaymentRun` schema SHALL declare a batch of approved Payee payments with a declarative lifecycle

The system SHALL define a `PaymentRun` schema under the core AP fragment. It
SHALL declare its state machine as `x-openregister-lifecycle` with states
`draft → approved → exported → reconciled` (no PHP state-machine service per
ADR-031). It SHALL carry `runNumber`, `administrationId`, `executionDate`,
`debtorAccountIban`, `status`, `totalAmount`, `currency`, `paymentLines[]`,
`exportedFileRef`, `exportedAt`, `reconciledAt`, and `lifecycleState`. Each
`paymentLines[]` entry SHALL carry `payeeId`, `payeeName`, `creditorIban`,
`amount`, `remittanceInfo`, and `apTransactionRef`. Schema.org annotation:
`schema:PaymentService`.

#### Scenario: PaymentRun lifecycle enforces approval before export

- **GIVEN** a `PaymentRun` in state `draft`
- **WHEN** a `draft → exported` transition is attempted
- **THEN** the lifecycle SHALL reject it; the run MUST first be `approved`.

#### Scenario: PaymentRun lines reference payees and AP invoices

- **GIVEN** a `PaymentRun` with two `paymentLines[]`
- **WHEN** the object is saved
- **THEN** each line carries `payeeId`, `creditorIban`, `amount`, and `apTransactionRef`
- **AND** `totalAmount` equals the sum of the line `amount`s.

### Requirement: REQ-AP-014: The AP menu SHALL collapse to a single group with clean leaf labels

The Accounts-Payable navigation SHALL present exactly one group, with the
"(T2)" suffix removed, and leaf labels: Payees, AP Invoices, AP Aging,
Dunning Notices, Payment Runs. The legacy base `src/manifest.json` AP leaves
(`Vendors`, `AccountsPayable`, `APAging`, `PaymentRuns`) SHALL be removed, AND
the now-orphaned legacy base-AP **page definitions** of the same names SHALL
also be deleted from `src/manifest.json` in this change (the base
`AccountsPayable`/`APAging` page defs used the legacy `APTransaction` schema);
the consolidated module's own pages replace them. This uses the
`IInitialState`-provided manifest consumed by `CnAppRoot` per ADR-024; no
per-leaf route registration is added.

#### Scenario: Single AP group with clean labels

- **WHEN** an operator opens the Bookkeeping menu
- **THEN** exactly one "Accounts Payable" group is shown (no "(T2)" suffix)
- **AND** its leaves are Payees, AP Invoices, AP Aging, Dunning Notices, Payment Runs
- **AND** no legacy `Vendors` / `AccountsPayable` / `APAging` / `PaymentRuns` base leaves appear.

#### Scenario: Orphaned legacy AP page definitions removed

- **WHEN** `src/manifest.json` is inspected after this change
- **THEN** the legacy `Vendors`, `AccountsPayable`, `APAging`, and `PaymentRuns` page definitions are absent
- **AND** no orphaned legacy base-AP page definition remains for a follow-up cleanup.
