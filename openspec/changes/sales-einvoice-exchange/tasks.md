# Tasks: sales-einvoice-exchange

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12. -->

## 1. Outbound

- [ ] 1.1 Store the hybrid PDF and UBL XML in the administration's Files folder in `EInvoiceService::storeArtefact()` and return a reference that resolves (REQ-SEIX-002). Verify: PHPUnit with a Files double asserting both files are written and the reference names the XML.
- [ ] 1.2 Replace the Nextcloud event dispatch with a save of the outbound CloudEvent object in integriq's `event` schema, drop the local port call from the invoice path, and answer "not available" when integriq is absent (REQ-SEIX-001). Verify: PHPUnit for integriq present, integriq absent and a failed save, each asserting `deliveryStatus`.
- [ ] 1.3 Live check on an instance with integriq: send one invoice and confirm integriq writes a `peppol_transmission` for it. Record the result in the PR body; if integriq's consumer does not match because of the id comparison, open the integriq issue and link it (REQ-SEIX-001). Verify: the transmission uuid or the issue link in the PR body.

- [ ] 1.4 Add `occ shillinq:einvoice:requeue`, which lists invoices left at `queued` by the old path and, when the operator confirms, sends them again through the new one (REQ-SEIX-001). Verify: PHPUnit for the listing and the confirmed requeue.

## 2. Status and rejection

- [ ] 2.1 Add `lib/Listener/IntegriqCloudEventListener.php` on `ObjectCreatedEvent` with slug resolution for integriq's register, move the transition logic of `PeppolDeliveryStatusListener` into a service it calls, and remove the named-event registration (REQ-SEIX-003). Verify: PHPUnit that feeds a real `ObjectCreatedEvent` with an id-stamped entity and asserts the invoice moves from queued to sent.
- [ ] 2.2 Add `lib/Notification/EInvoiceNotifier.php` for `einvoice_delivery_rejected` and register it (REQ-SEIX-004). Verify: PHPUnit that `prepare()` renders the subject with the reason and link, and throws for another subject.
- [ ] 2.3 Add the Rejected e-invoices quick filter on `AccountsReceivable` (REQ-SEIX-004). Verify: `npm run check:manifest` and Playwright with a seeded rejected invoice.

## 3. Self-billing

- [ ] 3.1 Add `selfBilled`, `selfBillingMention`, the `selfBilling` group, `CustomerMaster.selfBillingAgreement` and `SelfBilledInvoiceRefusal` in `lib/Settings/register.d/sales-einvoice-exchange.json`, and the agreement on `CustomerDetail` (REQ-SEIX-005). Verify: `npm run check:registers`; the `vatdir-art226-10a` check passes on a seeded self-billed invoice.
- [ ] 3.2 Add `SelfBilledInvoiceIntake` (payload read, self-billing detection, party matching, agreement check, draft write, refusal record) reusing `SupplierInvoiceService::parseUblInvoice()` (REQ-SEIX-006). Verify: PHPUnit with a Peppol self-billing UBL fixture, a fixture without agreement and a repeated delivery.
- [ ] 3.3 Route a non-self-billing inbound invoice to `SupplierInvoiceService::ingestUBLInvoice()` from the same listener (REQ-SEIX-006). Verify: PHPUnit with an ordinary Peppol BIS invoice fixture asserting a `SupplierInvoice` is written.
- [ ] 3.4 Add the guard `requireSelfBillingAgreement` on `ARInvoice.issue` for `selfBilled` invoices and a reject action with reason on `ARInvoiceDetail` (REQ-SEIX-007). Verify: PHPUnit for a current and an expired agreement; Playwright issuing the seeded draft.

## 4. Docs

- [ ] 4.1 User guide section on Peppol status, rejections and self-billing, and a release note that lists the `occ shillinq:einvoice:requeue` step for invoices stuck at queued. Verify: the page is in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-einvoice-exchange/tasks.md#task-N` on every new method, English source strings with Dutch translations, and coordinate the own-sequence exclusion with `sales-invoice-issue-controls`.
