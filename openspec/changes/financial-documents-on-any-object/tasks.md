# Tasks: financial-documents-on-any-object

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Schema and events

- [ ] 1.1 Fragment `financial-document-subject.json`: `subject` on Quote, ARInvoice, InboundPayable; frozen after issue (REQ-FDO-001). Verify: register test validates each with the merged register; red first.
- [ ] 1.2 `PayableRaisedEvent` and `PayableRaisedListener` carry `subject` onto the InboundPayable (REQ-FDO-001). Verify: PHPUnit red first.

## 2. Leaf

- [ ] 2.1 `FinancialDocumentLeafProvider` + finder: the five kinds in one row shape, administration check before the read (REQ-FDO-002). Verify: PHPUnit, a caller outside the administration gets no amounts.
- [ ] 2.2 Acts through the leaf `create` and `createAsApp` with the new actions (REQ-FDO-003, REQ-FDO-005). Verify: PHPUnit refusals per action; route-auth and IDOR gates.

## 3. Panel and payment

- [ ] 3.1 `ShillinqFinancialDocumentsPanel.vue` + registration with `kinds` and `actions` props and the linked-card summary (REQ-FDO-003). Verify: vitest.
- [ ] 3.2 "Request payment" on an invoice raises an invoice payment request with the host subject (REQ-FDO-004). Verify: PHPUnit + vitest.

## 4. End to end

- [ ] 4.1 Strings (en, nl) and Playwright `tests/e2e/financial-documents-on-any-object.spec.ts`. Verify: passes locally.
