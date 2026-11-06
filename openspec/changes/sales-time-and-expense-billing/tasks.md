# Tasks: sales-time-and-expense-billing

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Markup

- [ ] 1.1 Add `lib/Service/PassThroughMarkupResolver.php` with the priority rule taken from `SettlementGuard::matchMarkupRule()`, and make `SettlementGuard::computeMarkupAmount()` delegate to it (REQ-STEB-004, REQ-STEB-005). Verify: PHPUnit for each priority tier, percentage, fixed and no rule.
- [ ] 1.2 Add the lifecycle action `lock-passthrough-markup` on `ExpenseClaimEntry.submit`, register it under that name, and remove the three `markupLookup` declarations from `expense-reimbursement-or-passthrough.json` (REQ-STEB-004). Verify: `npm run check:registers`; PHPUnit that a submitted claim's items carry the rule, rate and amount and that a later rule change leaves them.

## 2. Billable work

- [ ] 2.1 Add the `BillableHoursSource` interface and an unbound default that reports hours as unavailable (REQ-STEB-001). Verify: PHPUnit for the unavailable answer.
- [ ] 2.2 Add `BillableWorkService` and `GET /api/v1/invoices/billable-work` with the administration check first and the dedup rule of `InvoiceDeduplicationService` (REQ-STEB-001, REQ-STEB-002). Verify: controller PHPUnit including a cross-administration refusal, and a service test that billed hours and items are left out.
- [ ] 2.3 Bind the humaniq hours read from `hours-to-humaniq` task 3.3 as the `BillableHoursSource` implementation (REQ-STEB-001). Verify: PHPUnit against a humaniq read double; a live check on an instance with humaniq, recorded in the PR body.

## 3. Lines

- [ ] 3.1 Let `loadExpenses()` take item references, pass the locked markup into `BillingModelEngine::expenseLine()`, add `recharge` to `BillableInvoiceLine` and `expenseItemRefs` to `BillableInvoice` (REQ-STEB-003). Verify: PHPUnit for the EUR 42.80 train receipt becoming EUR 47.08.
- [ ] 3.2 Apply the main-supply VAT rate to recharge lines and the disbursement treatment when an item is marked so (REQ-STEB-003). Verify: PHPUnit for a 9 percent receipt billed at 21 percent and a disbursement at 0 percent with the mention.
- [ ] 3.3 Take `markInvoiced` on a pass-through claim once every pass-through item sits on a posted invoice (REQ-STEB-002). Verify: PHPUnit for a claim billed in two invoices.

## 4. Page

- [ ] 4.1 Replace the two id textareas in `InvoiceGenerator.vue` with tick lists fed by the billable-work endpoint, including the unavailable-hours message and a disbursement toggle per expense (REQ-STEB-001, REQ-STEB-002, REQ-STEB-003). Verify: Vitest for the payload built from ticked rows; Playwright for the seeded Het Anker invoice.

## 5. Docs

- [ ] 5.1 Update the invoice-from-time-and-expense user guide and add a release note naming the `markupValue` precision limit. Verify: the page in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-N` on every new method, English source strings with Dutch translations.
