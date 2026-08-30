# Tasks: portal-payment-initiation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12. -->

## Prerequisites (apply-time confirmations)

- [x] T01 — Confirm the A6 assertion carries (or the receiver resolves) the subject's `customerMasterId` scope claim server-side; the receiver fails closed `403` without an owner-identifying claim, so this gates go-live, not authoring (design.md Open Q1). RESOLVED at apply: the frozen assertion carries only `sub`/`audience`/`organisation`/`trust`/`jti` (verified against portaliq's canonical contract spec), so `PortalPaymentSessionService::resolveCustomerMasterId()` resolves `claims.shillinq.customerMasterId` itself by reading portaliq's own `portalAccount` register (cross-app, read-only), mirroring `PortalObjectReader::resolveClaim()`. Any absent/malformed claim fails closed `403`.
- [x] T02 — Confirm shared-secret sourcing (verifier reads portaliq's instance signing secret; fail closed `401` when unset) and the portaliq-owned `redirectUrl` return-URL contract (design.md Open Q2/Q3). RESOLVED: `PortalAssertionVerifier` reads `IConfig::getAppValue('portaliq', 'jwt_signing_secret', '')` falling back to the instance secret — byte-identical to the fleet-reference receiver (petstore) and portaliq's own `PortalSessionService` derivation; fails closed `401` when unusable (<16 chars). `redirectUrl` is sourced from shillinq's own app config (`portal_payment_redirect_url`, never the client body) with the instance root as a safe default — portaliq does not forward a return URL in the A6 body per its frozen contract.

## Implementation

- [x] T03 — Add `PaymentProviderInterface` port + shipped Mollie binding (REQ-SPPI-001). Define `lib/Service/Payment/PaymentProviderInterface.php` (`createSession(...)`, `isDormant()`); ship a binding that delegates to the existing `MolliePaymentAdapterInterface` with `method: 'ideal'`, API key/test-mode from app config; register the DI binding in `Application::register()`. Do NOT fork a second Mollie client. EUPL-1.2/SPDX docblock + `@spec` tags.

- [x] T04 — Ship `lib/Portal/PortalAssertionVerifier.php` (REQ-SPPI-002). Verify HS256 vs the portaliq-managed shared secret; accept `alg == HS256` ONLY; require `iss=portaliq`, `use=assertion`, unexpired `exp`, frozen claim set; return derived claims or fail closed. No client input, no `Authorization` header.

- [x] T05 — Ship `lib/Service/Payment/PortalPaymentSessionService.php` (REQ-SPPI-002/003/004). Resolve the owned `ARInvoice` (id + `customerId == customerMasterId` + payable `state`) via OpenRegister; reject URL/path ids (SSRF); mint or reuse a pending `PaymentRequest`; read amount/currency from the SERVER invoice (ignore body amount); drive the provider port; persist `paymentIntentId`; return the checkout URL. Uniform not-authorised (no existence oracle).

- [x] T06 — Ship `lib/Controller/PortalPaymentInitiationController.php` (REQ-SPPI-002/003). One `#[PublicPage]` + `#[NoCSRFRequired]` route; verify assertion first; require `audience==customer`; delegate to the session service; fail closed 401/403/502/503; never echo raw exception text. Register the route in `appinfo/routes.php` (declared before the SPA catch-all).

- [x] T07 — Extend `PaymentReconciliationService` to write a subject-safe `confirmationSummary` on capture (REQ-SPPI-005). Add the `confirmationSummary` scalar to the `PaymentRequest` schema (`ar-invoice-payment-links.json`, register-version bump 0.1.0→0.1.1); keep settlement idempotent; reconcile amount from the stored PR only.

- [x] T08 — Extend `lib/Portal/PortalContributionProvider.php` — `pay` action + rowAction (REQ-SPPI-006). Add exactly one `pay` `endpoint-forward` action (instance-local relative endpoint under `/apps/shillinq/api/portal/payments/`, `method: POST`, `minTrust` tracking the AR surface); reference it as a `rowAction` on the open/payable rows of `salesInvoices`/`paymentRequests`; add `confirmationSummary` to the `paymentRequests` whitelist; keep `supplier`/`accountant` `actions` empty; class stays plain/dependency-free.

## Testing & quality

- [x] T09 — Unit tests `tests/Unit/Portal/PortalContributionProviderTest.php` (extend): pin the `pay` action (id, `type: endpoint-forward`, method, `minTrust`, relative endpoint) and the rowAction reference on open invoices; assert `supplier`/`accountant` `actions` stay empty; assert `confirmationSummary` in the `paymentRequests` whitelist.

- [x] T10 — Unit tests `tests/Unit/Portal/PortalAssertionVerifierTest.php` + `PortalPaymentInitiationControllerTest.php` + `PortalPaymentSessionServiceTest.php` (+ `MolliePaymentProviderTest.php`, T03) with a MOCKED `PaymentProviderInterface`: fail-closed matrix (missing/expired/wrong-`alg`/`iss`/`use`/bad-sig → 401; no secret → 401; wrong audience → 403; non-owned/non-payable/non-existent invoice → identical uniform result, PSP never called; URL/path `invoiceId` rejected before any lookup); client-supplied amount ignored (charged = invoice amount); dormant provider → `503`/deferred, no fabricated URL; happy-path returns checkout URL.

- [x] T11 — Unit tests for the webhook confirmation (REQ-SPPI-005): a captured event writes `confirmationSummary` and settles the invoice to `paid`; a replayed event is an idempotent no-op that never overwrites the confirmation; settlement amount reconciled from the stored PR, never the caller; an unapplied capture writes NO confirmation.

- [x] T12 — Quality gates: `php -l`, PHPCS/PHPMD/Psalm/PHPStan and the full unit suite (3963 tests) pass with zero new violations (run in the `nextcloud:34.0.0-apache` container per `phpunit-unit.xml`, host PHP 8.2 too old for composer.json's `^8.3`); `openspec validate portal-payment-initiation --strict` exits 0. Hydra gate CLI itself was not invoked (no local Hydra runner available in this apply session) — the mechanical checks each gate would run (spdx, forbidden-patterns, route-auth, route-reachability, no-admin-idor, spec-coverage) were verified by hand against the new files.

## Quality checklist

- Every MUST in the spec has a unit test; the fail-closed matrix, the no-cross-debtor-IDOR guard, and the amount-integrity invariant are explicitly asserted (PSP never called on a non-authorised path; charged amount = server invoice amount).
- The PSP is MOCKED in every unit test — no test contacts a real Mollie endpoint.
- Manifest labels ship in English source (i18n policy); portaliq owns portal-side translation.
- Refunds/partial reversals are explicitly out of scope (proposal) — no refund action ships.
- Every referenced property (`ARInvoice.customerId/state`, `PaymentRequest.amount/state/paymentIntentId`) verified against HEAD; the only schema addition is `PaymentRequest.confirmationSummary`.
- No Shillinq portal UI ships (portaliq owns the SPA) — no Playwright; receiver covered by PHPUnit.
