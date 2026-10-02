# Tasks: payment-request-event-fee-type

## 1. Request type

- [x] 1.1 `eventFee` in the `PaymentRequest.requestType` enum and in `ObjectPaymentRequestValidator::REQUEST_TYPES` (REQ-SOPR-011). Verify: PHPUnit red first; an object request of type `eventFee` validates and saves through the leaf with a payload validated against the real fragment; a second pending `eventFee` on the same object is refused; a captured `eventFee` books against its mapped account.
- [x] 1.2 Tell larpinq (for-ruben/larpinq-use-shillinq-app-payment-request.md names `eventFee`).
