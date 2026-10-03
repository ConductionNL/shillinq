# Tasks: sales-invoice-sending

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Customer defaults

- [ ] 1.1 Add `paymentTermDays`, `deliveryMethod` and `invoiceEmail` to `CustomerMaster` in a new fragment `lib/Settings/register.d/sales-invoice-sending.json`, and put them on `CustomerDetail` (REQ-SIS-005). Verify: `npm run check:registers` and a PHPUnit case that the merged schema declares the three fields with their defaults.
- [ ] 1.2 Replace the `Customers` index columns with declared keys (`customerId`, `legalName`, `paymentTermDays`, `deliveryMethod`, `creditLimit`, `lifecycleState`) (REQ-SIS-006). Verify: `npm run check:manifest` and a manifest test that every index column of `Customers` exists on `CustomerMaster`.
- [ ] 1.3 Read `paymentTermDays` in `InvoiceQuickDraftModal.vue`, `InvoiceGenerationService::draftInvoice()` and the recurring profile modal default (REQ-SIS-005). Verify: Vitest for `dueDateFromTerms` with a 14-day customer; PHPUnit for a 30-day customer through `draftInvoice()`.

## 2. Send one invoice

- [ ] 2.1 Add the `sending` group to `ARInvoice` in the same fragment and `lib/Service/InvoiceSendingService.php` (PDF from `InvoicePdfGenerator`, mail through `IMailer`, outcome written after `send()` returns) (REQ-SIS-001). Verify: PHPUnit with a mailer double for success, refusal and a draft invoice.
- [ ] 2.2 Add the delivery-method router, including the Peppol fallback to email (REQ-SIS-002). Verify: PHPUnit per delivery method, with `EInvoiceService` answering `fallback: true` for one case.
- [ ] 2.3 Add `POST /api/ar-invoices/{id}/send` on a new `InvoiceSendingController` (administration check first, ADR-005) and the Send by email action in `AREInvoiceActions.vue`, replacing the "use PDF + email instead" notice with a button (REQ-SIS-001, REQ-SIS-002). Verify: controller PHPUnit including a cross-administration 404; Playwright on `ARInvoiceDetail`.

## 3. Batches

- [ ] 3.1 Add `InvoiceBatch` with its `x-openregister-notifications` rule and `lib/BackgroundJob/SendInvoicesJob.php` (QueuedJob) (REQ-SIS-003). Verify: `npm run check:job-registration` and PHPUnit for skip-already-sent, resend and a failed mail inside a batch.
- [ ] 3.2 Add `POST /api/ar-invoices/send-batch`, `selectable` and a Send selected bulk action on `AccountsReceivable` (REQ-SIS-003). Verify: Playwright selecting three invoices and reading the batch outcome.
- [ ] 3.3 Add `InvoiceBatchService` and `POST /api/ar-invoices/batch` (at most 200 customers, idempotent on batch id and customer, optional issue and send), and a batch page reachable from `AccountsReceivable` (REQ-SIS-004). Verify: PHPUnit for a retried half-written batch; Playwright for the three-customer example.

## 4. Recurring

- [ ] 4.1 Call `InvoiceSendingService` from `RecurringInvoiceGenerator::generateForProfile()` for an auto-issued invoice on a profile with `deliveryChannel = email` (REQ-SIS-007). Verify: PHPUnit for auto-issue with email, auto-issue with none, and draft-for-review.

## 5. Docs

- [ ] 5.1 User guide page for sending, batches and customer terms under `docs/`, and the release note. Verify: the page is linked from the `AccountsReceivable` `documentationUrl` and the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-invoice-sending/tasks.md#task-N` on every new method, English source strings with Dutch translations for every new message, mail subject and body.
