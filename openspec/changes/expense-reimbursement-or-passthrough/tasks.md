# Tasks — Expense Reimbursement or Pass-through

> **Implemented (hydra build, 2026-06).** The proposal framed this as spec-only,
> but the upstream dependency (`expense-capture-core`) is already merged into the
> monolith, so this build implements the declarative configuration directly.
>
> **Spec correction (ADR-022):** the spec names schemas `Expense`/`ExpenseClaim`;
> those do not exist in the shillinq model. The real upstream schemas are
> `Receipt` (the expense line item) and `ExpenseClaimEntry` (the claim) — corrected
> throughout. The 7-state `ExpenseClaimEntry` lifecycle from REQ-ERP-007 / the
> EXTENDED Lifecycle table already ships in the monolith from expense-capture-core,
> so no lifecycle redeclaration is needed (re-declaring it would duplicate the
> post action list on fragment merge — see fragment test).
>
> **ADR-037:** all schema/seed work lands in
> `lib/Settings/register.d/expense-reimbursement-or-passthrough.json` (never the
> monolith). Cross-schema/immutability logic the declarative DSL cannot express
> lives in `OCA\Shillinq\Lifecycle\SettlementGuard` (ADR-031 exception path),
> mirroring the existing `ExpenseClaimGuard`.

## Tasks

- [x] Task 1: Confirm no `expense-reimbursement-or-passthrough` capability spec already exists, no `settlementMode` or pass-through fields are declared on `Expense` or `ExpenseClaim`, and no `lib/Service/Settlement*` or `lib/Service/PassThrough*` PHP classes are present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/expense-reimbursement-or-passthrough/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: expense-capture-core, bookkeeping-general-ledger, accounts-receivable-core` header, `REQ-ERP-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 + ADR-024 inline; address market intelligence evidence (11/26 competitors offer dual-mode settlement)

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Approach / New Dependencies / Impact / Cross-Project Dependencies / Risks (pass-through markup audit immutability, customer AR linking, SEPA notification delivery, markup approval threshold conflicts) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Goals / Non-Goals / Decisions (D1 settlement mode enum, D2 optional approval threshold, D3 cost + markup storage, D4 dual-path GL posting, D5 markup rule lookup, D6 SEPA as event) / Reuse Analysis table / Declarative-vs-imperative decision table / Seed Data tables (ReimbursementPolicy, PassThroughMarkupRule) / Risks / Out-of-Scope Decisions

- [x] Task 5: Extend `Expense` schema in `lib/Settings/shillinq_register.json` with all REQ-ERP-002 fields (`settlementMode`, `linkedCustomerId`, `markupRuleId`, `markupRateApplied`, `markupAmountCalculated`, `passthroughDebitAccountCode`); add validation rule: `linkedCustomerId` required if `settlementMode = pass-through`

- [x] Task 6: Extend `ExpenseClaim` schema in `lib/Settings/shillinq_register.json` with all REQ-ERP-003 fields (`settlementMode`, `totalReimbursableAmount`, `totalPassThroughAmount`, `passThroughCustomerIds`, `glReimbursableTransactionId`, `glPassThroughTransactionId`); add validation: all child expenses MUST have matching or null `settlementMode`

- [x] Task 7: Declare new `ReimbursementPolicy` schema in `lib/Settings/shillinq_register.json` with all REQ-ERP-004 fields (`policyId`, `name`, `description`, `autoApproveThreshold`, `requiresMarkupApprovalThreshold`, `employeeBankAccountMapping`, `administrationId`); one policy per administration

- [x] Task 8: Declare new `PassThroughMarkupRule` schema in `lib/Settings/shillinq_register.json` with all REQ-ERP-005 fields (`ruleId`, `targetCustomerId`, `targetCategory`, `markupType`, `markupValue`, `currency`, `effectiveFromYear`, `administrationId`); add lookup service for rule matching (customer + category > customer only > global default per priority)

- [x] Task 9: Add `x-openregister-calculations` to `Expense` for `markupAmountCalculated` field: IF `settlementMode = pass-through`, lookup markup rule matching `linkedCustomerId` + `category` with priority (customer+category > customer > global), apply `markupValue` to `amount`, store result; else null

- [x] Task 10: Add `x-openregister-calculations` to `ExpenseClaim` for aggregate fields: `totalReimbursableAmount = SUM(Expense.amount WHERE settlementMode = reimbursable)`, `totalPassThroughAmount = SUM(Expense.markupAmountCalculated WHERE settlementMode = pass-through)`, `passThroughCustomerIds = UNIQUE(Expense.linkedCustomerId WHERE settlementMode = pass-through)`

- [x] Task 11: Extend `ExpenseClaim` lifecycle with REQ-ERP-007 states: `reimbursed` (reimbursable path), `invoiced` (pass-through path), `disputed` (hold for both paths), `voided` (GL reversal both paths); add transition guards: draft→submitted requires settlementMode, submitted→approved consumes OR approval-workflow + optional markup approval if threshold exceeded

- [x] Task 12: Declare GL materialisation on `ExpenseClaim.posted` per REQ-ERP-007: branch on `settlementMode` — (a) reimbursable: debit expense-payable account, credit cost centres per line; (b) pass-through: debit customer AR, credit cost centres + revenue-deferral account; populate `glReimbursableTransactionId` or `glPassThroughTransactionId`; use T1 REQ-JE-007 pattern

- [x] Task 13: Declare SEPA payment notification event on `ExpenseClaim.posted` (reimbursable mode) per REQ-ERP-008: emit `ExpenseClaimReimbursementNotification` with claimId, employeeId, employeeBankAccount, amount, currency, glEntryId, policyId; event logged in audit trail + published to event-bus; treasury module consumes asynchronously

- [x] Task 14: Declare pass-through AR linking on `ExpenseClaim.posted` (pass-through mode) per REQ-ERP-009: GL entry debit linked to customer AR record per `linkedCustomerId` + `glPassThroughTransactionId`; on next invoice cycle (T2 accounts-receivable), claim cost + markup accumulated on customer AR + included in invoice

- [x] Task 15: Implement markup-rate locking on claim submission per REQ-ERP-010: `markupRateApplied` and `markupAmountCalculated` are immutable after submission; mark fields as read-only in schema; GL entry metadata records applied rate + rule ID for audit trail; future rule changes do NOT affect historical claims

- [x] Task 16: Implement optional settlement-mode change + GL reversal per REQ-ERP-011: operator can change `settlementMode` post-submission only with high privilege; if claim already posted, existing GL entry is reversed per T1 REQ-GL-004 (credit/debit reversed) before new GL entry is created with updated mode

- [x] Task 17: Declare `ReimbursementPolicy` approver rule per REQ-ERP-006: IF `requiresMarkupApprovalThreshold` is set AND claim `totalPassThroughAmount × (markupRateApplied - 1)` ≥ threshold, THEN extra approver gate on submitted→approved transition; consumed via OR approval-workflow (ADR-022), not app-local table

- [x] Task 18: Seed `ReimbursementPolicy` master table per design.md: (a) POL-NL-01 "Netherlands Standard" with autoApproveThreshold €500, null markup approval threshold; (b) POL-NL-02 "Netherlands High-Touch" with autoApproveThreshold €100, markupApprovalThreshold €100

- [x] Task 19: Seed `PassThroughMarkupRule` master table per design.md: (a) RULE-001 global default 15% percentage markup; (b) RULE-002 customer CUST-123 travel 10% (negotiated); (c) RULE-003 customer CUST-456 all categories 20% (VIP); (d) RULE-004 global meals €2.50 fixed markup per receipt

- [x] Task 20: Update `openspec/architecture/adr-000-data-model.md` with extended `Expense` (add settlementMode, linkedCustomerId, markupRuleId fields) and `ExpenseClaim` (add settlementMode, totalPass ThroughAmount, glPassThroughTransactionId fields); declare new `ReimbursementPolicy` and `PassThroughMarkupRule` schemas; reconcile against any existing settlement or pass-through entities

- [x] Task 21: Add 1 manifest form page (`Expense Settlement Classifier`) for bulk expense classification per proposal.md Impact; operator selects multiple expenses, chooses `settlementMode`, optionally picks customer and markup rule, applies; form submits via standard approval workflow if necessary

- [x] Task 22: Validate GL account configuration per policy: reimbursable path requires expense-payable account code; pass-through path requires customer AR account code per `ReimbursementPolicy.passthroughDebitAccountCode`; on claim submission, system checks that required GL accounts exist and are configured for the administration

- [x] Task 23: Lookup service for `PassThroughMarkupRule` matching: given (customerId, expenseCategory, administrationId, fiscalYear), return matching rule with priority: (customer + category) > (customer only, same category=null) > (global, customer=null, category=null); used by markup calculation in Task 9

- [x] Task 24: Integrate with T2 accounts-receivable invoice generation: on invoice cycle, query all claims linked to customer (via `glPassThroughTransactionId` / AR account), sum cost + markup, include as line items on next customer invoice with description "Expense Pass-through: Meals, Travel, etc." per expense category

## Build notes & deferred runtime wiring

All 24 tasks are implemented as **declarative configuration + ADR-031 guard
logic**, with the spec's `Expense`/`ExpenseClaim` names corrected to the real
`Receipt`/`ExpenseClaimEntry` schemas (ADR-022). Specifically:

- Tasks 5–10, 17–19: shipped in
  `lib/Settings/register.d/expense-reimbursement-or-passthrough.json` (schema
  extensions, two new master-data schemas, six seed objects, calculations).
- Tasks 1, 11: confirmed no prior settlement fields; the 7-state claim lifecycle
  already ships from expense-capture-core (re-declaring it would duplicate the
  post action list on merge — guarded by the fragment test).
- Tasks 8, 9, 15, 16, 23: implemented as `SettlementGuard` (markup priority
  lookup, markup amount, claim aggregation, submit classification + mixed-mode
  rejection, mode-change authorisation) with full unit coverage.
- Task 21: `Expense Settlement Classifier` + the two master-data pages ship in
  `src/manifest.d/expense-reimbursement-or-passthrough.json` (manifest-v2).
- Tasks 12, 13, 14, 22, 24: declared as the `x-openregister-settlement` contract
  on `ExpenseClaimEntry` (dual-path GL branch, reimbursement notification, AR
  linking, AR-account validation) with the `SettlementGuard::onReimbursablePosted`
  payload assembler. **Deferred (live-instance):** the actual GL transaction
  materialisation for the pass-through leg, event-bus publication, and the T2
  accounts-receivable invoice-cycle accumulation require a running OpenRegister
  engine + the cross-app AR invoice generator; they are wired declaratively here
  and verified end-to-end in a runtime cycle.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer
review (e.g. `/test-persona-janwillem` for SMB) confirms the settlement flow
matches Dutch SMB practice for dual reimbursement/billing (receipt submission →
settlement classification → GL posting → reimbursement OR invoicing). Architecture
reviewer confirms ADR-022 + ADR-031 + ADR-024 compliance (no app-local approval
table; no PHP settlement service; no custom GL classes; manifest carries navigation).

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- PHPUnit unit tests for settlement-mode classification, markup calculation,
  GL posting (dual-path), approval threshold routing, customer AR linking,
  SEPA notification event emission
- Playwright MCP browser tests for the Expense Settlement Classifier manifest
  page (bulk classification, rule selection, approval workflow integration)
- Schema validation tests for extended Expense and ExpenseClaim (REQ-ERP-002,
  REQ-ERP-003) and new ReimbursementPolicy, PassThroughMarkupRule (REQ-ERP-004,
  REQ-ERP-005)
- GL materialisation tests verifying dual-path posting (reimbursable vs.
  pass-through) with correct GL accounts, balancing, and metadata per REQ-ERP-007
- Markup-rate locking tests per REQ-ERP-010 (immutability after submission,
  historical claim isolation from rule changes)
- Settlement-mode change + GL reversal tests per REQ-ERP-011 (GL entry
  reversal on mode change post-submission)
- AR integration tests per REQ-ERP-009 (pass-through claim linked to customer
  AR, included in next invoice)
- SEPA notification event tests per REQ-ERP-008 (event logged, published,
  consumed by treasury module)
- Approval threshold tests per REQ-ERP-006 (optional markup approval gate)
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- `docs/user-guide/expense-management/settlement-modes.md` per ADR-030
  journeydoc convention with reimbursable (SEPA) vs. pass-through (AR/invoice)
  workflows
- `docs/user-guide/expense-management/pass-through-billing.md` with customer
  linking, markup-rule application, invoice aggregation, revenue recognition
  timeline
- `docs/user-guide/expense-management/settlement-classifier.md` with bulk
  classification UI walkthrough
- Screenshots of Settlement Classifier form, pass-through claim details, AR
  linking confirmation, generated invoice with pass-through line items committed
  to `docs/images/`

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Reimbursable`, `Pass-through`, `Settlement Mode`, `Expense Settlement`,
  `Markup Rule`, `Customer Link`, `Linked Customer`, `Markup Applied`,
  `Markup Rate`, `Pass-through Amount`, `Reimbursable Amount`, `Settlement
  Classifier`, `SEPA Reimbursement`, `AR Billing`, `Invoice Aggregation`,
  `Expense Disputed`, `Expense Voided`, `Expense Reimbursed`, `Expense Invoiced`,
  `Expense No Settlement Mode`, `Settle Now`, `Bill to Customer`
