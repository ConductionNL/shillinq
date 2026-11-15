# Tasks

- [x] 1.1 `CustomerMaster.externalReference` (register fragment `billable-period-becomes-an-invoice.json`, schema version moved, info.xml moved, version lock recorded).
- [x] 1.2 `BillablePeriodClosedEvent` with accept/refuse in place (`lib/Event/BillablePeriodClosedEvent.php`, `tests/Unit/Event/BillablePeriodClosedEventTest.php`).
- [x] 1.3 `BillablePeriodInvoiceService` resolves the customer by reference, refuses on none or many, writes meter readings on flat plans, drafts through `InvoiceGenerationService::draftInvoice()` (usage model) and records the batch for idempotency (`lib/Service/BillablePeriodInvoiceService.php`, `tests/Unit/Service/BillablePeriodInvoiceServiceTest.php`).
- [x] 1.4 `BillablePeriodClosedListener` registered (`lib/Listener/BillablePeriodClosedListener.php`, `lib/AppInfo/ObjectRequestSettlementRegistration.php`, `tests/Unit/Listener/BillablePeriodClosedListenerTest.php`).
- [ ] 1.5 Live pass (decision 139): with a customer carrying a dossiq tenant id, run dossiq's monthly invoicing and see one draft invoice with one usage line per billable event; run it again and see the same invoice; remove the reference and see dossiq record the refusal.
