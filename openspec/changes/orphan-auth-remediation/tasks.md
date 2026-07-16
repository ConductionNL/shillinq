# Tasks: orphan-auth-remediation

## 1. Triage (complete)

- [x] 1.1 Run the un-blinded gate-6 against `origin/development`; capture the 5 findings.
- [x] 1.2 Supersession check per method (`->method(` grep over `lib/ src/`; injection audit).
- [x] 1.3 Confirm the live payment-webhook path is fail-closed (controller `verifySignature`).
- [x] 1.4 Record the verdict table in `design.md` (2 DELETE, 3 SEAM, 0 WIRE, 0 UNSURE).

## 2. Delete the superseded Mollie webhook method

- [x] 2.1 Remove `verifyWebhook()` from `MolliePaymentAdapterInterface` + narrow its docblock.
- [x] 2.2 Remove `verifyWebhook()` from `LogMolliePaymentAdapter` + narrow its docblock.
- [x] 2.3 Correct the Mollie entry in `ExternalAdaptersAdminController` (inbound verification is
      on the controllers, not the adapter).
- [x] 2.4 Confirm zero residual `verifyWebhook` references in `lib/ src/ tests/ appinfo/`.

## 3. Document the retained seams

- [x] 3.1 Add `REQ-OAR-002` describing the CSRD dormant XBRL seam + SMS DTO accessor as
      intentional, non-regressive, and not gating a live financial mutation.

## 4. Tests (the point)

- [x] 4.1 Add `LogMolliePaymentAdapterTest` — dormant `createPayment` contract + `verifyWebhook`
      absence on both interface and implementation.
- [ ] 4.2 Run `PaymentRequestWebhookControllerTest` + `DepositWebhookControllerTest` — the
      superseder proof (forged/unsigned `gateway: 'mollie'` webhook rejected 400, `reconcile()`
      never called; missing secret fails closed).
- [ ] 4.3 Run the full PHPUnit suite; confirm no regression vs the ~3796-green baseline
      (4 pre-existing `Symfony\HeaderUtils` env errors excluded).

## 5. Verify + gates

- [ ] 5.1 Re-run gate-6 diff-scoped; confirm 0 findings on the changed files.
- [ ] 5.2 `phpcs`/`phpstan`/`psalm`/`phpmd` clean on the edited files.
- [ ] 5.3 Open PR "shillinq: orphan-auth-remediation (apply)" base `development`; file on #482.
