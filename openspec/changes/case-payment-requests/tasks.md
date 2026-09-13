# Tasks: case-payment-requests

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Schema

- [ ] 1.1 Extend `PaymentRequest` in `lib/Settings/register.d/ar-invoice-payment-links.json` with the D1 properties, the conditional requireds and the `(subject, requestType)` uniqueness; guard the invoice calculation on `subjectKind` (REQ-SOPR-001)
- [ ] 1.2 Add the `paymentRevenueAccounts` setting and its admin field (REQ-SOPR-002)

## 2. Booking

- [ ] 2.1 Add the object branch to `PaymentReconciliationService::reconcile()`: receipt posting, `captured_unapplied` on a missing mapping (REQ-SOPR-002)

## 3. Leaves

- [ ] 3.1 Add `lib/Integration/PaymentRequestLeafProvider.php` with `list` and `create`, registered on `RegisterLeafProvidersEvent`; seed the `payment.request` action in `lib/actions.seed.json` (REQ-SOPR-003)
- [ ] 3.2 Register `shillinq-payment-requests-panel` on both halves with the two actions and their endpoints (REQ-SOPR-004)

## 4. Portal

- [ ] 4.1 Include object requests in the `paymentRequests` collection and read the amount from the request in the initiation endpoint (REQ-SOPR-005)

## 5. Quality

- [ ] 5.1 PHPUnit: schema guard, uniqueness, reconciliation branch, provider refusals, initiation amount; run inside the container
- [ ] 5.2 Playwright `tests/e2e/payment-request-leaf.spec.ts` and `tests/e2e/payment-request-panel.spec.ts`
- [ ] 5.3 Dutch and English strings; docs with screenshots
- [ ] 5.4 Hand the two leaf ids and the `create` payload to dossiq for `financial-integration`
