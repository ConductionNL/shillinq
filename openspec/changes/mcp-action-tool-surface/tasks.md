# Tasks: mcp-action-tool-surface

## 1. HEAD re-verification (before any code)
- [ ] Confirm the derived chain live on the shared instance: import the register, enumerate MCP tools, and count exactly 24 `shillinq.{Schema}.{search|get}` tools (REQ-MCP-002's "24 and nothing else" baseline must be TRUE before extending — a check that did not run looks like one that passed).
- [ ] Confirm OpenRegister's per-app alias discovery (`IMcpToolProvider::<appId>`, openregister `AppInfo/Application.php` ~3827) accepts a shillinq alias, and confirm the hand-written > derived collision rule in `SchemaDerivedToolProvider`.
- [ ] Confirm hermiq's grant surface for a foreign app's tools: default-deny matrix (`ActionAuthService`), `scope`/`reach` keys read from the tool descriptor (`ToolReachResolver::REACH_KEY`), human-approval gating for `external`/`instance` writes.

## 2. Provider skeleton (decidesk reference shape)
- [ ] `lib/Mcp/ShillinqToolProvider.php` — dispatcher + catalogue only; implements `OCA\OpenRegister\Mcp\IMcpToolProvider`; `getAppId(): 'shillinq'`; every id prefixed `shillinq.`; SPDX headers (hydra gate-1).
- [ ] `lib/Mcp/McpAdministrationGate.php` — validate → load → not_found → authorise ladder per `decidesk/lib/Mcp/McpMeetingGate.php`; bool-returning helpers, no `catch(\Throwable)` swallow (hydra unsafe-auth-resolver gate), administration-scoped via the existing `AdministrationContextService`.
- [ ] `lib/Mcp/McpArgumentValidator.php` — per-tool argument schemas, mirroring decidesk's validator.
- [ ] Register the container alias `OCA\OpenRegister\Mcp\IMcpToolProvider::shillinq` in `lib/AppInfo/Application.php` with a string-name alias (no autoload trigger when OpenRegister is absent — decidesk ADR-083 pattern).

## 3. Tool handlers (one per catalogue row, REQ-MCP-004)
- [ ] Invoicing: `invoice.draft` / `invoice.issue` / `invoice.render_pdf` / `invoice.send_einvoice` / `recurring_invoice.generate` — delegate to the exact service paths behind `InvoiceApiController` (`generate`/`post`/`pdf`), `ARInvoiceEInvoiceController`, `RecurringInvoiceGenerator`.
- [ ] Dunning: `dunning.dossier` / `dunning.execute_run` / `dunning.pause` / `dunning.resume` / `dunning.write_off` (paths behind `DunningController`).
- [ ] Payments + bank: `payment_run.export_sepa` / `payment_run.reconcile` (`PaymentRunController::export`/`reconcile`), `bank_statement.import` (`BankStatementImportController::import`), `bank_reconciliation.resolve` (`ReconciliationResolutionController::resolve`/`bulkResolve`).
- [ ] VAT: `vat_return.create` / `vat_return.rebase` / `vat_return.submit` / `vat_return.destroy` (paths behind `VATReturnController`; destroy keeps the non-submitted-only constraint).
- [ ] Expenses + time: `receipt.book` (receipt-extraction consume flow + `claimId` link), `expense_claim.approve` (`approvalState` transition), `hours.book` (`TimeIntakeService`).
- [ ] Procurement + close: `requisition.create`/`submit`/`decide`/`convert` (`RequisitionController`), `purchase_order.decide` (`PurchaseOrderApprovalController::decide`), `period_close.status`/`start`/`close`/`reopen` (`PeriodCloseController`).
- [ ] Annotate every write with `scope` + `reach` per the catalogue; every read with `readOnlyHint: true`; agent-facing descriptions in the zzz-fragment style (REQ-MCP-005).
- [ ] Audit: every invocation (incl. refusals/failures) writes one immutable audit record — parity with `AuditTrailMapper::createToolInvocationEntry` (REQ-MCP-003).

## 4. Tests
- [ ] Unit: gate refusal parity — for at least `purchase_order.decide` and `vat_return.submit`, the gate refuses exactly where the controller twin would (subclass ObjectEntity-backed doubles; accessors are magic `__call` — subclass, don't mock).
- [ ] Unit: catalogue invariants — every non-read tool declares both `scope` and `reach`; every read declares `readOnlyHint`; every id is `shillinq.`-prefixed; no CRUD-shaped tool duplicates a derived id.
- [ ] Unit: validator rejects malformed arguments before any service call; a refused invocation still audits.

## 5. Live verification (shared :8080, both surfaces)
- [ ] Enumerate tools after deploy: 24 derived + the catalogue count, no collisions, no duplicates.
- [ ] Chat scenario 1 (read): "what invoices are overdue?" answered via `shillinq.ARInvoice.search` with zero grants configured (proves default-deny does not block reads and no write path was touched).
- [ ] Chat scenario 2 (granted create): grant `receipt.book` (`create`/`user`) to a test agent, run "book this receipt", verify the `Receipt` object + `claimId` link + audit record under the agent principal.
- [ ] Chat scenario 3 (approval gate): grant `vat_return.submit`, instruct submission, verify the invocation HALTS at the human-approval gate and only proceeds after approval — and that with no grant it is refused outright (prove the gate can say NO first — positive control).
- [ ] Negative: attempt a `GLTransaction` delete and a payroll-schema read through both surfaces — both must be impossible (allow-list check: assert the expected refusal, not the absence of success).
