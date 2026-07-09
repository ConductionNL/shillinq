# payment-run-sepa-export Specification

## Purpose
TBD - created by archiving change payment-run-sepa-export. Update Purpose after archive.
## Requirements
### Requirement: REQ-SEPA-001: An approved PaymentRun SHALL export to a SEPA pain.001.001.03 file

The system SHALL generate a SEPA Credit Transfer file in **pain.001.001.03**
(ISO 20022 customer credit transfer initiation) XML format from a `PaymentRun`
in lifecycle state `approved`. The file SHALL cover every entry in the run's
`paymentLines[]`, with exactly one `CdtTrfTxInf` (credit-transfer transaction)
per line. Export SHALL be rejected for a `PaymentRun` not in state `approved`.
The generator MUST use the bundled native XML writer (no new composer
dependency), mirroring the `ReportGenerationService` document-generation
pattern. Schema.org annotation: `schema:PaymentService`.

#### Scenario: Export rejected before approval

- **WHEN** an export is requested for a `PaymentRun` in state `draft`
- **THEN** the system SHALL reject the request without generating a file
- **AND** the `PaymentRun` lifecycle state SHALL remain `draft`.

#### Scenario: One credit-transfer transaction per payment line

- **GIVEN** an approved `PaymentRun` with two `paymentLines[]`
- **WHEN** the SEPA pain.001 export runs
- **THEN** the generated XML contains exactly two `CdtTrfTxInf` elements
- **AND** each carries an `EndToEndId`, an `InstdAmt` with `Ccy="EUR"`, a `Cdtr`, and a `CdtrAcct/Id/IBAN`.

### Requirement: REQ-SEPA-002: The pain.001 document SHALL declare the canonical ISO 20022 structure

The generated pain.001.001.03 document SHALL contain a `GrpHdr` (group header)
with `MsgId`, `CreDtTm`, `NbOfTxs`, `CtrlSum`, and `InitgPty`; one
`PmtInf` (payment information) block with `PmtInfId`, `PmtMtd` = `TRF`,
`BtchBookg`, `ReqdExctnDt` (from `PaymentRun.executionDate`),
`Dbtr`/`DbtrAcct` (IBAN from `PaymentRun.debtorAccountIban`)/`DbtrAgt`; and one
`CdtTrfTxInf` per line with `PmtId/EndToEndId`, `Amt/InstdAmt` (`Ccy="EUR"`),
`CdtrAgt` (BIC, optional), `Cdtr`, `CdtrAcct/Id/IBAN`, and `RmtInf` (`Ustrd`
for unstructured or `Strd` for a structured creditor reference). `NbOfTxs`
SHALL equal the line count and `CtrlSum` SHALL equal `PaymentRun.totalAmount`.
All example values used in tests/fixtures SHALL be SAFE placeholders (e.g.
IBAN `NL00BANK0123456789`, BIC `<BANKNL2A>`, `MsgId` `MSGID-PLACEHOLDER`).

#### Scenario: Group header totals match the run

- **GIVEN** an approved `PaymentRun` with 2 lines totalling 1497.50 EUR
- **WHEN** the pain.001 file is generated
- **THEN** `GrpHdr/NbOfTxs` = 2 and `GrpHdr/CtrlSum` = 1497.50
- **AND** `PmtInf/PmtMtd` = `TRF` and `PmtInf/ReqdExctnDt` = the run `executionDate`.

#### Scenario: Remittance info carries the AP reference

- **GIVEN** a payment line with `remittanceInfo` "ENECO-2026-04-0001"
- **WHEN** the file is generated
- **THEN** the corresponding `CdtTrfTxInf/RmtInf/Ustrd` carries "ENECO-2026-04-0001".

### Requirement: REQ-SEPA-003: A CSV fallback SHALL be generated alongside the XML

The system SHALL also generate a CSV fallback file for the same `PaymentRun`,
one row per payment line, with columns `payeeName`, `creditorIban`, `amount`,
`currency`, `remittanceInfo`, `apTransactionRef`. The CSV SHALL be rendered
via `fputcsv` (no new dependency).

#### Scenario: CSV mirrors the payment lines

- **GIVEN** an approved `PaymentRun` with two lines
- **WHEN** the export runs
- **THEN** a CSV file with a header row plus two data rows is produced
- **AND** each row's `amount` and `creditorIban` match the corresponding payment line.

### Requirement: REQ-SEPA-004: Generated files SHALL be stored + tagged in Nextcloud Files via OR

The system SHALL store the generated SEPA artefacts in Nextcloud Files under
`/Shillinq/PaymentRuns/<administrationId>/` (folders created as needed) and
SHALL apply system tags (payment-run number, administration, file type) so the
files are discoverable — reusing the storage + tagging approach of
`ReportGenerationService`. File storage MUST go through `OCP\Files\IRootFolder`
and tagging through `OCP\SystemTag\ISystemTagManager` /
`ISystemTagObjectMapper`. Failures SHALL be fail-soft (logged, not crashing
the request).

#### Scenario: Artefacts stored under the run's administration folder

- **WHEN** the export of `PR-2026-001` (administration `adm-consultancy`) completes
- **THEN** the XML and CSV files exist under `/Shillinq/PaymentRuns/adm-consultancy/`
- **AND** each file carries system tags identifying the payment-run number and administration.

### Requirement: REQ-SEPA-005: Export SHALL write back the file reference and drive the lifecycle

On a successful export, the system SHALL set `PaymentRun.exportedFileRef` to
the stored XML file reference, set `exportedAt` to the export timestamp, and
drive the declarative `approved → exported` lifecycle transition (defined by
`consolidate-accounts-payable`). The transition MUST be performed through
OpenRegister's lifecycle engine, not a bespoke PHP state machine.

#### Scenario: PaymentRun transitions to exported

- **GIVEN** an approved `PaymentRun`
- **WHEN** the SEPA export succeeds
- **THEN** `exportedFileRef` points at the stored XML file
- **AND** `exportedAt` is set
- **AND** the `PaymentRun` lifecycle state becomes `exported`.

### Requirement: REQ-SEPA-006: An "Export to bank" trigger SHALL invoke the export

The system SHALL expose an "Export to bank" action on the `PaymentRun` detail
page that invokes the export via a controller endpoint registered in
`appinfo/routes.php` (ADR-016). The endpoint MUST declare its auth posture via
a Nextcloud attribute and guard the request per ADR-005 (the caller must be
authorised for the `PaymentRun`'s administration). Uses
`OCP\AppFramework\Controller` + `IInitialState`-driven frontend per ADR-004.

#### Scenario: Operator exports from the detail page

- **GIVEN** an operator viewing an approved `PaymentRun` detail page
- **WHEN** they click "Export to bank"
- **THEN** the export endpoint is invoked
- **AND** on success the detail page reflects the `exported` state and the stored file reference.

#### Scenario: Unauthorised export is rejected

- **WHEN** a user not authorised for the run's administration calls the export endpoint
- **THEN** the request is rejected before any file is generated.

### Requirement: REQ-SEPA-007: An exported PaymentRun SHALL reconcile against an imported CAMT.053 bank statement

The system SHALL reconcile a `PaymentRun` in lifecycle state `exported`
against an imported **CAMT.053** (ISO 20022 bank-to-customer account
statement) file. The system SHALL parse the statement's booked outgoing
credit-transfer entries and match each back to the run's `paymentLines[]`,
using `EndToEndId` as the primary key (the deterministic
`<runNumber>-<lineIndex>` value emitted in the pain.001 export) and falling
back to `(amount, creditorIban)` against an as-yet-unmatched line. On a **full**
match (every line matched), the system SHALL set `PaymentRun.reconciledAt` and
drive the declarative `exported → reconciled` lifecycle transition (defined by
`consolidate-accounts-payable`) through OpenRegister's lifecycle engine — not a
bespoke PHP state machine. On a **partial or unmatched** result, the run SHALL
remain `exported` and the system SHALL record a mismatch note; it SHALL NOT
transition the run. CAMT.053 parsing MUST use the bundled native XML reader (no
new composer dependency). A "Reconcile / import statement" action on the
`PaymentRun` detail page SHALL invoke reconciliation via a controller endpoint
registered in `appinfo/routes.php` (ADR-016), declaring its auth posture via a
Nextcloud attribute and guarding the caller per ADR-005. All example values in
tests/fixtures SHALL be SAFE placeholders (e.g. IBAN `NL00BANK0123456789`,
`EndToEndId` `PR-2026-001-1`, statement id `STMT-PLACEHOLDER`). Schema.org
annotation: `schema:PaymentService`.

#### Scenario: Full match transitions the run to reconciled

- **GIVEN** an exported `PaymentRun` `PR-2026-001` with two lines (EndToEndIds `PR-2026-001-1`, `PR-2026-001-2`)
- **WHEN** a CAMT.053 statement is imported whose two booked entries echo both EndToEndIds with matching amounts and creditor IBANs
- **THEN** both lines match
- **AND** `reconciledAt` is set
- **AND** the `PaymentRun` lifecycle state becomes `reconciled`.

#### Scenario: Fallback matches on amount + creditor IBAN

- **GIVEN** an exported `PaymentRun` line for 605.00 EUR to creditor IBAN `NL00TEST0222222222`
- **WHEN** the CAMT.053 entry omits the `EndToEndId` but carries amount 605.00 and that creditor IBAN
- **THEN** the entry matches that payment line via the amount + creditor IBAN fallback.

#### Scenario: Partial match leaves the run exported

- **GIVEN** an exported `PaymentRun` with two lines
- **WHEN** a CAMT.053 statement is imported that matches only one line
- **THEN** the `PaymentRun` lifecycle state SHALL remain `exported`
- **AND** a mismatch note recording the unmatched line(s) is set
- **AND** `reconciledAt` is NOT set.

#### Scenario: Unauthorised reconciliation is rejected

- **WHEN** a user not authorised for the run's administration calls the reconcile endpoint
- **THEN** the request is rejected before the statement is processed.

