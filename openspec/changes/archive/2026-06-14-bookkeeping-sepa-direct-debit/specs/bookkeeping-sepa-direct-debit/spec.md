# Spec: bookkeeping-sepa-direct-debit

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md` (Invoice + Counterparty),
`../bookkeeping-bank-connectors/spec.md` (pain.008 submission, pain.002/camt.054 ingestion),
`../add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md` (bank account + receivable accounts)

## ADDED Requirements

### Requirement: REQ-SDD-001: Mandate registration with scheme rules enforced

The system MUST allow a bookkeeper to register a SEPA mandate and
MUST enforce the structural rules of the SDD CORE or SDD B2B rulebook
at registration time.

The `SepaMandate` schema SHALL declare the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `mandateReference` | string | Yes | Unique per creditor, max 35 chars, immutable once issued |
| `creditorIdentifier` | string | Yes | Dutch `NL{check}ZZZ{KvK}` per De Nederlandsche Bank |
| `scheme` | enum | Yes | One of `CORE` (consumer) or `B2B` (business) |
| `type` | enum | Yes | One of `recurring` or `oneoff` |
| `status` | enum | Yes | One of `pending`, `active`, `cancelled`, `expired`, `suspended` |
| `signedAt` | date | Yes | Date mandate was signed |
| `signedBy` | string | Yes | Debtor name as it appears on mandate document |
| `debtorIban` | string | Yes | Debtor's bank account IBAN |
| `debtorBic` | string | No | BIC/SWIFT code (mandatory for non-EEA destinations; optional for EEA) |
| `debtorName` | string | Yes | Debtor's legal name or trading name |
| `debtorAddress` | object | No | Street, postcode, city, country |
| `debtorAccountType` | enum | Yes | One of `consumer` (CORE only) or `business` (B2B only) |
| `firstCollectionDate` | date | No | First permissible collection date |
| `lastCollectionDate` | date | No | Last known or planned collection date (nullable) |
| `lastUsedAt` | date | No | Date of last successful collection (used for 36-month dormancy rule) |
| `mandateDocument` | file | No | Scanned signature or digital-signing evidence (PDF/image) |
| `cancellationReason` | string | No | Free-text reason if status = `cancelled` |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:FinancialProduct` or `schema:BankAccount`.

#### Scenario: CORE mandate for consumer is accepted

- **GIVEN** a bookkeeper registering a CORE mandate
- **WHEN** they submit `scheme: CORE`, `type: recurring`, `debtorIban: NL91ABNA0417164300`, `debtorAccountType: consumer`, `signedAt: 2026-05-10`, `signedBy: J. de Vries`, and `mandateReference: MAND-2026-0042` (valid format, not duplicate for this creditor)
- **THEN** the mandate MUST be persisted with `status: active`, and the system MUST NOT auto-generate a `mandateReference` (supplied value accepted).

#### Scenario: B2B mandate with consumer account type is rejected

- **GIVEN** a bookkeeper submitting a B2B mandate
- **WHEN** they attempt to set `scheme: B2B` with `debtorAccountType: consumer`
- **THEN** the save MUST fail with error code `sdd.mandate.scheme.mismatch` because SDD B2B is reserved for business debtors.

#### Scenario: Duplicate mandate reference is rejected

- **GIVEN** a mandate with `mandateReference: MAND-2026-0042` already active for the same creditor identifier
- **WHEN** a bookkeeper attempts to register a second mandate with the same reference
- **THEN** the save MUST fail with error code `sdd.mandate.reference.duplicate`.

### Requirement: REQ-SDD-002: Sequence-type derivation per collection

The system MUST automatically determine the correct `sequenceType`
for every collection based on the mandate's history, per SDD rulebook
section 4.3 (sequence types).

The `DirectDebitCollection` schema SHALL declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `mandateId` | string | Yes | FK to `SepaMandate` UUID |
| `invoiceId` | string | No | FK to `Invoice` UUID (nullable for ad-hoc collections) |
| `amount` | number | ≥ 0.01, ≤ 2 decimals) | Yes | Collection amount in EUR |
| `currency` | string | Yes | Always EUR for SEPA; hardcoded |
| `sequenceType` | enum | Yes | Auto-derived: one of `FRST`, `RCUR`, `OOFF`, `FNAL` |
| `requestedCollectionDate` | date | Yes | Date funds should hit creditor account |
| `endToEndId` | string | Yes | Max 35 chars, unique per creditor (e.g., `SDD-2026-00451`) |
| `status` | enum | Yes | One of `scheduled`, `submitted`, `accepted_by_bank`, `presented`, `succeeded`, `rejected`, `refunded` |
| `submittedInBatchId` | string | No | FK to `DirectDebitBatch` once submitted |
| `pain002ReasonCode` | string | No | ISO 20022 reason code if rejected (e.g., `MS03`, `AC04`, `AM04`) |
| `camt054ReferenceId` | string | No | Reference ID from camt.054 notification |
| `repostedAsCollectionId` | string | No | FK to new `DirectDebitCollection` if this one was rejected and reposted |
| `administrationId` | string | Yes | FK to administration |

**Sequence-type rules:**
- **FRST** (first): mandate's first collection ever, or first collection after scheme change
- **RCUR** (recurrent): any collection after a FRST or RCUR that succeeded (or any status other than `rejected`/`refunded`)
- **OOFF** (one-off): only for mandates with `type: oneoff`; the system MUST refuse to schedule a second collection against the same one-off mandate
- **FNAL** (final): a collection explicitly marked as the last against a recurring mandate (operator-triggered or automatic on mandate cancellation)

The system MUST calculate `sequenceType` at collection creation time
based on the mandate's previous collection states. Operator input of
`sequenceType` MUST be rejected.

Schema.org annotation: `schema:PaymentMethod` or `schema:FinancialProduct`.

#### Scenario: First recurring collection defaults to FRST

- **GIVEN** a recurring mandate with no previous collections
- **WHEN** a collection is scheduled for `requestedCollectionDate: 2026-07-15`
- **THEN** the system MUST auto-set `sequenceType: FRST` and MUST NOT allow manual override.

#### Scenario: Subsequent successful collection defaults to RCUR

- **GIVEN** a recurring mandate whose last collection has `status: succeeded`
- **WHEN** a new collection is scheduled
- **THEN** the system MUST auto-set `sequenceType: RCUR`.

#### Scenario: One-off mandate allows only one collection

- **GIVEN** a mandate with `type: oneoff` and one collection already in `succeeded` state
- **WHEN** an operator attempts to schedule a second collection against the same mandate
- **THEN** the system MUST refuse with error code `sdd.mandate.oneoff.second_collection_refused`.

### Requirement: REQ-SDD-003: Pre-notification with default 14 days lead time

For every scheduled collection the system MUST generate a `PreNotification`
and MUST NOT allow the collection to be included in a generated pain.008
batch unless the pre-notification has been sent or marked as included
on the invoice line (vooraankondiging per Dutch SME practice).

The `PreNotification` schema SHALL declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `collectionId` | string | Yes | FK to `DirectDebitCollection` UUID |
| `sentAt` | datetime | No | When notification was actually sent (null = not yet sent) |
| `channel` | enum | No | One of `email`, `letter`, `invoice_line` (null = not yet sent) |
| `noticeDays` | integer | Yes | Number of calendar days between notification and collection date |
| `recipientAddress` | string | No | Email, postal address, or invoice reference (per channel) |
| `administrationId` | string | Yes | FK to administration |

**Pre-notification contract:**
- Default lead time: 14 calendar days per SDD CORE rulebook section 2.6
- Minimum lead time: configurable per mandate down to rulebook's contractual minimum (14 days for CORE; varies for B2B)
- Proof of notification: email delivery receipt, letter registered-mail proof, or invoice line annotation
- Blocking rule: no pain.008 submission until `sentAt IS NOT NULL` or invoice carries the line annotation

#### Scenario: Default 14-day pre-notification is created

- **GIVEN** a collection scheduled for `requestedCollectionDate: 2026-07-15` with no contractual deviation
- **WHEN** the collection is created
- **THEN** a `PreNotification` MUST be generated with `noticeDays: 14`, and the earliest valid send date MUST be 2026-07-01.

#### Scenario: Invoice line annotation counts as pre-notification

- **GIVEN** an invoice issued on 2026-06-25 with `paymentMethod: direct_debit` and a line reading "Wij incasseren EUR 89 op of rond 2026-07-15 onder mandaatkenmerk MAND-2026-0042"
- **WHEN** the bookkeeper marks the invoice as the pre-notification carrier (via `Invoice.directDebitPreNotificationInvoiceId`)
- **THEN** the system MUST link the invoice to the collection's `PreNotification` (no separate email needed), and the batch-generation block MUST be lifted.

#### Scenario: Insufficient pre-notification lead time blocks batch generation

- **GIVEN** a collection scheduled for 2026-07-15 where pre-notification was sent only 6 days before
- **WHEN** the bookkeeper attempts to generate a batch including it
- **THEN** the system MUST block with error code `sdd.prenotification.too_short`, identify which collection(s) violate the deadline, and offer to push the collection date forward.

### Requirement: REQ-SDD-004: Submission-window enforcement

The system MUST enforce the SDD CORE submission windows (D-5 business
days for FRST/OOFF, D-2 business days for RCUR/FNAL) and the SDD B2B
window (D-1 business day for all sequence types) when generating a batch.
A "business day" is any day except Saturday, Sunday, and Dutch public
holidays.

#### Scenario: FRST within window is accepted

- **GIVEN** a FRST collection in a CORE batch with `requestedCollectionDate: 2026-07-15` (a Wednesday)
- **WHEN** the bookkeeper attempts batch generation on 2026-07-10 (a Thursday, which is 3 business days before due)
- **THEN** the system MUST block with error code `sdd.submission.window.late`, indicate the latest valid generation date was 2026-07-09 (D-5 counting back from Wednesday), and refuse the batch.

#### Scenario: RCUR at D-2 boundary is accepted

- **GIVEN** a RCUR collection in a CORE batch with `requestedCollectionDate: 2026-07-15` (Wednesday)
- **WHEN** the bookkeeper generates a batch on 2026-07-13 (Monday, which is 2 business days counting back: Friday 12th, Monday 13th would be 1; recalc: Wed 15 - 2 bus days = Mon 13)
- **THEN** the system MUST accept the batch (2 business days lead time satisfied).

#### Scenario: B2B collection with D-1 lead time is accepted

- **GIVEN** a B2B collection with `requestedCollectionDate: 2026-07-15` (Wednesday)
- **WHEN** the bookkeeper generates a batch on 2026-07-14 (Tuesday, 1 business day lead time)
- **THEN** the system MUST accept.

### Requirement: REQ-SDD-005: pain.008.001.02 generation and validation

The system MUST generate ISO 20022 `pain.008.001.02` XML conforming
to the EPC SEPA Direct Debit Core Implementation Guidelines and the
Dutch overlay (Betaalvereniging Nederland addendum), and MUST validate
the generated payload against the EBA / Equens / SurePay XSDs and the
Dutch overlay rules before marking the batch as `generated`.

The `DirectDebitBatch` schema SHALL declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `messageId` | string | Yes | Max 35 chars, globally unique per creditor per file (e.g., `SDD-MSG-2026-0001`) |
| `creationDateTime` | datetime | Yes | Timestamp when batch was generated |
| `requestedCollectionDate` | date | Yes | The earliest collection date in the batch |
| `scheme` | enum | Yes | One of `CORE` or `B2B` |
| `sequenceType` | enum | Yes | One of `FRST`, `RCUR`, `OOFF`, `FNAL` (batch is homo-sequence per SDD rulebook) |
| `collectionCount` | integer | Yes | Number of collections in batch |
| `controlSum` | number | Yes | Sum of all collection amounts (EUR, 2 decimals) |
| `status` | enum | Yes | One of `draft`, `generated`, `submitted`, `accepted_by_bank`, `partially_rejected`, `fully_rejected` |
| `pain008Xml` | string | Yes | Full ISO 20022 pain.008.001.02 XML payload (archived 7+ years per bewaarplicht) |
| `pain002Xml` | string | No | Incoming pain.002 status report (archived) |
| `submittedAt` | datetime | No | When batch was submitted to bank via connector |
| `administrationId` | string | Yes | FK to administration |

**pain.008 generation rules:**
- Validate every collection's mandate: `signedAt MUST NOT be after requestedCollectionDate` (mandate not signed yet when collection is due)
- Validate every collection's pre-notification: `sentAt IS NOT NULL OR invoiceLine annotation exists` (REQ-SDD-003)
- Validate every collection's debtor IBAN against IBAN checksum (mod-97 algorithm)
- Generate exactly one `PmtInf` (payment information) block per unique `(scheme, sequenceType, requestedCollectionDate)` tuple (e.g., if batch mixes FRST and RCUR on the same date, two `PmtInf` blocks)
- Carry creditor identifier in `CdtrSchmeId/Id/PrvtId/Othr/Id` with `SchmeNm/Prtry: SEPA`
- Carry `CtrlSum` (sum of all transaction amounts in the block) and `NbOfTxs` (count)
- Carry debtor BIC if `debtorBic IS NOT NULL`; BIC is mandatory for non-EEA destinations

#### Scenario: Valid 3-collection batch generates and validates

- **GIVEN** a batch with 3 active collections totaling EUR 247.50 with valid mandates, pre-notifications, and IBANs
- **WHEN** the batch is generated
- **THEN** the resulting pain.008 MUST validate against the published `pain.008.001.02.xsd`, MUST include exactly one `PmtInf` block per unique `(scheme, sequenceType, requestedCollectionDate)` tuple, MUST carry the creditor identifier with `SchmeNm/Prtry: SEPA`, and MUST carry `CtrlSum: 247.50` with `NbOfTxs: 3`.

#### Scenario: Mandate signed after collection date fails generation

- **GIVEN** any collection in a batch references a mandate where `signedAt: 2026-07-20`
- **WHEN** the collection's `requestedCollectionDate: 2026-07-15` (before signing)
- **WHEN** generation is attempted
- **THEN** it MUST fail with error code `sdd.mandate.signed.after.collection`.

### Requirement: REQ-SDD-006: pain.002 ingestion and status update

The system MUST ingest pain.002 status reports from the creditor's bank
(delivered manually as file upload, or automatically via OpenConnector
bank-connector polling) and MUST update each referenced `DirectDebitCollection`
and `DirectDebitBatch` accordingly.

#### Scenario: Batch fully accepted by bank

- **GIVEN** a submitted batch with 10 collections and an incoming pain.002 with `GrpSts: ACCP` and no `TxInfAndSts` entries (all-accepted shorthand)
- **WHEN** the pain.002 is ingested
- **THEN** the batch MUST transition to `accepted_by_bank` and all 10 collections MUST transition to `accepted_by_bank`.

#### Scenario: Partial rejection updates only affected collections

- **GIVEN** a submitted batch with 10 collections and an incoming pain.002 with `GrpSts: PART` containing one `TxInfAndSts` with `TxSts: RJCT`, `StsRsnInf/Rsn/Cd: AC04` (closed account), and `OrgnlTxRef/EndToEndId: SDD-2026-00451`
- **WHEN** ingested
- **THEN** the batch MUST transition to `partially_rejected`, the single collection matching `endToEndId: SDD-2026-00451` MUST transition to `rejected` with `pain002ReasonCode: AC04`, and the remaining 9 collections MUST remain in their current state or transition to `accepted_by_bank`.

### Requirement: REQ-SDD-007: camt.054 reconciliation and R-transaction handling

The system MUST reconcile camt.054 debit notifications and credit-side
R-transaction notifications against the originating collections and MUST
capture R-transactions in the `RTransaction` schema.

The `RTransaction` schema SHALL declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `collectionId` | string | Yes | FK to `DirectDebitCollection` UUID |
| `type` | enum | Yes | One of `reject`, `return`, `refund`, `reversal`, `revocation`, `request_for_cancellation` |
| `reasonCode` | string | Yes | ISO 20022 ExternalReturnReason code (e.g., `MS03`, `AC04`, `MD01`) |
| `reasonText` | string | No | Human-readable reason from bank |
| `originatorBic` | string | No | BIC of the bank or institution initiating the R-transaction |
| `transactionAmount` | number | Yes | Amount of the R-transaction (usually matches collection amount) |
| `valueDate` | date | Yes | Date the debtor's account was re-credited (for returns/refunds) |
| `notifiedAt` | datetime | Yes | When shillinq received the R-transaction notification |
| `administrationId` | string | Yes | FK to administration |

**R-transaction handling rules:**
- Debit notification (camt.054 debit entry matching `endToEndId`) → transition collection to `succeeded`, mark invoice as paid, post journal entry (debit bank, credit receivable)
- Return notification (camt.054 return entry or pain.002 reason `MS03`, `AC01`, `AC04`, `AM04`, `SL02`, `RR01`, `RR02`) → transition collection to `refunded`, re-open invoice, reverse journal entry, create `RTransaction` record
- Refund notification (camt.054 refund or reason `MD06` "consumer refund request" under PSD2 Art. 76) → identical to return, AND flag the mandate for bookkeeper review (high refund rate → bank-imposed scheme exclusion risk)

#### Scenario: Successful debit closes collection

- **GIVEN** a successful camt.054 entry referencing `endToEndId: SDD-2026-00451` for amount EUR 500
- **WHEN** ingested
- **THEN** the matching collection MUST transition to `succeeded`, the corresponding invoice MUST be marked paid, and a journal entry (debit bank account, credit receivable) MUST be posted.

#### Scenario: Return (no reason) reverses collection

- **GIVEN** an incoming Return notification with `RtrInf/Rsn/Cd: MS03` (no reason given by debtor) on a collection that was already `succeeded`
- **WHEN** ingested
- **THEN** an `RTransaction` of type `return` MUST be created with `reasonCode: MS03`, the originating collection MUST transition to `refunded`, the receivable MUST be re-opened on the invoice, and the bank journal entry MUST be reversed.

#### Scenario: Consumer refund request triggers mandate review

- **GIVEN** an incoming Refund notification with reason code `MD06` (consumer refund request under PSD2 Art. 76) for a CORE collection
- **WHEN** ingested
- **THEN** the system MUST treat it identically to a return AND MUST flag the mandate for bookkeeper review (high refund rate can lead to bank-imposed scheme exclusion per SDD Core rulebook section 3.1).

### Requirement: REQ-SDD-008: Mandate cancellation and dormancy expiry

The system MUST allow a bookkeeper to cancel a mandate, MUST refuse to
schedule any further collections against a cancelled mandate, and MUST
automatically mark mandates as `expired` after 36 months of inactivity
per the rulebook.

#### Scenario: Mandate cancellation blocks future collections

- **GIVEN** an active recurring mandate
- **WHEN** the bookkeeper cancels it with reason "Klant heeft opgezegd op 2026-05-21"
- **THEN** the mandate MUST transition to `status: cancelled`, `cancellationReason` MUST be persisted, and any future-dated `scheduled` collection against it MUST be cancelled automatically.

#### Scenario: Dormancy expiry after 36 months

- **GIVEN** an active recurring mandate whose `lastUsedAt` was 2023-05-22
- **WHEN** the daily expiry job runs on 2026-05-22 (exactly 36 months later)
- **THEN** the mandate MUST transition to `status: expired`, and the bookkeeper MUST be notified (email + dashboard alert) that a fresh mandate is required before further collection.

#### Scenario: Collection against cancelled mandate is refused

- **GIVEN** a cancelled mandate
- **WHEN** the bookkeeper tries to schedule a new collection against it
- **THEN** the system MUST refuse with error code `sdd.mandate.cancelled`.

### Requirement: REQ-SDD-009: Reposting of rejected collections

When a collection moves to `rejected` or `refunded` because of a
bank-side problem rather than a debtor refusal, the system MUST offer
one-click reposting that creates a new `DirectDebitCollection` linked
back via `repostedAsCollectionId` and preserving the original invoice link.

#### Scenario: Reposting after insufficient-funds rejection

- **GIVEN** a rejected collection with `pain002ReasonCode: AM04` (insufficient funds) for invoice INV-2026-1018
- **WHEN** the bookkeeper clicks "Opnieuw incasseren" (retry)
- **THEN** a new collection MUST be created against the same mandate for the same amount, scheduled for the next valid collection date respecting REQ-SDD-004, with `sequenceType: RCUR` (because the mandate is now active), and the originating collection's `repostedAsCollectionId` MUST point to the new collection UUID.

#### Scenario: Reposting after debtor-refusal rejection is forbidden

- **GIVEN** a rejected collection with `pain002ReasonCode: MD01` (no mandate / mandate cancelled by debtor)
- **WHEN** the bookkeeper clicks "Opnieuw incasseren"
- **THEN** the system MUST refuse with error code `sdd.mandate.debtor_refusal`, explain that reposting against a refused/revoked mandate is forbidden by the rulebook, and advise pursuing the receivable through dunning or manual bank transfer.

### Requirement: REQ-SDD-010: Audit-trail and bewaarplicht

The system MUST retain every generated pain.008, every ingested pain.002
and camt.054, every PreNotification, and every signed mandate document
for at least 7 years (Dutch fiscal bewaarplicht per VAT Act and VAT
Records Directive) and MUST be able to produce, within 10 seconds, a
per-mandate or per-invoice audit bundle containing the mandate document,
every collection against it, every R-transaction, and the underlying
journal lines.

#### Scenario: Audit bundle export for mandate

- **GIVEN** a mandate with 24 successful and 1 refunded collection over 2 years
- **WHEN** the bookkeeper clicks "Exporteer dossier" on the mandate detail page
- **THEN** a ZIP file MUST be produced within 10 seconds containing:
  - The signed mandate PDF/image
  - A CSV of all 25 collections with status, amount, dates, reason codes
  - The pain.008 XML fragments that initiated each collection
  - The pain.002 and camt.054 XML entries that closed each
  - The journal entries (debit/credit pairs) for each successful collection and any reversals

#### Scenario: Audit bundle export for invoice

- **GIVEN** an invoice with 3 collections attempted under different mandates
- **WHEN** the bookkeeper exports the invoice dossier
- **THEN** the ZIP MUST contain the invoice PDF, the 3 collections' details, the associated pain files, the R-transaction records for any rejections, and the journal entries.

#### Scenario: No auto-deletion of archived pain files

- **GIVEN** any pain.008, pain.002, or camt.054 file older than 7 years
- **WHEN** the housekeeping job runs
- **THEN** the system MUST NOT auto-delete it; deletion MUST be an explicit bookkeeper action with a retention-override warning acknowledging legal liability.
