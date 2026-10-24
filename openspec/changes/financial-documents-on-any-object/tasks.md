# Tasks: financial-documents-on-any-object

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Schema and events

- [x] 1.1 Fragment `zz-financial-documents-linked-objects.json`: `linkedObjects` on Quote, ARInvoice, PaymentRequest, InboundPayable; `LinkedObjectsLockListener` freezes it once issued (REQ-FDO-001). Verify: LinkedObjectsFragmentTest, LinkedObjectsLockListenerTest, red first.
- [x] 1.2 `PayableRaisedEvent` carries `linkedObjects` onto the InboundPayable; the leaf and the object-request invoice write them; repair `BackfillLinkedObjects` (REQ-FDO-001). Verify: InboundPayableServiceTest, BackfillLinkedObjectsTest, red first.

## 2. Leaf

- [x] 2.1 `FinancialDocumentLeafProvider` + finder: the five kinds in one row shape, administration check before the read (REQ-FDO-002). Verify: PHPUnit, a caller outside the administration gets no amounts.
- [ ] 2.2 Acts through the leaf `create` and `createAsApp` with the new actions (REQ-FDO-003, REQ-FDO-005). Verify: PHPUnit refusals per action; route-auth and IDOR gates.

## 3. Panel and payment

- [x] 3.1 `ShillinqFinancialDocumentsPanel.vue` + registration with `kinds` and `actions` props, the acts the caller holds from the leaf envelope, and the linked-card summary (REQ-FDO-003). Verify: vitest financialDocumentsPanel.spec.js.
- [x] 3.2 Issue an invoice on the object, and request payment of an invoice with the host's linked objects (REQ-FDO-003, REQ-FDO-004). Verify: FinancialDocumentActionsTest, FinancialDocumentActionControllerTest, vitest.
- [ ] 3.3 Panel acts for new quote, credit invoice and raise payout (follow-up change).

## 4. End to end

- [ ] 4.1 Strings (en, nl) and Playwright `tests/e2e/financial-documents-on-any-object.spec.ts`. Verify: passes locally.
