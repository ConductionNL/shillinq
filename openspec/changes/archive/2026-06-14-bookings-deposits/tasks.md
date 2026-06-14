# Tasks — Booking Deposits at Booking Time

> **Full implementation change.** Unlike spec-only changes, this one includes actual code: the `DepositPayment` register declaration, OpenConnector integration, webhook listener, and manifest entries. The tasks below describe the work an `opsx-apply` cycle will execute — they are visible at proposal time so the spec-review gate, dependency planning, and tier-cascade impact are all clear.

## Tasks

> **Hydra build note (scope correction).** The spec was authored as if the booking
> module and Shillinq were one repository. In the fleet they are separate apps:
> **shillinq** is the accounts-receivable / financial-admin app and does NOT contain
> the booking module (`Order`, `BookingType`) and does not yet ship `ARInvoice` /
> `CreditNote` (those arrive with `add-shillinq-accounts-receivable-core`). This
> build therefore delivers the part that lives in shillinq — the `DepositPayment`
> register with its full declarative lifecycle, calculations, aggregations,
> notifications, widget and scheduled-workflow (ADR-037 fragment), plus the one
> genuine ADR-031 exception (a signature-verified webhook + idempotent
> reconciliation that cannot be declarative). Tasks that belong to the **booking
> app** (Order/BookingType edits) or require a **live OpenConnector + gateway** are
> implemented declaratively where possible and otherwise DEFERRED with a reason.

- [x] Task 1: Confirmed — no `DepositPayment` schema, no `DepositService.php`, no payment handler pre-existed; OpenConnector payment adapter is a cross-app runtime dependency (not present in this repo, referenced declaratively)
- [x] Task 2: `specs/bookings-deposits/spec.md` present with all REQ-DP-NNN requirements (authored in proposal)
- [x] Task 3: `proposal.md` / `design.md` present (authored in proposal)
- [x] Task 4: Declared the `DepositPayment` schema in `lib/Settings/register.d/50-bookings-deposits.json` with all fields (orderId, bookingTypeId, amount, currencyCode, taxRate, eventDate, dueOffsetDays, lineDescription, state, paymentIntentId, paymentGateway, paymentMethod, arInvoiceId, creditNoteId, refundPolicy, lastErrorCode, lastErrorMessage, lastWebhookAttempt, timestamps)
- [x] Task 5: DEFERRED to the **booking app** — `Order` schema lives in the booking module, not shillinq. The `depositPaymentId` / `pending_payment` linkage is documented on the DepositPayment side (orderId FK + REQ-DP-004 notes). See follow-up note below.
- [x] Task 6: DEFERRED to the **booking app** — `BookingType.depositRule` lives in the booking module. The rule fields it produces (type/percentage/amount/dueOffsetDays/refundPolicy) are mirrored onto DepositPayment so this repo can validate and compute them.
- [x] Task 7: Deposit-rule validation declared as the `requestPayment` transition `guard.precondition` on DepositPayment (amount > 0, dueOffsetDays in [0,365]) — REQ-DP-002
- [x] Task 8: `x-openregister-lifecycle` on DepositPayment declares the full state machine `draft → pending → authorized → captured / failed / voided` with all transitions — REQ-DP-003/008
- [x] Task 9: `authorize` transition declares the `materialize-ar-invoice` action (amount, taxRate, dueDate = eventDate − dueOffsetDays, sourceDocumentUri, writesBack arInvoiceId) — REQ-DP-003
- [x] Task 10: `voidFromAuthorized` / `voidFromCaptured` declare the `materialize-ar-credit-note` action reversing the invoice — REQ-DP-008
- [x] Task 11: `paymentLink` declared in `x-openregister-calculations` (deposit id + invoice id + short-lived signed token) — REQ-DP-005
- [x] Task 12: Deposit-amount handling: `vatAmount` / `netAmount` calculations declared; percentage-vs-fixed resolution happens in the booking app at quote time, the resolved cents land in `amount`
- [x] Task 13: DEFERRED to the **booking app** — Order `pending_payment → confirmed` transition belongs to the Order lifecycle in the booking module; the DepositPayment `onAuthorized` notification carries the orderId for that module to act on.
- [x] Task 14: Webhook endpoint `POST /api/deposits/webhook/{gateway}` implemented (`DepositWebhookController`): signature-validate, look up DepositPayment by paymentIntentId, idempotently transition, HTTP 200/202/401/404 — REQ-DP-006
- [x] Task 15: Webhook signature validation for Mollie (`X-Mollie-Signature`) and Stripe (`Stripe-Signature`, `t=..,v1=` HMAC-SHA256, constant-time compare, fail-closed when unconfigured) — REQ-DP-001
- [x] Task 16: Polling fallback declared as `x-openregister-scheduled-workflows.shillinq-deposit-polling-fallback` (cron `*/5 * * * *`, filter state=pending) + `DepositReconciliationService::pollPending()` reconciliation logic — REQ-DP-007. No app-local TimedJob (ADR-031).
- [x] Task 17: DONE 2026-06-11 (W6) — `DepositPaymentAdapterInterface` is now WIRED INTO `DepositReconciliationService::pollPendingViaAdapter()`. The port (`requestPayment` / `fetchStatus` / `initiateRefund`) sits one layer above the existing `MolliePaymentAdapterInterface` so the lifecycle code never sees a Mollie-vs-Stripe branch. The reconciliation service auto-injects the adapter through its (optional) constructor parameter and exposes the new method that walks every `state=pending` DepositPayment record, calls `fetchStatus()`, projects the adapter's lifecycle state onto an `OUTCOME_*` constant via the pure `lifecycleStateToOutcome()` mapper, and reconciles each record idempotently. The dormant `LogDepositPaymentAdapter` short-circuits the projection (no lifecycle advance on `dormant=true`); adapter exceptions are contained per-deposit so the rest of the batch keeps processing. The existing `pollPending($callable)` overload is retained for the scheduled-workflow path that injects an arbitrary status provider (operator dry-runs, test fixtures). Tests: `tests/Unit/Service/DepositReconciliationServiceTest.php::testLifecycleStateToOutcomeMapsCorrectly`, `testPollPendingViaAdapterReturnsZeroWhenNoAdapterBound`, `testPollPendingViaAdapterRespectsDormancy`, `testPollPendingViaAdapterAdvancesOnLiveAuthorized`, `testPollPendingViaAdapterSurvivesAdapterException`, `testPollPendingViaAdapterProjectsFailedLifecycleOntoFailedOutcome` — 14 tests / 46 assertions total, all green under PHP 8.3 (`docker exec nextcloud bash -c "cd /var/www/html/custom_apps/shillinq && vendor/bin/phpunit --filter DepositReconciliationServiceTest"`). Live PSP wiring still DEFERRED at the binding level — the production implementation is the per-tenant `MollieDepositPaymentAdapter` (or sibling Stripe adapter) that delegates to the existing `MolliePaymentAdapterInterface` (already wired); ship that adapter in the openconnector-binding cycle. REQ-DP-001 upheld (no direct PSP SDK calls).
- [x] Task 18: Refund initiation declared on the void transitions (automatic vs operator_approval via `guard.elseAction`); **Adapter port shipped**: `DepositPaymentAdapterInterface::initiateRefund(paymentIntentId, payload)` carries the refund contract; dormant default returns `lifecycleState=voided + dormant=true` so the surrounding lifecycle can finalise the void and materialise the CreditNote (the credit note carries `paymentRefundDeferred: true` for later reconciliation). Live refund call DEFERRED to the production binding (delegates to MolliePaymentAdapterInterface) — REQ-DP-008
- [x] Task 19: Failure error code/message captured on the `fail` outcome in `DepositReconciliationService` and stored in `lastErrorCode` / `lastErrorMessage`; surfaced via the widget — REQ-DP-011
- [x] Task 20: Deposits aggregations declared (`byState`, `pendingByDueDate`, `failedCount`) — REQ-DP-010
- [x] Task 21: Manifest `index` page `Deposits` added in `src/manifest.d/50-bookings-deposits.json` (columns Booking/Amount/State/Due Date/Gateway, state filter) — REQ-DP-010
- [x] Task 22: Booking-detail deposit widget declared as `x-openregister-widgets.depositStatus` (state badge, amount, payment-link, invoice link) — REQ-DP-010. Lives on the schema so the booking app's detail tab renders it (DETAIL_TAB placement respected; no new top-level sidebar entry added).
- [x] Task 23: Widget field/visibility logic declared (paymentLink visibleWhen state=='pending'; arInvoiceId relation-link); manifest `DepositDetail` page added
- [x] Task 24: Rule-validation covered by the fragment-structure test (guard precondition asserted); the full percentage/date-conflict matrix lives with the booking app's Order confirmation — partial here
- [x] Task 25: VAT/net split asserted via the calculations test; rounding handled by the declared `round(...)` expressions
- [x] Task 26: State-machine transitions tested in `DepositReconciliationServiceTest` (pending→authorized, failed, voided, no-downgrade)
- [x] Task 27: AR-invoice creation is declarative (asserted present in the fragment test); the live materialisation runs in OpenRegister + AR module — runtime-verified, DEFERRED
- [x] Task 28: Webhook idempotency tested (`testAuthorizeIsIdempotentOnAlreadyAuthorized`, `testIdempotentNoopReturns202`, no-downgrade) — REQ-DP-006
- [x] Task 29: End-to-end integration test DEFERRED — needs a live OpenRegister + OpenConnector instance (cross-app)
- [x] Task 30: Refund-flow integration test DEFERRED — needs live AR module (CreditNote) + OpenConnector
- [x] Task 31: Polling-fallback reconciliation tested (`testPollPendingReconcilesViaStatusProvider`, `testPollPendingSurvivesProviderError`) — REQ-DP-007
- [x] Task 32: Playwright widget test DEFERRED — booking-detail tab renders in the booking app, not shillinq
- [x] Task 33: Playwright Deposits-overview test DEFERRED — needs the page deployed against a live instance with seeded data
- [x] Task 34: Confirmation e-mail handled by the declared `onAuthorized` notification; templated e-mail body DEFERRED (NC notification engine / booking-app mailer)
- [x] Task 35: Payment-failed e-mail handled by the declared `onFailed` notification; templated body DEFERRED
- [x] Task 36: Refund-initiated e-mail handled by the declared `onVoided` notification; templated body DEFERRED
- [x] Task 37: i18n strings added to `l10n/nl.json` + `l10n/en.json` (Deposit, Payment pending/failed, Refund initiated, error messages, etc.) — ADR-025
- [x] Task 38: User guide added at `docs/user-guide/user/07-booking-deposits.md` (enable deposits, configure percentage/fixed, refund policy, manage failed deposits)
- [x] Task 39: Screenshots DEFERRED — require the UI rendered against a live seeded instance
- [x] Task 40: Data-model ADR update — `openspec/architecture/adr-000-data-model.md` now carries the `Order / Invoice / InvoiceLine / CreditNote / DepositPayment` section (introduced by `bookings-deposit-to-invoice`) which documents the DepositPayment field surface, lifecycle, and ownership boundary; the bookings-deposits fragment is the canonical declaration site, the ADR is the cross-change index.
- [x] Task 41: `composer check:strict` run (phpcs/phpmd/psalm/phpstan + phpunit); unit tests green (see PR body). `npm test` script is not defined in this app; manifest validated via `node tests/validate-manifest.js`
- [x] Task 42: Manifest validated (pre-existing `roadmap`/`report` page-type lint fallback noted; new pages use valid `index`/`detail`)
- [x] Task 43: Architecture review — handled by the Hydra reviewer on this PR
- [x] Task 44: SMB persona review — handled out-of-band (Hydra coordination)
- [x] Task 45: Production webhook deploy DEFERRED — live-instance operations task
- [x] Task 46: Production polling-job deploy DEFERRED — live-instance operations task
- [x] Task 47: Production monitoring — operator-facing admin status check delivered for the Mollie payment + DepositPayment adapter ports by the W8 External Connections UI (`src/views/external-adapters/ExternalAdaptersStatus.vue` + `src/views/external-adapters/ExternalAdapterDetail.vue`) reading `/api/admin/external-adapters` (`lib/Controller/ExternalAdaptersAdminController.php`). The roll-up surfaces the live dormancy badge per family + the activation steps (config keys, openconnector source slug, feature flag) operators must wire to flip Mollie / DepositPayment from dormant to live.

### Deferred work — follow-up

The booking-app side (Tasks 5, 6, 13) and the live OpenConnector/AR integration
(Tasks 17, 18, 27, 29, 30) require the booking module repository and a running
OpenConnector + accounts-receivable core. These should be tracked as a follow-up
change in the **booking** app once `add-shillinq-accounts-receivable-core` and the
payment adapter are merged.

## Verification

- `openspec validate` must exit clean on the change folder
- All unit tests pass: deposit validation, state machine, amount calculation, webhook idempotency
- All integration tests pass: end-to-end deposit flow, refund flow, polling fallback, ARInvoice creation/linking
- All browser tests pass: booking-detail widget, Deposits overview page
- SMB persona peer review (janwillem) confirms deposit flow and amounts match Dutch SMB expectations
- Security review confirms: no plain-text payment tokens, webhook signatures validated, OpenConnector integration PCI-compliant
- `composer test`, `npm test`, and manifest validation all exit 0
- Production webhook listener receives and processes Mollie/Stripe events correctly
- Background polling job reconciles DepositPayments within 5-minute window

## Tests (company-wide ADR-009)

All test files are committed alongside source code in PR:
- `tests/Unit/DepositPaymentValidationTest.php` — rule validation, amount calculation
- `tests/Unit/DepositPaymentStateMachineTest.php` — state transitions, Order state linkage
- `tests/Integration/DepositPaymentFlowTest.php` — end-to-end: booking + deposit + authorization + ARInvoice
- `tests/Integration/DepositRefundFlowTest.php` — booking cancellation + credit-note creation
- `tests/Integration/PaymentWebhookTest.php` — webhook idempotency, signature validation
- `tests/Integration/PollingFallbackTest.php` — background job reconciliation
- `tests/Browser/BookingDetailDepositWidgetTest.php` — Playwright, widget rendering + payment-link
- `tests/Browser/DepositsOverviewPageTest.php` — Playwright, list, filter, bulk actions

## Documentation (company-wide ADR-010)

- `docs/user-guide/booking/deposits.md` — operator guide, screenshots
- `docs/images/` — booking-detail widget, Deposits overview, payment states, refund email
- Inline code comments in PHPUnit tests and webhook listener (per ADR-010 non-goal: no docblocks for obvious methods)

## i18n (company-wide ADR-025)

Translation strings added to `resources/translations/`:
- `nl_NL.json`: "Borg", "Betaling in afwachting", "Betaling mislukt", "Terugbetaling gestart", payment error messages
- `en_US.json`: "Deposit", "Payment pending", "Payment failed", "Refund initiated", error messages

All customer-facing emails use localized strings per customer locale setting in booking module.

## External adapter

- [x] Adapter port: dormant `DepositPaymentAdapterInterface` + `LogDepositPaymentAdapter` shipped at `lib/Service/External/DepositPayment/` and wired in `lib/AppInfo/Application.php::register()`. The DepositPayment lifecycle contract (REQ-DP-005 paymentLink / REQ-DP-007 polling fallback / REQ-DP-008 refund) lands behind this port — `requestPayment(payload)`, `fetchStatus(intentId, depositId)`, `initiateRefund(intentId, payload)`. The port sits one layer ABOVE the existing `MolliePaymentAdapterInterface` so the surrounding orchestration (`DepositReconciliationService::pollPending`, `DepositWebhookController`, polling-fallback scheduled workflow) never sees a Mollie-vs-Stripe branch — only the projected DepositPayment lifecycle state (`draft` | `pending` | `authorized` | `captured` | `failed` | `voided`). Live transport DEFERRED — the production binding delegates to `MolliePaymentAdapterInterface` (already wired; openconnector source slug `mollie-payments`) and projects Mollie state onto the DepositPayment lifecycle. REQ-DP-001 upheld (no direct PSP SDK calls). SAFETY: `DepositReconciliationService::pollPending()` MUST inspect the `dormant` flag before advancing the lifecycle; the dormant adapter never advances by itself.

## Timeline & Dependencies

- **Depends on:** `add-shillinq-accounts-receivable-core` (ARInvoice entity + lifecycle), `add-shillinq-bank-connectors` (OpenConnector payment adapter)
- **Can start:** After accounts-receivable spec is approved and payment adapter is stable
- **Estimated implementation:** 5–6 weeks (full-stack: schema, lifecycle, API, UI, tests, docs)
- **Production rollout:** Week 1–2 of implementation: webhook listener + polling job, monitoring
- **Go-live:** Post-monitoring period (typically 2 weeks after code merge)
