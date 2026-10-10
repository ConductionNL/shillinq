# Tasks

- [x] 1.1 `CustomerMaster.externalReference` (register fragment `tenant-month-invoice-from-dossiq.json`, schema version moved, info.xml moved, version lock recorded).
- [x] 1.2 `InvoiceIngestRequestedEvent` with accept/refuse in place (`lib/Event/InvoiceIngestRequestedEvent.php`, `tests/Unit/Event/InvoiceIngestRequestedEventTest.php`).
- [x] 1.3 `InvoiceIngestService` resolves the customer by reference, refuses on none or many, writes meter readings on flat plans, drafts through `InvoiceGenerationService::draftInvoice()` (usage model) and records the batch for idempotency (`lib/Service/InvoiceIngestService.php`, `tests/Unit/Service/InvoiceIngestServiceTest.php`).
- [x] 1.4 `InvoiceIngestRequestedListener` registered (`lib/Listener/InvoiceIngestRequestedListener.php`, `lib/AppInfo/ObjectRequestSettlementRegistration.php`, `tests/Unit/Listener/InvoiceIngestRequestedListenerTest.php`).
- [ ] 1.5 Live pass (decision 139): with a customer carrying a dossiq tenant id, run dossiq's monthly invoicing and see one draft invoice with one usage line per billable event; run it again and see the same invoice; remove the reference and see dossiq record the refusal.
