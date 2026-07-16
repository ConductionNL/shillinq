# Change: orphan-auth-remediation

## Why

Hydra gate 6 (`orphan-auth`, OWASP A01:2021 — a defined-but-uncalled authorization/
validation method is identical to having no check at all) was recently un-blinded: its
file enumeration changed from a non-recursive glob to a recursive one, so it now sees
methods in nested `lib/Service/**` directories it previously skipped. On clean
`origin/development` the recursive gate reports **5** orphan auth-verb methods that the
blinded gate never surfaced:

| # | File | Method |
|---|------|--------|
| 1 | `lib/Service/External/Mollie/MolliePaymentAdapterInterface.php:116` | `verifyWebhook` |
| 2 | `lib/Service/External/Mollie/LogMolliePaymentAdapter.php:103` | `verifyWebhook` |
| 3 | `lib/Service/External/CsrdEsrsXbrl/CsrdEsrsXbrlAdapterInterface.php:123` | `validateMandatoryDataPoints` |
| 4 | `lib/Service/External/CsrdEsrsXbrl/LogCsrdEsrsXbrlAdapter.php:115` | `validateMandatoryDataPoints` |
| 5 | `lib/Service/Sms/SmsSendResult.php:84` | `isDelivered` |

shillinq already fixed the acute members of this defect class this cycle (segregation
control hardcoded to pass — #444; four-eyes payment-run guard — #459; 15 missing lifecycle
guards). These 5 are the residue the newly-recursive gate additionally sees. Each had to be
triaged against the live financial paths (payment reconciliation, journal posting, approval)
because a **dead guard on a live money mutation** is the worst outcome of this class.

**Triage result — NO live financial mutation is unprotected.** The one payment-adjacent
finding (Mollie `verifyWebhook`) is a *superseded, never-wired* method: the live inbound
payment-confirmation path (`PaymentRequestWebhookController` + `DepositWebhookController`,
both `#[PublicPage]`) performs its own fail-closed HMAC `verifySignature()` over the raw body
and rejects a forged/unsigned/secret-unconfigured request *before any reconciliation runs* —
this is REQ-APL-004's explicit "ONE shared surface, never a fork" rule. The adapter's
`verifyWebhook()` is the forked duplicate that rule prohibits, is injected nowhere, and is a
dangerous always-`PAYMENT_DEFERRED` stub. The remaining two clusters are a dormant XBRL
submission seam (no live submit path exists — the cross-app `bookkeeping-sbr-xbrl-reporting`
dependency is unmerged) and a non-auth DTO status accessor mis-matched by the gate's `is*`
verb pattern.

## What Changes

- **DELETED** `MolliePaymentAdapterInterface::verifyWebhook()` and its dormant implementation
  `LogMolliePaymentAdapter::verifyWebhook()` (superseded by the webhook controllers' fail-closed
  `verifySignature()` gate — REQ-APL-004). The port is narrowed to outbound
  `createPayment()` + `isDormant()`. The admin-adapter catalogue description is corrected to
  state inbound verification is owned by the controllers, never the adapter.
- **ADDED** `REQ-OAR-001` — the Mollie payment port SHALL NOT declare an inbound
  `verifyWebhook()`; inbound Mollie/Stripe webhook HMAC verification SHALL be performed solely
  by the fail-closed controller gate, and a forged/unsigned request SHALL be rejected before
  any payment-reconciliation side effect.
- **ADDED** `REQ-OAR-002` — documents the two retained dormant/DTO seams
  (`CsrdEsrsXbrlAdapterInterface::validateMandatoryDataPoints`, `SmsSendResult::isDelivered`)
  as intentional and non-regressive: neither gates a live financial mutation.
- New regression test `tests/Unit/Service/External/Mollie/LogMolliePaymentAdapterTest.php`
  pins the narrowed dormant contract and asserts `verifyWebhook()` is not re-introduced on the
  port. The existing `PaymentRequestWebhookControllerTest::testInvalidSignatureReturns400AndNoDispatch`
  and `testMissingSecretFailsClosed` (both `gateway: 'mollie'`, both assert
  `reconcile()` is `never()` called) are the superseder proof for the deletion.

## Impact

- Affected spec: `orphan-auth-remediation` (new capability — ADDED REQ-OAR-001, REQ-OAR-002).
- Affected code: `lib/Service/External/Mollie/MolliePaymentAdapterInterface.php`,
  `lib/Service/External/Mollie/LogMolliePaymentAdapter.php`,
  `lib/Controller/ExternalAdaptersAdminController.php` (description text only).
- Security-relevant: removes a superseded, always-defer webhook stub; the live money path is
  unchanged and already proven fail-closed. No routes, no schemas, no DI wiring change.
- Tracking: shillinq#482 (gate-6 un-blinding umbrella).
