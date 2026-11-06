# Tasks: receivables-payment-links

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Provider

- [ ] 1.1 Open the integriq issue for a typed payment command event (ADR-041) calling `PaymentIntentService::createPayment()` with a result slot for `paymentIntentId`, `providerPaymentId` and `checkoutUrl`; link it in the PR body (REQ-RPL-001). Verify: the issue link in the PR body.
- [ ] 1.2 Add `lib/Service/External/Integriq/IntegriqPaymentAdapter.php` implementing `MolliePaymentAdapterInterface`, fail closed to dormant with a reason, bind it at `Application.php:497` and remove the misleading comment (REQ-RPL-001, REQ-RPL-002). Verify: PHPUnit with a handled event, an unhandled event, integriq absent and a log-provider source.
- [ ] 1.3 Add `providerPaymentId`, `checkoutExpiresAt` and `linkSentAt` to `PaymentRequest`, store them from every raise path (leges, case leaf, contribution raise, portal pay) (REQ-RPL-001). Verify: `npm run check:registers`; PHPUnit per raise path asserting the stored link.

## 2. Status

- [ ] 2.1 Take `nl.conduction.payment.status` CloudEvents from integriq's event register (slug-resolved) into `PaymentReconciliationService::reconcile()` (REQ-RPL-003). Verify: PHPUnit with a real `ObjectCreatedEvent` carrying an id-stamped entity for captured, failed and a repeat.
- [ ] 2.2 Update `connections.json` so the Mollie row reports integriq's payment source instead of `reportedOnly` (REQ-RPL-002). Verify: the External connections page shows the source state on a seeded instance.

## 3. Pay link

- [ ] 3.1 Add `PayLinkService` (sign, verify, rotate per administration) and accept a forwarded signed token in `portalPaymentInitiation#initiate`, reusing an unexpired checkout or creating one (REQ-RPL-004, REQ-RPL-006). Verify: PHPUnit for a valid, a tampered, a foreign-administration and a paid-invoice token.
- [ ] 3.2 Print the pay link in `InvoicePdfGenerator::renderHtml()` and put it in the invoice mail of `sales-invoice-sending` (REQ-RPL-004). Verify: PHPUnit asserting the link in the rendered HTML and mail body, and no link with a dormant adapter.
- [ ] 3.3 Disable Send payment link with the reason on the Payment requests page and the case panel when the adapter is dormant (REQ-RPL-002). Verify: Vitest for `ShillinqPaymentRequestsPanel.vue`; Playwright on `PaymentRequests`.

## 4. End to end

- [ ] 4.1 Live check with integriq's Mollie test source: portal Pay now, a case leges request and an invoice pay link each reach a checkout and end paid (REQ-RPL-003, REQ-RPL-005). Verify: the three payment ids and end states in the PR body.
- [ ] 4.2 Open the portaliq issue for the guest pay page of a signed link and add a release note naming the integriq source setup. Verify: the issue link and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/receivables-payment-links/tasks.md#task-N` on every new method, English source strings with Dutch translations.
