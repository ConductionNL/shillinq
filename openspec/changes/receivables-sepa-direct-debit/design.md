# Design: receivables-sepa-direct-debit

Read at shillinq `feat/ledger-booking-rules` @6ee1421d9 on 2026-09-29.

## Context

- `InvoiceSettlementService` (`lib/Service/Bank/`, change banking-manual-match) registers a payment on an `ARInvoice` and is the one path that marks it paid.
- The creditor identifier is a per-administration value; `SepaMandate.creditorIdentifier` copies it at signing.

## Decisions

### D1. Proposal

`lib/Service/Receivables/DirectDebitBatchService.php::propose(string $administrationId, string $collectionDate): array` reads issued, unpaid `ARInvoice` with `paymentMethod: direct-debit`, resolves the mandate (`directDebitMandateId` or the customer's `defaultMandateId`), skips an invoice without an active mandate (and says why), and writes one `DirectDebitCollection` per invoice plus one `DirectDebitBatch` per sequence type. The pre-notification and submission-window guards run on the collections' `submit` transition as today.

### D2. File

`lib/Receivables/SepaPain008Generator.php::render(array $batch, array $collections, array $creditor): RenderedFile`, built like `SepaPain001Generator`. The `generate` transition of `DirectDebitBatch` calls it through a declared action; the batch keeps `messageId`, `controlSum` and `collectionCount`. The XSD `pain.008.001.02.xsd` ships under `lib/Receivables/xsd/`; a file that fails the XSD is not stored and the batch stays draft with the errors.

### D3. Download

`GET /api/direct-debit-batches/{id}/file` returns the stored XML as an attachment, `#[NoAdminRequired]`, administration checked.

### D4. Outcome

The collection's `succeed` transition runs an action that calls `InvoiceSettlementService` for its invoice and amount; `reject` records `pain002ReasonCode` and leaves the invoice open. Reading pain.002 and camt.054 files automatically stays in REQ-SDD-006 and 007 and is not part of this change; the transitions are set by hand or by the bank statement match.

