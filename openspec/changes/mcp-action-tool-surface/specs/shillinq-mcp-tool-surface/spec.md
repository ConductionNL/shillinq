# Spec: shillinq-mcp-tool-surface (delta)

## MODIFIED Requirements

### Requirement: REQ-MCP-001 — Derived CRUD tools MUST stay declarative on the schema; action tools MUST live in exactly one hand-written provider

The 24 coarse-CRUD read tools MUST remain derived by OpenRegister's
`SchemaDerivedToolProvider` from the `x-openregister-mcp` blocks in
`lib/Settings/register.d/zzz-mcp-tool-surface.json` (ADR-037 key-union merge,
ADR-063) — no CRUD-shaped tool may be hand-written. Non-CRUD **action tools**
(verbs that traverse a shillinq service rather than reading/writing one
object) MUST live in a single hand-written provider
`OCA\Shillinq\Mcp\ShillinqToolProvider` implementing
`OCA\OpenRegister\Mcp\IMcpToolProvider`, registered under the container alias
`OCA\OpenRegister\Mcp\IMcpToolProvider::shillinq` (OpenRegister's per-app
discovery convention), with every tool id prefixed `shillinq.`. The provider
MUST follow the decidesk reference shape (`decidesk/lib/Mcp/`): a dispatcher
owning the catalogue, a per-object authorisation gate
(`McpAdministrationGate`, mirroring `McpMeetingGate`'s validate → load →
not_found → authorise ladder with bool-returning helpers never wrapped in
`catch(\Throwable)`), an `McpArgumentValidator`, and a scope resolver.
Exposure stays default-OFF: a schema without the dialect and an action without
a catalogue entry produce no tool.

#### Scenario: the derived read surface is unchanged

- **GIVEN** this change is deployed
- **WHEN** the MCP tool list is enumerated
- **THEN** the 24 `shillinq.{Schema}.{search|get}` derived tools still exist, all `scope: "read"` / `readOnlyHint: true`
- **AND** no CRUD-shaped duplicate of them exists in `ShillinqToolProvider`

#### Scenario: a CRUD-expressible tool is rejected from the provider

- **GIVEN** a proposed provider tool that merely searches or gets one schema's objects
- **WHEN** the change is reviewed
- **THEN** it MUST be moved to the `x-openregister-mcp` fragment instead of the provider

#### Scenario: hand-written beats derived on id collision

- **GIVEN** a future fragment declares a verb whose derived id collides with a provider tool id
- **WHEN** `SchemaDerivedToolProvider` builds shillinq's derived tools
- **THEN** it self-suppresses the colliding derived duplicate (hand-written > derived)

### Requirement: REQ-MCP-003 — Write tools MUST be exposed only under hermiq's default-deny scope×reach grant model; BSN- and credential-bearing schemas stay excluded outright

The blanket refusal of write verbs is lifted **only because** the two platform
capabilities it named as prerequisites now exist in hermiq: agent-principal
attribution with a per-invocation audit record, and a human-in-the-loop
approval gate, fronted by a **default-deny** grant matrix
(`ActionAuthService`) keyed on each tool's declared `scope`
(`read`/`create`/`update`/`delete`) and `reach`
(`ToolReachResolver::REACH_SELF/USER/INSTANCE/EXTERNAL`). Accordingly:

- Every write tool MUST declare `scope` and `reach`; a write tool without both
  annotations MUST NOT be published.
- Writes remain **default-deny**: absent an explicit per-agent grant, every
  write tool invocation fails.
- Tools with `reach: external` (Digipoort, Peppol, SEPA, dunning dispatch) and
  `reach: instance` writes (approvals, period close, write-off) MUST be
  gateable by hermiq's human-approval step.
- Every provider invocation — read, write, or failed — MUST write one
  immutable audit record (parity with the derived provider's
  `AuditTrailMapper::createToolInvocationEntry` behaviour), so agent action is
  always distinguishable from human action.
- `GLTransaction` MUST still have no delete tool of any kind; corrections are
  postings, not deletions. The only `delete`-scope tool is
  `shillinq.vat_return.destroy`, which is valid solely for non-submitted
  returns (the same constraint `VATReturnController::destroy()` enforces).
- Unchanged and absolute: no schema bearing credential material
  (`WidgetAccessKey.apiKeyHash`, `ConfirmationToken.tokenString`) or
  special-category PII (`Employee.bsn`, `Werknemer.bsn`, `IBAangifte.bsn`,
  `IB47Record.ontvangerBSN`) may be reached through any shillinq tool — read
  or write, derived or hand-written.

#### Scenario: an ungranted write is refused by default

- **GIVEN** an agent with no explicit grant for `shillinq.dunning.execute_run`
- **WHEN** it invokes the tool
- **THEN** hermiq's default-deny matrix refuses the invocation
- **AND** the refusal is recorded in the audit trail attributed to the agent principal

#### Scenario: an external-reach write requires human approval

- **GIVEN** an agent granted `shillinq.vat_return.submit` (`scope: update`, `reach: external`)
- **WHEN** it invokes the tool
- **THEN** the invocation MUST pass hermiq's human-approval gate before `VATReturnController::submit()`'s service path runs
- **AND** without approval nothing reaches Digipoort

#### Scenario: the ledger still cannot be deleted from

- **WHEN** the MCP tool list is enumerated
- **THEN** no `shillinq.*` tool with `scope: delete` targets `GLTransaction`
- **AND** no BSN- or credential-bearing schema is reachable through any tool

## ADDED Requirements

### Requirement: REQ-MCP-004 — The provider SHALL cover every user action, one tool per action, reads separated from writes

`ShillinqToolProvider` SHALL expose one tool per real user action enumerated
from `lib/Controller/` + `lib/Service/`, delegating to the same service path
as the controller action, in the caller's ambient Nextcloud session (no
impersonation — RBAC identical to REST). The initial catalogue, grounded
action-by-action:

| Tool id | Backing action | scope | reach |
|---|---|---|---|
| `shillinq.invoice.draft` | `InvoiceApiController::generate()` / `InvoiceGenerationService` | create | user |
| `shillinq.invoice.issue` | `InvoiceApiController::post()` (sequential numbering) | update | instance |
| `shillinq.invoice.render_pdf` | `InvoiceApiController::pdf()` / `InvoicePdfGenerator` | read | user |
| `shillinq.invoice.send_einvoice` | `ARInvoiceEInvoiceController` (UBL/Peppol dispatch) | update | external |
| `shillinq.recurring_invoice.generate` | `RecurringInvoiceGenerator` | create | user |
| `shillinq.dunning.dossier` | `DunningController::dossier()` | read | user |
| `shillinq.dunning.execute_run` | `DunningController::executeRun()` / `DunningRunService` | update | external |
| `shillinq.dunning.pause` / `shillinq.dunning.resume` | `DunningController::pause()` / `resumePause()` | update | user |
| `shillinq.dunning.write_off` | `DunningController::writeOff()` | update | instance |
| `shillinq.payment_run.export_sepa` | `PaymentRunController::export()` | update | external |
| `shillinq.payment_run.reconcile` | `PaymentRunController::reconcile()` | update | user |
| `shillinq.bank_statement.import` | `BankStatementImportController::import()` | create | user |
| `shillinq.bank_reconciliation.resolve` | `ReconciliationResolutionController::resolve()` / `bulkResolve()` | update | user |
| `shillinq.vat_return.create` | `VATReturnController::create()` / `VATReturnService` | create | user |
| `shillinq.vat_return.rebase` | `VATReturnController::rebase()` | update | user |
| `shillinq.vat_return.submit` | `VATReturnController::submit()` (Digipoort) | update | external |
| `shillinq.vat_return.destroy` | `VATReturnController::destroy()` (non-submitted only) | delete | user |
| `shillinq.receipt.book` | receipt-extraction consume flow (`Receipt` + `claimId` link) | create | user |
| `shillinq.expense_claim.approve` | `ExpenseClaimEntry.approvalState` transition | update | instance |
| `shillinq.requisition.create` / `shillinq.requisition.submit` | `RequisitionController::create()` / `submit()` | create / update | user |
| `shillinq.requisition.decide` | `RequisitionController::approve()` / `reject()` | update | instance |
| `shillinq.requisition.convert` | `RequisitionController::convert()` / `RequisitionConversionService` | create | instance |
| `shillinq.purchase_order.decide` | `PurchaseOrderApprovalController::decide()` | update | instance |
| `shillinq.period_close.status` | `PeriodCloseController::show()` / `aiFlags()` | read | user |
| `shillinq.period_close.start` / `shillinq.period_close.close` / `shillinq.period_close.reopen` | `PeriodCloseController::startClose()` / `close()` / `reopen()` | update | instance |
| `shillinq.hours.book` | `TimeIntakeService` (`UrenRegistratie`) | create | self |

Read tools MUST carry `readOnlyHint: true` and MUST NOT share a tool id
namespace entry with a write; a new controller action added later without a
catalogue entry (or a recorded exclusion rationale) is a spec violation.

#### Scenario: an action tool runs the same guards as its REST twin

- **GIVEN** a caller lacking approval rights on a purchase order
- **WHEN** the agent invokes `shillinq.purchase_order.decide` for that PO
- **THEN** `McpAdministrationGate` refuses exactly where `PurchaseOrderApprovalController::decide()` would
- **AND** the refusal produces an audit record, not a silent skip

#### Scenario: every catalogue write carries both grant axes

- **WHEN** `ShillinqToolProvider::getTools()` is enumerated
- **THEN** every non-read tool declares both `scope` and `reach`
- **AND** every read tool declares `readOnlyHint: true`

#### Scenario: a new action cannot ship untooled silently

- **GIVEN** a PR adding a new public controller action
- **WHEN** the change is verified against this spec
- **THEN** the action has a catalogue tool OR a recorded exclusion rationale in this spec

### Requirement: REQ-MCP-005 — A user SHALL be able to command shillinq from chat, with agent-facing descriptions carrying the routing knowledge

Every tool description SHALL be agent-facing prose stating what the tool does
and when to reach for it (the style already used in
`zzz-mcp-tool-surface.json`, e.g. ARInvoice search: "Use for 'which invoices
are overdue?'"), so a chat model can route a natural-language command to the
right tool without shillinq-specific prompting. The surface MUST serve both
OpenRegister MCP surfaces (JSON-RPC + chat `ToolRegistry`/`McpProviderBridge`).

#### Scenario: "what invoices are overdue?" needs no write grant

- **GIVEN** a user asking an agent "what invoices are overdue?"
- **WHEN** the agent routes via tool descriptions
- **THEN** it answers with the derived `shillinq.ARInvoice.search` read tool (amountDue/dunning live there)
- **AND** no write grant, approval, or provider tool is involved

#### Scenario: "book this receipt" is a granted create with a visible trail

- **GIVEN** a user telling an agent "book this receipt against my open expense claim", and the agent holding a `create`/`user` grant for `shillinq.receipt.book`
- **WHEN** the agent invokes the tool with the receipt image reference
- **THEN** a `Receipt` object is created and linked via `claimId` through the same consume flow the UI uses
- **AND** the invocation is audit-recorded under the agent principal

#### Scenario: "submit the Q2 BTW return" pauses for a human

- **GIVEN** a user instructing an agent to prepare and submit the Q2 VAT return
- **WHEN** the agent invokes `shillinq.vat_return.create` (granted) and then `shillinq.vat_return.submit`
- **THEN** the create proceeds under its grant, and the submit — `reach: external` — halts at hermiq's human-approval gate until the user approves
- **AND** upon approval the return is submitted and `VatReturn.state`/`submittedAt` update as if filed from the UI
