# Migration: arinvoice-lines-and-portal-amounts

## Current State

`PaymentRequest` 0.4.0 has no flat customer field; a request without an invoice
names its debtor only in `debtor.customerMasterId`. Quick drafts and generated
recurring invoices were saved without lines (their `lines` was dropped).

## Target State

`PaymentRequest` 0.5.0 declares `customerId` (uuid, `$ref: CustomerMaster`,
nullable), set on every request without an invoice that names a debtor customer.

## Migration Class

```
None. PaymentRequest 0.5.0 is a register fragment change imported by
InitializeSettings. BackfillPaymentRequestCustomer (IRepairStep, post-migration,
after InitializeSettings) stamps existing rows.
```

## Migration Steps

1. InitializeSettings reimports the register (the fragment signature changed).
2. BackfillPaymentRequestCustomer reads every `subjectKind: object` request in
   batches and saves `customerId` on each one without an `invoiceReference` whose
   `debtor.customerMasterId` is set and whose `customerId` is empty.

## Data Impact

Only request-only rows with a debtor customer are written, one field each. No
data loss. Invoices saved without lines before this change stay without lines:
the lines were never stored, so nothing can restore them.

## Rollback Procedure

Revert the PR; the stamped `customerId` values are harmless on 0.4.0 (dropped on
the next save).

## Validation

The repair step logs how many requests it stamped and skipped; a second run
stamps zero. `BackfillPaymentRequestCustomerTest` asserts both.
