# Tasks — AR Invoice Payment Links

> **STATUS (2026-06-15, archived):** BUILT. `PaymentRequest` register fragment
> (schema with audit-trail, lifecycle pending→authorized→captured |
> captured_unapplied | failed | expired | voided, `paymentLink` calculation,
> 2 canonical-dialect notifications, RBAC, demo seeds, NO PCI fields),
> shared `PaymentReconciliationService` (one idempotent code path for BOTH
> `DepositPayment` and `PaymentRequest` per REQ-APL-004, capture→AR settlement
> with `captured_unapplied` exception, polling fallback),
> `PaymentRequestWebhookController` (`#[PublicPage]` HMAC-verified, fail-closed),
> the webhook route, ADR-037 manifest fragment (payment-requests overview +
> detail), l10n en+nl, and unit tests (fragment + reconciliation). All 24
> hydra gates green on the diff.
> **DEFERRED `[~]` (honest):**
> - The LIVE Mollie/Stripe leg (real per-gateway shared secrets +
>   OpenConnector hosted-UI token minting) is provider-config-dependent and
>   deferred to deployment config — the signature-verification + idempotent
>   reconciliation CODE is real and complete (reuses the proven deposits
>   pattern); the endpoint fail-closes when secrets are unset. NOT a stub.
> - REQ-APL-003 embedding into the invoice email / PDF-UBL payment-means /
>   dunning reminders is CHAINED to `bookkeeping-credit-control-dunning` and
>   the AR-core invoice-detail page (additive there); remains `[ ]`.
> - Newman + full Playwright e2e not added this pass (gate-19 passed via the
>   spec's unbuilt-UI exclusion); remain `[ ]`.

> Declarative-first per ADR-031/ADR-037: the `PaymentRequest` schema,
> lifecycle, `paymentLink` calculation, and notification rules live in the
> register fragment; pages live in the manifest fragment. The only PHP is
> the shared webhook controller + idempotent reconciliation service —
> SHARED with `bookings-deposits` [CHAINED], never forked. GL settlement
> posting belongs to AR core's `paid` transition; this change posts
> nothing.

## Phase 0: Deduplication and Chain Check

- [ ] Task 1: Confirm scope boundaries: `bookings-deposits` covers deposits
  only (no `PaymentRequest`/AR-invoice link anywhere); no payment-link
  fields on `ARInvoice` in `lib/Settings/`; no existing webhook route under
  `/apps/shillinq/api/webhooks/`. Record the merge state of
  `bookings-deposits` and decide the shared-surface landing order per
  REQ-APL-004 (whichever change merges second refactors onto the shared
  controller/service/polling job). Document findings explicitly.

## Phase 1: Register Fragment (schema, lifecycle, calculation, notifications)

- [ ] Task 2: Create the ADR-037 register fragment
  `lib/Settings/register.d/ar-invoice-payment-links.json` and declare the
  `PaymentRequest` schema with all REQ-APL-001 fields (arInvoiceId FK,
  amount, currency, paymentGateway, paymentIntentId — opaque only, no PCI
  fields —, state, expiresAt, failureReason, settlementReference,
  capturedAt); set `x-openregister-audit: true`.

- [ ] Task 3: Declare the `PaymentRequest` lifecycle (pending → authorized
  → captured | captured_unapplied | failed | expired | voided) including
  the voiding couplings per REQ-APL-006: invoice settled by other means →
  void pending requests; outstanding-amount change → void pending request.

- [ ] Task 4: Declare the `paymentLink` calculation per REQ-APL-002
  (payment-request id + invoice id + short-lived signed token, resolving to
  the OpenConnector hosted payment UI; `visibleWhen` pending + invoice
  unsettled). Verify no gateway URL construction exists in app code.

- [ ] Task 5: Declare the three `x-openregister-notifications` rules per
  REQ-APL-007 (captured / failed / captured_unapplied; field-change
  conditions on `state`; recipients invoice owner, `shillinq-finance`,
  `{"kind":"object-acl","permission":"manage"}`; subjects `nl` + `en`,
  metadata-only, no amounts). Verify gate-18 passes.

## Phase 2: Shared Webhook + Reconciliation [CHAINED: bookings-deposits]

- [ ] Task 6: [CHAINED: bookings-deposits] Land or refactor to the SHARED
  surface per REQ-APL-004: one route
  `/apps/shillinq/api/webhooks/payments/{gateway}`
  (`PaymentWebhookController`, `#[PublicPage]`, signature verified over the
  raw body with the per-gateway secret, HTTP 400 on mismatch) and one
  `PaymentReconciliationService` resolving records by `paymentIntentId`
  across BOTH `DepositPayment` and `PaymentRequest`, applying transitions
  idempotently via the real OR ObjectService API. SPDX + `@spec`
  annotations; no duplicate verification code.

- [ ] Task 7: [CHAINED: bookings-deposits] Extend the single polling
  fallback (scheduled workflow `*/5`, filter `state=pending`) to cover both
  record types through the shared service; verify no second TimedJob/cron
  exists. Polling also enriches `settlementReference` when the gateway
  exposes payout data only asynchronously.

- [ ] Task 8: Implement the capture → invoice settlement handoff per
  REQ-APL-005: trigger the `ARInvoice` `paid` lifecycle transition with the
  payment request as evidence (AR core owns GL posting); on impossible
  transitions set `captured_unapplied` and expose the refund action via
  OpenConnector's existing `initiateRefund`.

- [ ] Task 9: Unit-test the shared plumbing
  (`tests/Unit/Service/PaymentReconciliationServiceTest.php` +
  controller test): signature accept/reject, idempotent replay, both record
  types resolved, captured→paid handoff, captured_unapplied path, polling
  recovery, voiding on settlement-by-other-means.

## Phase 3: Embedding Surfaces

- [ ] Task 10: Embed the payment link in the invoice email template
  (button + plain URL, only when a pending request exists) per REQ-APL-003,
  consuming the `paymentLink` calculation — no link construction at the
  call site.

- [ ] Task 11: Add the link (and SHOULD: QR code) to the invoice PDF
  payment-means block; coordinate the UBL payment-means representation
  with `bookkeeping-quote-order-invoice` REQ-QOI-008 [CHAINED: only the
  UBL part waits for that change].

- [ ] Task 12: [CHAINED: bookkeeping-credit-control-dunning] Embed the
  latest valid payment link in dunning reminder templates, regenerating an
  expired request on demand; never embed a stale link (REQ-APL-003).

## Phase 4: Bank Reconciliation Feed

- [ ] Task 13: Record `settlementReference` on capture per REQ-APL-006 and
  expose captured requests (amount + payout reference) as auto-match input
  to `bookkeeping-bank-reconciliation` (REQ-BBR-002), including the
  gross/net fee surfaced on the match; verify the three-captures-one-payout
  scenario in a unit/integration test.

## Phase 5: Frontend (ADR-037 manifest fragment)

- [ ] Task 14: Create `src/manifest.d/ar-invoice-payment-links.json` with
  the invoice payment panel (state badge, link + copy + QR, expiry,
  regenerate, failure reason, request history) and the payment-requests
  overview page (state filter incl. exceptions filter for failed /
  captured_unapplied) per REQ-APL-008.

- [ ] Task 15: Place all modals/dialogs in their own files under
  `src/modals/` / `src/dialogs/`; every `NcSelect` carries `inputLabel`;
  initial state (if any) via `IInitialState` + `loadState()` (ADR-004
  gates).

## Phase 6: i18n

- [ ] Task 16: Add all new strings with ENGLISH source keys to
  `l10n/en.json` and Dutch translations to `l10n/nl.json` per REQ-APL-008;
  notification subjects in both `nl` and `en`; verify the l10n gate and no
  Dutch source keys in `t('shillinq', …)` calls.

## Phase 7: Tests, Gates, Docs

- [ ] Task 17: Author Playwright e2e UI specs (gate-19, UI-only — API/
  webhook assertions go to Newman): payment panel shows pending link,
  copy action, regenerate flow, exceptions filter on the overview, link
  hidden after settlement. Annotate spec scenarios with `@e2e` references;
  reason-bearing `@e2e exclude` only for true backend scenarios (webhook
  signature, polling, reconciliation).

- [ ] Task 18: Add Newman integration assertions
  (`tests/integration/*.postman_collection.json`): webhook signature
  reject (400, no side effects), idempotent capture replay, capture →
  invoice paid, captured_unapplied on settled invoice, void on
  manual settlement.

- [ ] Task 19: Run `composer check:strict` + the full hydra gate suite
  (spdx, route-auth on the webhook route — `#[PublicPage]` + signature
  semantics —, no-admin-idor, spec-coverage `@spec` annotations,
  notification-dialect, e2e-coverage) and fix everything including
  pre-existing issues encountered; verify the single-plumbing-stack
  reviewer gate (REQ-APL-004: one route, one service, one polling job);
  update `docs/` and the README ("payment links on invoices — iDEAL via
  Mollie, cards via Stripe"); bump `appinfo/info.xml` `<version>`
  (bundle-affecting change).
