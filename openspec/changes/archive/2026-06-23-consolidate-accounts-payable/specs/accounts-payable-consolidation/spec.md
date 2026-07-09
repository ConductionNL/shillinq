## ADDED Requirements

### Requirement: Single canonical Accounts-Payable module

The system SHALL expose exactly ONE Accounts-Payable module, built on the
canonical core fragment (`Payee`, `APTransaction`, `DunningNotice`,
`PaymentRun`). The legacy base-monolith AP (`VendorFinancialProfile` +
legacy `PaymentRun`) SHALL be retired. No duplicate vendor master, no
duplicate AP-invoice list, and no duplicate "Accounts Payable" navigation
entry SHALL remain. The legacy base-AP **page definitions** in
`src/manifest.json` (`Vendors`, `AccountsPayable`, `APAging`, `PaymentRuns` —
the base `AccountsPayable`/`APAging` pages used the legacy `APTransaction`
schema) SHALL be deleted in this change (not just their menu leaves); the
consolidated module's own pages replace them. (Schema.org: the module governs
`schema:Organization` payees and `schema:Invoice` transactions.)

#### Scenario: Only one AP module is live in OpenRegister

- **WHEN** the regenerated `shillinq_register.json` is imported into OpenRegister
- **THEN** the live register contains `Payee`, `APTransaction`, `DunningNotice`, and `PaymentRun`
- **AND** the live register contains NO `VendorFinancialProfile` schema
- **AND** the live register contains no second `PaymentRun` definition

#### Scenario: Navigation shows one AP entry point

- **WHEN** an operator opens the Bookkeeping menu
- **THEN** there is exactly one "Accounts Payable" group (no "(T2)" suffix)
- **AND** the legacy `Vendors`, `AccountsPayable`, `APAging`, and `PaymentRuns` base leaves are absent

#### Scenario: Orphaned legacy AP page definitions are removed

- **WHEN** `src/manifest.json` is inspected after this change
- **THEN** the legacy base-AP page definitions (`Vendors`, `AccountsPayable`, `APAging`, `PaymentRuns`) are absent
- **AND** no orphaned page definition referencing the legacy AP topology remains

### Requirement: Payee is the canonical "anyone we pay" master

The `Payee` schema SHALL be the single canonical master for any party the
administration pays — suppliers, vendors, and freelancers (Dutch ZZP'ers /
sole traders) alike. It SHALL carry a `payeeType` enum with values
`[supplier, vendor, freelancer, contractor, government, other]`. It SHALL
carry the financial fields folded in from the retired `VendorFinancialProfile`
as top-level fields: `creditLimit` and `apBalance`. Its `bankAccount` field
SHALL be a structured object `{ iban, bic, accountHolderName }` into which the
retired profile's `iban`/`bic` fold (NOT as separate top-level `Payee`
fields). The `bic` field is the bank's ISO 9362 identifier; because a "SWIFT
code" IS a BIC, no separate `swift` field SHALL be added. (Schema.org:
`schema:Organization`.)

**Non-Goal**: true non-SEPA / cross-border international payments (non-IBAN
account numbers, intermediary/correspondent banks) are out of scope — a future
capability. The `bankAccount` object models SEPA IBAN/BIC only.

#### Scenario: Payee distinguishes a freelancer from a supplier

- **WHEN** a freelancer (ZZP'er) record is created with `payeeType="freelancer"`
- **AND** a utility supplier record is created with `payeeType="supplier"`
- **THEN** both validate against the single `Payee` schema
- **AND** the AP module treats both as payable parties

#### Scenario: bankAccount is a structured object holding iban/bic

- **WHEN** the `Payee` schema is loaded
- **THEN** it exposes top-level `creditLimit` and `apBalance`
- **AND** its `bankAccount` field is an object exposing `iban`, `bic`, and `accountHolderName`
- **AND** no separate top-level `iban`, `bic`, or `swift` field exists on `Payee`

### Requirement: VendorFinancialProfile is retired

The system SHALL remove the `VendorFinancialProfile` schema. Its stale entry
in the generated `shillinq_register.json` SHALL be deleted, and the absence
of any `register.d` source for it SHALL be confirmed. The
`MigrateProductVendorMasterToPipelinq` repair SHALL be repointed to target
`Payee` instead of `VendorFinancialProfile`.

**Reason**: `VendorFinancialProfile` duplicated the canonical `Payee`
master, was never loaded in live OR, and had no `register.d` source — it
existed only as a stale generated-register entry.

**Migration**: All `VendorFinancialProfile` unique fields (`creditLimit`,
`apBalance`, `iban`, `bic`) fold into `Payee`. Existing test data is
re-seeded as `Payee` objects (no production data exists).

#### Scenario: No VendorFinancialProfile source remains

- **WHEN** the repository is searched for a `register.d` source defining `VendorFinancialProfile`
- **THEN** no such source file is found
- **AND** the regenerated `shillinq_register.json` contains no `VendorFinancialProfile` schema or objects

#### Scenario: Repair targets Payee

- **WHEN** the `MigrateProductVendorMasterToPipelinq` repair runs
- **THEN** it reads and writes `Payee` objects, not `VendorFinancialProfile`

### Requirement: PaymentRun batch schema with declarative lifecycle

The system SHALL define a `PaymentRun` schema under the core AP fragment
representing a batch of approved `Payee` payments. It SHALL declare its state
machine as a declarative `x-openregister-lifecycle` with states
`draft → approved → exported → reconciled`, with no PHP state-machine
service. It SHALL carry: `runNumber`, `administrationId`, `executionDate`,
`debtorAccountIban`, `status`, `totalAmount`, `currency`, `paymentLines[]`,
`exportedFileRef`, `exportedAt`, `reconciledAt`, `lifecycleState`. Each entry
in `paymentLines[]` SHALL carry `payeeId`, `payeeName`, `creditorIban`,
`amount`, `remittanceInfo` (unstructured or structured reference), and
`apTransactionRef`. (Schema.org: `schema:PaymentService`.)

#### Scenario: PaymentRun declares a declarative lifecycle

- **WHEN** the `PaymentRun` schema is loaded
- **THEN** it declares `x-openregister-lifecycle` with states draft, approved, exported, reconciled
- **AND** no `lib/Service/PaymentRunService.php` state-machine class exists

#### Scenario: PaymentRun carries payment lines referencing payees and AP invoices

- **WHEN** a `PaymentRun` object is created with two `paymentLines[]`
- **THEN** each line carries `payeeId`, `creditorIban`, `amount`, `remittanceInfo`, and `apTransactionRef`
- **AND** `totalAmount` equals the sum of the line amounts

#### Scenario: Approval gates export

- **WHEN** a `PaymentRun` is in state `draft`
- **THEN** the lifecycle SHALL NOT permit a direct `draft → exported` transition
- **AND** the run MUST pass through `approved` before it can be `exported`
