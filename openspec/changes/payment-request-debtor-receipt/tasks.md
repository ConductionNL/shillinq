# Tasks: payment-request-debtor-receipt

## 1. Receipt

- [x] 1.1 `PaymentReceiptMailer` mails the debtor of a captured request what was paid, the amount, the date and the reference; nothing without a valid `debtor.email` or a captured state; a mail failure is logged, not thrown (REQ-SOPR-012). Verify: PHPUnit red first.
- [x] 1.2 `PaymentReconciliationService::reconcile()` sends it after it books and saves a captured object request; not on `captured_unapplied`, not on a replayed capture (REQ-SOPR-012). Verify: PHPUnit from the caller, with the mailer as a double of the real class.
- [x] 1.3 Dutch and English strings. Verify: `npm run test:l10n`.
- [ ] 1.4 Live: capture a request with a debtor email on the instance and read the mail in Mailhog. (Needs the live instance.)
