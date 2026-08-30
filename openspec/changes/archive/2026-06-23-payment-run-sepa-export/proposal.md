---
kind: code
depends_on: [consolidate-accounts-payable]
---

## Why

The `consolidate-accounts-payable` change establishes the `PaymentRun` schema
(a batch of approved `Payee` payments) with a declarative lifecycle
`draft → approved → exported → reconciled`, but stops at the data model — it
deliberately defers the actual bank-file *generation*. An approved
`PaymentRun` currently has nowhere to go: an operator cannot turn it into a
file the bank will accept. This change implements the final steps — exporting
an approved `PaymentRun` to a SEPA Credit Transfer bank file, AND reconciling
the exported run against an imported bank statement (CAMT.053) to close the
loop `draft → approved → exported → reconciled` — so the AP payment cycle is
end-to-end usable.

## What Changes

- **Generate a SEPA Credit Transfer file** from an approved `PaymentRun`:
  **pain.001.001.03** XML (ISO 20022 customer credit transfer initiation),
  covering every entry in the run's `paymentLines[]`. One file per
  `PaymentRun`.
- **Provide a CSV fallback** alongside the XML (same payment lines, flat rows)
  for banks / operators that prefer it.
- **Reuse the document-generation pattern of `ReportGenerationService`**
  (`lib/Reporting/`): a thin generator renders bytes via the native XML writer
  / `fputcsv` (no new composer dependency — the PHPOffice stack is already
  bundled via OR), and the orchestrating service writes the tagged file into
  Nextcloud Files under `/Shillinq/PaymentRuns/<administrationId>/` and records
  metadata.
- **Wire the file back into the `PaymentRun`**: set `exportedFileRef` +
  `exportedAt`, and drive the declarative `approved → exported` lifecycle
  transition.
- **Add an "Export to bank" trigger** on the PaymentRun detail page plus a
  controller endpoint (ADR-016 route) to invoke the export. (UI-vs-OR-action
  choice recorded as a deferred question.)
- **Reconcile an exported `PaymentRun` against an imported bank statement.**
  Import a **CAMT.053** (ISO 20022 bank-to-customer account statement) file,
  match its booked credit-transfer entries back to the run's `paymentLines[]`
  (by `EndToEndId` primary; amount + creditor IBAN fallback), and on a full
  match drive the declarative `exported → reconciled` lifecycle transition
  (set `reconciledAt`). Partial/unmatched lines leave the run `exported` with a
  mismatch note. Triggered by a "Reconcile / import statement" action on the
  PaymentRun detail page plus a controller endpoint (ADR-016).

## Capabilities

### New Capabilities
- `payment-run-sepa-export`: defines the export of an approved `PaymentRun` to
  a SEPA Credit Transfer bank file — the pain.001.001.03 XML structure, the
  CSV fallback, the document-generation/storage pattern (reusing
  `ReportGenerationService`), the `exportedFileRef`/`exportedAt` write-back,
  and the `approved → exported` lifecycle transition trigger — **plus the
  reconciliation of an exported run against an imported CAMT.053 bank
  statement**: CAMT.053 parsing, the EndToEndId/amount+IBAN matching strategy,
  the `reconciledAt` write-back, and the `exported → reconciled` lifecycle
  transition (with partial/unmatched lines staying `exported` with a mismatch
  note).

### Modified Capabilities
- (none — `PaymentRun`'s schema + lifecycle are introduced by
  `consolidate-accounts-payable`; this change only adds the export behaviour
  that consumes them, not a new requirement on the schema shape.)

## Impact

- **New PHP (document generation + file ingestion — ADR-031 justified
  exceptions):** a `PaymentRunExportService` + a SEPA pain.001 generator + a
  CSV generator under `lib/PaymentRun/` (mirroring `lib/Reporting/` generator
  discovery), plus a `Camt053StatementParser` + a
  `PaymentRunReconciliationService` (CAMT.053 parse + match) under the same
  `lib/PaymentRun/`. No new composer dependency.
- **New routes + controllers:** an "export" endpoint AND a "reconcile" endpoint
  registered in `appinfo/routes.php` (ADR-016), guarded per ADR-005.
- **Frontend:** an "Export to bank" action and a "Reconcile / import
  statement" action on the `PaymentRun` detail page (ADR-004).
- **Files:** generated export artefacts stored under
  `/Shillinq/PaymentRuns/<administrationId>/`, tagged like reports.
- **Depends on `consolidate-accounts-payable`** for the `PaymentRun` schema +
  lifecycle + the `Payee.iban`/`bic` fields read into the creditor agent.
