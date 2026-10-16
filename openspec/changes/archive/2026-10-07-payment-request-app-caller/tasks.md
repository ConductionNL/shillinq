# Tasks: payment-request-app-caller

## 1. Leaf

- [x] 1.1 `PaymentActionAppGrant` reads `paymentActionApps` and fails closed; `PaymentRequestLeafProvider::createAsApp()` raises a request for a granted, enabled app with no user, as the system, with `requestedBy` `app:<appId>` (REQ-SOPR-010). Verify: PHPUnit red first; an app with the grant creates a request; an app without it, an app granted another action, a granted app that is not enabled and a malformed id are refused and nothing is written; a payload naming an app does not open `create`.
- [x] 1.2 Tell larpinq the contract (shillinq#1836, the PR body carries it).
