# Tasks: inventory-receipt-cost-layers

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Receipts

- [ ] 1.1 Create receipt moves as drafts and post them through the transition engine with the dispatch path's fallback (REQ-IRCL-001, REQ-IRCL-002). Verify: PHPUnit asserting one layer and one GR/IR entry per accepted line; the FIFO example costed at EUR 932.50.

## 2. Backfill

- [ ] 2.1 `BackfillReceiptCostLayers` repair with the undercosted issue report (REQ-IRCL-003). Verify: PHPUnit on a seeded register; `npm run check:job-registration` if it is scheduled.

## 3. Page, end to end and strings

- [ ] 3.1 Stock valuation page over `/api/inventory/valuation-report` with a date (REQ-IRCL-004). Verify: `npm run check:manifest`, nav reachability.
- [ ] 3.2 Playwright `tests/e2e/inventory-receipt-cost-layers.spec.ts`: receive, dispatch, read the valuation. Verify: passes locally.
- [ ] 3.3 Live check on an instance: accept a GRN and read the layer (the matrix asked for one). Verify: object ids in the PR body.
- [ ] 3.4 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
