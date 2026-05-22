---
status: draft
---
# SEPA Direct Debit (Incasso) generation + mandate tracking

## Purpose

SEPA Direct Debit (in the Netherlands universally referred to as "incasso" or "SEPA-incasso") is the standard mechanism through which a Dutch SME collects recurring or one-off payments directly from a debtor's bank account, on the strength of a signed mandate (machtiging). It is the backbone of subscription billing, membership fees, tuition, gym contributions, utility bills, and rent collection across the country. The scheme is operated by the European Payments Council (EPC) under the SEPA Direct Debit Core Rulebook (SDD CORE) for consumer debtors and the SEPA Direct Debit Business-to-Business Rulebook (SDD B2B) for business debtors, with technical exchange formats defined in ISO 20022 (`pain.008.001.02` for initiation, `pain.002.001.03` for status, `camt.054` for collection details on the receivable side).

For a Dutch SME using shillinq today, the absence of incasso functionality is the single largest practical gap relative to incumbents like Exact, AFAS, or Moneybird. Without it the bookkeeper has to log into the bank portal, manually upload an Excel template (or worse, key in each collection one by one), keep a separate spreadsheet of mandates, manually pre-notify debtors at the prescribed lead time, and then reconcile rejections by eye when bank statements come back days later. This is error-prone (a single typo in an IBAN causes a charged collection fee, a missed pre-notification gives the debtor an unconditional right to reversal within 8 weeks, and a missing mandate signature exposes the SME to an unconditional 13-month right of reversal under the Payment Services Directive PSD2), time-consuming (a small association with 200 members spends 4-6 hours per month on incasso operations), and bank-locked (each migration to a new bank requires the bookkeeper to relearn a portal). It also blocks adoption by exactly the SME segments that incasso is most valuable for: associations, sports clubs, after-school care, music schools, small SaaS vendors.

This change adds a complete SEPA Direct Debit module to shillinq: mandate registration and lifecycle (CORE vs B2B, one-off vs recurring, captured digitally or scanned from a paper signature), automatic sequence-type management (FRST for the first collection on a recurring mandate, RCUR for subsequent, FNAL for the last, OOFF for one-off), pre-notification (vooraankondiging) generation with the legally required 14-day default (configurable per mandate down to the contractual minimum), pain.008 file generation that validates against the current EBA Clearing and Equens schemas plus the Dutch overlay, submission-window awareness (5 business days for FRST/OOFF, 2 business days for RCUR/FNAL under SDD CORE; D-1 for SDD B2B), pain.002 and camt.054 ingestion to mark collections as successful, rejected (R-transactions) or refunded, automatic reposting of rejected collections to the debtor's account with the original invoice link preserved, and a mandate cancellation flow that respects the "no further collection" rule of the rulebook. The result is an end-to-end incasso loop that brings shillinq to feature parity with the incumbents on the workflow that drives the largest fraction of SME bookkeeping pain.

## Data Model

A new `SepaMandate` schema captures the bilateral instruction from debtor to creditor. Fields are `id`, `mandateReference` (unique per creditor, max 35 chars per the rulebook, immutable once issued), `creditorIdentifier` (the Dutch `NL{check}ZZZ{KvK}` identifier issued by De Nederlandsche Bank via the bank), `scheme` (`CORE` / `B2B`), `type` (`recurring` / `oneoff`), `status` (`pending` / `active` / `cancelled` / `expired` / `suspended`), `signedAt` (date), `signedBy` (debtor name as on the mandate document), `debtorIban`, `debtorBic` (optional for IBAN-only countries; mandatory for non-EEA SEPA destinations), `debtorName`, `debtorAddress`, `debtorAccountType` (`consumer` for CORE / `business` for B2B; must match scheme), `firstCollectionDate`, `lastCollectionDate` (nullable), `lastUsedAt` (nullable; used to enforce the 36-month dormancy expiry rule of the rulebook), `mandateDocument` (file attachment, image or PDF of the signed paper or the digital-signing evidence), and `cancellationReason` (nullable).

A new `DirectDebitCollection` schema represents a single collection attempt against a single mandate. Fields are `id`, `mandateId`, `invoiceId` (the receivable being collected; nullable for ad-hoc collections), `amount` (euros, two decimals), `currency` (always EUR for SEPA scheme), `sequenceType` (`FRST` / `RCUR` / `OOFF` / `FNAL`), `requestedCollectionDate` (the date funds should hit the creditor's account, which is the date on which the debtor's account is debited), `endToEndId` (max 35 chars, unique per creditor, used by the debtor's bank to surface the collection in the debtor's statement), `status` (`scheduled` / `submitted` / `accepted_by_bank` / `presented` / `succeeded` / `rejected` / `refunded`), `submittedInBatchId`, `pain002ReasonCode` (e.g. `MS03`, `AC04`, `AM04`), `camt054ReferenceId`, and `repostedAsCollectionId` (nullable; set when a rejected collection has been retried as a new collection).

A new `DirectDebitBatch` schema aggregates collections into a single pain.008 submission. Fields are `id`, `messageId` (max 35 chars, globally unique per creditor per file), `creationDateTime`, `requestedCollectionDate`, `scheme`, `sequenceType` (one batch per sequence-type; FRST and RCUR cannot mix in a single payment-information block, though most modern banks accept them in different blocks of the same message), `collectionCount`, `controlSum`, `status` (`draft` / `generated` / `submitted` / `accepted_by_bank` / `partially_rejected` / `fully_rejected`), `pain008Xml` (the generated payload, archived for the 7-year bewaarplicht), `pain002Xml` (the status report when received), and `submittedAt`.

A new `RTransaction` schema captures the rejection / return / refund events that the rulebook collectively calls R-transactions: `id`, `collectionId`, `type` (`reject` / `return` / `refund` / `reversal` / `revocation` / `request_for_cancellation`), `reasonCode` (ISO 20022 ExternalReturnReason code), `reasonText`, `originatorBic`, `transactionAmount`, `valueDate`, and `notifiedAt`.

A new `PreNotification` schema records the legally required notice to the debtor before each collection: `id`, `collectionId`, `sentAt`, `channel` (`email` / `letter` / `invoice_line`), `noticeDays` (the number of calendar days between notification and collection date), and `recipientAddress`.

The existing `Counterparty` schema gains `defaultMandateId` (nullable) so that invoices to that counterparty default to the active mandate.

The existing `Invoice` schema gains `paymentMethod` (`bank_transfer` / `direct_debit` / `cash` / etc.) and `directDebitMandateId` (nullable; required when `paymentMethod = direct_debit`).

## Requirements

### REQ-SDD-001 Mandate registration with scheme rules enforced

The system MUST allow a bookkeeper to register a SEPA mandate and MUST enforce the structural rules of the SDD CORE or SDD B2B rulebook at registration time.

- GIVEN a bookkeeper registering a CORE mandate for a consumer debtor, WHEN they submit `scheme: CORE`, `type: recurring`, `debtorIban: NL91ABNA0417164300`, `signedAt: 2026-05-10`, `signedBy: J. de Vries`, THEN the system MUST persist the mandate with `status: active`, generate a `mandateReference` if not supplied, and accept the mandate.
- GIVEN a bookkeeper submitting a B2B mandate with `debtorAccountType: consumer`, WHEN they attempt to save, THEN the save MUST fail with `sdd.mandate.scheme.mismatch` because SDD B2B is reserved for business debtors.
- GIVEN a bookkeeper submitting a mandate with `mandateReference` that already exists for the same creditor identifier, WHEN they attempt to save, THEN the save MUST fail with `sdd.mandate.reference.duplicate`.

### REQ-SDD-002 Sequence-type derivation per collection

The system MUST automatically determine the correct `sequenceType` for every collection based on the mandate's history.

- GIVEN a recurring mandate that has never been used, WHEN a collection is scheduled, THEN its `sequenceType` MUST be `FRST`.
- GIVEN a recurring mandate whose previous collection has status `succeeded` (or any status other than `rejected`/`refunded`), WHEN the next collection is scheduled, THEN its `sequenceType` MUST be `RCUR`.
- GIVEN a one-off mandate (`type: oneoff`), WHEN any collection is scheduled, THEN its `sequenceType` MUST be `OOFF` and the system MUST refuse to schedule a second collection against the same mandate.

### REQ-SDD-003 Pre-notification with default 14 days lead time

For every scheduled collection the system MUST generate a `PreNotification` and MUST not allow the collection to be included in a generated pain.008 unless the pre-notification has been sent or marked as included on the invoice.

- GIVEN a collection scheduled for `requestedCollectionDate: 2026-07-15` with no contractual deviation, WHEN the collection is scheduled, THEN a `PreNotification` with `noticeDays: 14` MUST be generated and the earliest valid send date MUST be 2026-07-01.
- GIVEN an invoice issued on 2026-06-25 with `paymentMethod: direct_debit` and a line "Wij incasseren EUR 89 op of rond 2026-07-15 onder mandaatkenmerk MAND-2026-0042", WHEN the bookkeeper marks the invoice as the pre-notification carrier, THEN the system MUST link the invoice as the pre-notification (no separate email needed) and unblock inclusion in pain.008.
- GIVEN a collection where pre-notification was sent only 6 days before `requestedCollectionDate` and the mandate's contractual minimum is 14 days, WHEN the bookkeeper attempts to generate a batch including it, THEN the system MUST block with `sdd.prenotification.too_short` and offer to push the collection date forward.

### REQ-SDD-004 Submission-window enforcement

The system MUST enforce the SDD CORE submission windows (D-5 business days for FRST/OOFF, D-2 business days for RCUR/FNAL) and the SDD B2B window (D-1 business day for all sequence types) when generating a batch.

- GIVEN a FRST collection in a CORE batch with `requestedCollectionDate: 2026-07-15` (a Wednesday), WHEN the bookkeeper attempts batch generation on 2026-07-09 (a Thursday, which is 3 business days before due), THEN the system MUST block with `sdd.submission.window.late` and indicate the latest valid generation date was 2026-07-08.
- GIVEN a RCUR collection in a CORE batch with `requestedCollectionDate: 2026-07-15`, WHEN the bookkeeper generates a batch on 2026-07-11 (Friday), THEN the system MUST accept the batch (2 business days lead time satisfied).
- GIVEN a B2B collection with `requestedCollectionDate: 2026-07-15`, WHEN the bookkeeper generates a batch on 2026-07-14 (1 business day lead time), THEN the system MUST accept.

### REQ-SDD-005 pain.008.001.02 generation and validation

The system MUST generate ISO 20022 `pain.008.001.02` XML conforming to the EPC SEPA Direct Debit Core Implementation Guidelines and the Dutch overlay (Betaalvereniging Nederland addendum), and MUST validate the generated payload against the EBA / Equens / SurePay XSDs and the Dutch overlay rules before marking the batch as `generated`.

- GIVEN a batch with 3 active collections totaling EUR 247.50, WHEN the batch is generated, THEN the resulting pain.008 MUST validate against `pain.008.001.02.xsd`, MUST include exactly one `PmtInf` block per `(scheme, sequenceType, requestedCollectionDate)` combination, MUST carry the creditor identifier in `CdtrSchmeId/Id/PrvtId/Othr/Id` with `SchmeNm/Prtry: SEPA`, and MUST carry `CtrlSum: 247.50` with `NbOfTxs: 3`.
- GIVEN any collection in the batch references a mandate where `signedAt` falls AFTER `requestedCollectionDate`, WHEN generation is attempted, THEN it MUST fail with `sdd.mandate.signed.after.collection`.

### REQ-SDD-006 pain.002 ingestion and status update

The system MUST ingest pain.002 status reports from the creditor's bank (delivered manually as file upload, or automatically via OpenConnector bank-connector polling) and MUST update each referenced `DirectDebitCollection` and `DirectDebitBatch` accordingly.

- GIVEN a submitted batch with 10 collections and an incoming pain.002 with `GrpSts: ACCP` and no `TxInfAndSts` entries, WHEN the pain.002 is ingested, THEN the batch MUST move to `accepted_by_bank` and all 10 collections MUST move to `accepted_by_bank`.
- GIVEN a submitted batch and an incoming pain.002 with `GrpSts: PART` containing one `TxInfAndSts` with `TxSts: RJCT` and `StsRsnInf/Rsn/Cd: AC04` (closed account), WHEN ingested, THEN the batch MUST move to `partially_rejected`, the single referenced collection MUST move to `rejected` with `pain002ReasonCode: AC04`, and the remaining 9 collections MUST move to `accepted_by_bank`.

### REQ-SDD-007 camt.054 reconciliation and R-transaction handling

The system MUST reconcile camt.054 debit notifications and credit-side R-transaction notifications against the originating collections and MUST capture R-transactions in the `RTransaction` schema.

- GIVEN a successful camt.054 entry referencing `endToEndId: SDD-2026-00451`, WHEN ingested, THEN the matching collection MUST move to `succeeded`, the corresponding invoice MUST be marked paid, and a journal entry crediting the receivable and debiting the bank account MUST be posted.
- GIVEN an incoming Return notification with `RtrInf/Rsn/Cd: MS03` (no reason given by debtor) on a collection that was already `succeeded`, WHEN ingested, THEN an `RTransaction` of type `return` MUST be created, the originating collection MUST move to `refunded`, the receivable MUST be re-opened on the invoice, and the bank journal entry MUST be reversed.
- GIVEN an incoming Refund notification with reason code `MD06` (consumer refund request under the 8-week unconditional right) for a CORE collection, WHEN ingested, THEN the system MUST treat it identically to a return AND MUST flag the mandate for bookkeeper review (because a high refund rate can lead to bank-imposed scheme exclusion).

### REQ-SDD-008 Mandate cancellation and dormancy expiry

The system MUST allow a bookkeeper to cancel a mandate, MUST refuse to schedule any further collections against a cancelled mandate, and MUST automatically mark mandates as `expired` after 36 months of inactivity per the rulebook.

- GIVEN an active recurring mandate, WHEN the bookkeeper cancels it with reason "Klant heeft opgezegd 2026-05-21", THEN the mandate MUST move to `status: cancelled`, `cancellationReason` MUST be persisted, and any future-dated `scheduled` collection against it MUST be cancelled.
- GIVEN an active recurring mandate whose `lastUsedAt` was 2023-05-22, WHEN the daily expiry job runs on 2026-05-22, THEN the mandate MUST move to `status: expired` and the bookkeeper MUST be notified that a fresh mandate is required before further collection.
- GIVEN a cancelled mandate, WHEN the bookkeeper tries to schedule a new collection, THEN the system MUST refuse with `sdd.mandate.cancelled`.

### REQ-SDD-009 Reposting of rejected collections

When a collection moves to `rejected` or `refunded` because of a bank-side problem rather than a debtor refusal, the system MUST offer one-click reposting that creates a new `DirectDebitCollection` linked back via `repostedAsCollectionId` and preserving the original invoice link.

- GIVEN a rejected collection with `pain002ReasonCode: AM04` (insufficient funds) for invoice INV-2026-1018, WHEN the bookkeeper clicks "Opnieuw incasseren", THEN a new collection MUST be created against the same mandate for the same amount, scheduled for the next valid collection date respecting REQ-SDD-004, with `sequenceType: RCUR` (because the mandate is now active), and the originating collection's `repostedAsCollectionId` MUST point to the new collection.
- GIVEN a rejected collection with `pain002ReasonCode: MD01` (no mandate / mandate cancelled by debtor), WHEN the bookkeeper clicks "Opnieuw incasseren", THEN the system MUST refuse and explain that reposting against a refused mandate is forbidden by the rulebook; the receivable must be pursued through other means.

### REQ-SDD-010 Audit-trail and bewaarplicht

The system MUST retain every generated pain.008, every ingested pain.002 and camt.054, every PreNotification, and every signed mandate document for at least 7 years (Dutch fiscal bewaarplicht) and MUST be able to produce, within 10 seconds, a per-mandate or per-invoice audit bundle containing the mandate document, every collection against it, every R-transaction, and the underlying journal lines.

- GIVEN a mandate with 24 successful and 1 refunded collection over 2 years, WHEN the bookkeeper clicks "Exporteer dossier" on the mandate, THEN a ZIP MUST be produced containing the signed mandate PDF, a CSV of all 25 collections, the pain.008 fragments that initiated each, the pain.002 and camt.054 entries that closed each, and the journal entries.
- GIVEN any pain.008 older than 7 years, WHEN the housekeeping job runs, THEN the system MUST NOT auto-delete it; deletion MUST be an explicit bookkeeper action with a retention-override warning.

## Standards & Sources

- European Payments Council SEPA Direct Debit Core Rulebook (current version 2025, in force from November 2025).
- European Payments Council SEPA Direct Debit Business-to-Business Rulebook (current version 2025).
- EPC SEPA Direct Debit Core / B2B Customer-to-Bank Implementation Guidelines (ISO 20022 `pain.008.001.02`).
- EPC Bank-to-Customer Status Report Implementation Guidelines (`pain.002.001.03`).
- EPC Bank-to-Customer Debit/Credit Notification Implementation Guidelines (`camt.054.001.02`).
- Betaalvereniging Nederland addendum (Dutch overlay) for SDD: betaalvereniging.nl/giraal-en-online-betalen/sepa.
- De Nederlandsche Bank creditor identifier specification (NL{check}ZZZ{KvK} format).
- Payment Services Directive PSD2 (Directive (EU) 2015/2366), Article 76 (right to refund of authorised payment transactions initiated by or through a payee), implemented in Dutch law as Wet financieel toezicht art. 7:534-7:539.
- ISO 20022 external code lists for ExternalReturnReason and ExternalStatusReason (iso20022.org/catalogue-messages).

## Cross-app integration

- `bookkeeping-accounts-receivable-core` provides the Invoice and Counterparty schemas this brief extends, and exposes the receivable that a successful collection settles (REQ-SDD-007).
- `bookkeeping-bank-connectors` is the channel through which pain.008 leaves shillinq and through which pain.002 and camt.054 return; this brief assumes the connector capability is in place and reuses its credentials/certificate handling without redefining them.
- `bookkeeping-chart-of-accounts` provides the bank and receivable accounts that the journal entries in REQ-SDD-007 hit.
- `bookkeeping-vat-btw-filing` is not affected directly (the underlying invoice already carries its VAT regardless of payment method), but successful collections close the receivable that contributes to rubriek 1a/1b totals; the integration is purely through the invoice status.
- OpenConnector hosts the pain.008 submission and pain.002/camt.054 ingestion connectors and provides the schedule for the dormancy-expiry job (REQ-SDD-008) and the pre-notification dispatcher (REQ-SDD-003).
- OpenRegister holds the SepaMandate, DirectDebitCollection, DirectDebitBatch, RTransaction, and PreNotification schemas; the mandate document is stored as a file attached to the SepaMandate object using the standard OpenRegister files-attached-to-object pattern.
- `nldesign` provides the high-visibility warning banner for high-refund-rate mandate flagging (REQ-SDD-007) and the confirmation pattern for the destructive cancel-mandate action.

## Target users

The primary user is the bookkeeper of a Dutch SME with a recurring-revenue model: associations (sports clubs, music schools, neighbourhood associations), small SaaS vendors, fitness studios, after-school care, tuition providers, utility resellers, property managers collecting rent, and tax-advice firms billing monthly retainers. A close secondary user is the SME owner who wants visibility into collection success rates and refund risk without having to log into the bank portal. A third group is the external accountant who runs incasso batches on behalf of multiple client tenants and needs the audit-trail export of REQ-SDD-010 for fiscal review and for AVG/GDPR data-subject access requests. The Belastingdienst inspector reads the same audit trail to verify that VAT was correctly accrued at the point of supply, regardless of whether collection later succeeded, was rejected, or was refunded.
