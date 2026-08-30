# Design — Expense Reimbursement or Pass-through

## Context

Service firms and consulting teams routinely charge clients for employee expenses
(mileage, meals, travel) at cost + markup. Shillinq's bookkeeping model supports
two settlement patterns:

1. **Reimbursable**: Employee submits expense; company reimburses via SEPA
   transfer; GL posts to expense-payable account; cost centre-allocated.
2. **Pass-through**: Employee submits expense; company bills customer at cost +
   markup; GL posts to customer AR; invoice issued; revenue recognised on
   invoice date.

Per ADR-022, approval routing comes from OR. Per ADR-031, settlement classification
and markup calculation are declarative; no PHP expense-settlement service.

This change extends existing `Expense` and `ExpenseClaim` schemas + adds
master-data configuration for policies and markup rules, locking the dual-path
decision into the spec.

## Goals

- Express settlement mode (reimbursable vs. pass-through) as **declarative
  metadata** — enums + lifecycle states + calculations per ADR-031.
- Consume OR's approval-workflow abstraction for optional markup-approval
  thresholds per ADR-022.
- Make the spec a **bookkeeper-readable contract** — expense inlet → settlement
  decision → GL posting (reimbursable) OR AR accumulation (pass-through) →
  payment OR invoice.
- Support dual-path GL materialisation — one GL entry per posted claim,
  either to reimbursement-payable (debit) or customer AR (debit).
- Keep the spec open for future revenue-recognition policy and multi-currency
  markup rules without destructive migration.

## Non-Goals

- No PHP expense-settlement service, no `SettlementService.php`.
- No SEPA file generation — treasury module consumes notification event
  downstream.
- No revenue-recognition policy or deduction planning — T3+ enhancement.
- No multi-customer cost splitting — deferred to T3 time & materials phase.
- No customer AR dunning or collection workflow — existing T2 accounts-receivable
  feature covers.

## Decisions

### D1 — Settlement mode is an enum field on `Expense` and `ExpenseClaim`

A `ReimbursementMode` enum (`reimbursable | pass-through`) is added to both
schemas. At submission time, operator selects the mode (possibly constrained by
policy). GL materialisation branches on this field.

**Alternative considered**: A separate `SettlementRecord` table linking to Expense.
Rejected — settlement decision is made at expense-claim time, not post-hoc; a
single enum field is cleaner and immutable for audit.

### D2 — Settlement classification MAY require approval if pass-through exceeds markup threshold

A `ReimbursementPolicy` master-data schema optionally enables separate approvers
for pass-through claims above a markup threshold (e.g., approver required if
markup ≥ €100 or ≥ 20%). Consumed via OR approval-workflow per ADR-022.

**Alternative considered**: Always auto-approve settlement classification. Rejected
— pass-through markup can be material; explicit approver sign-off is prudent for
audit trail.

### D3 — Pass-through cost + markup is stored on Expense for GL posting

When an expense is marked pass-through with a markup rule applied, the
`Expense.passThrough RateApplied` and `markupAmount` are calculated and stored
at claim submission. The GL posting line for AR then carries cost + markup as a
single debit line to customer AR.

**Alternative considered**: Store cost and markup separately; calculate sum at
posting time. Rejected — GL entry must be immutable once posted; storing
pre-calculated amounts ensures audit trail clarity and prevents rate-change
artifacts.

### D4 — Reimbursable path GL posts to expense-payable account; pass-through path to customer AR

For reimbursable claims: GL entry debits expense-payable account (liabilit y),
credits cost-centre expense accounts per line.

For pass-through claims: GL entry debits customer AR (receivable/asset),
credits revenue-deferral account (liability) — operator invoices separately per
existing AR workflow.

**Alternative considered**: Post both paths to the same expense account, then
offset with a customer charge. Rejected — AR needs the customer linkage in the
GL line for reconciliation; dual posting is cleaner.

### D5 — Markup rule is a master-data lookup per customer + category + fallback

A `PassThroughMarkupRule` schema defines:
- **Target**: customer ID (or null = global default), expense category (or null),
  or both.
- **Rate**: fixed percentage (e.g., 15%) or fixed amount per unit.
- **Effective dates**: per fiscal year or date range.

Operator selects a customer at claim submission; system looks up matching rule
(priority: customer + category > customer only > global default).

**Alternative considered**: Fixed per-claim markup override. Rejected — policies
should be admin-configured, not operator-chosen, to prevent audit drift and
fraud.

### D6 — SEPA payment instruction is a notification event, not file generation

On `ExpenseClaim.post` (reimbursable mode), the GL entry is materialised + a
notification event is emitted: "Reimburse employee €X to bank account Y Z". The
treasury module consumes the event and generates SEPA/ACH/wire files per
deployment choice. Shillinq is decoupled from payment infrastructure.

**Alternative considered**: Generate SEPA file directly in Shillinq. Rejected —
SEPA is deployment-specific (some use bank APIs, others use batch files); event
is the right abstraction per event-driven architecture.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Expense schema | `Expense` from expense-capture-core | Extend with `settlementMode`, `linkedCustomerId`, `markupRuleId`, `passThroug hAmountCalculated` |
| Expense claim schema | `ExpenseClaim` from expense-capture-core | Extend with `settlementMode`, `totalPassThroughAmount`, `totalReimbursableAmount` aggregates |
| Settlement-mode policy config | None (new) | Master-data `ReimbursementPolicy` schema; operator selects at claim submission (or per-expense policy auto-assigns) |
| Markup calculation | None (new) | Master-data `PassThroughMarkupRule` schema; calculation field on Expense: `markupAmount = cost × (1 + ratePercent)` |
| Settlement approval (if threshold) | OR approval-workflow (ADR-022) | Optional `x-openregister-lifecycle.requires` on `submitted → approved` transition IF markup > threshold; consumed via OR UI |
| GL reimbursable posting | T1 `JournalEntry` materialisation pattern (REQ-JE-007) | Same GL-posting action; emits one balanced GL entry (debit expense-payable, credit cost centres) |
| GL pass-through posting | T2 accounts-receivable GL pattern | Same AR posting logic; debit customer AR, credit deferred-revenue account |
| SEPA payment trigger | Treasury module (async notification) | Emit event on `ExpenseClaim.post` (reimbursable); treasury consumes via event-bus |
| Audit trail | T2 `bookkeeping-audit-trail` (OR audit-trail-immutable) | Automatic on lifecycle transitions; markup rate locked on GL entry |
| Customer linkage | T2 accounts-receivable (customer master) | FK `linkedCustomerId` to `Organization` (customer); validated at claim submission |

**Net new code in implementation cycle**: 2 schema extensions (Expense,
ExpenseClaim) + 2 new schemas (ReimbursementPolicy, PassThroughMarkupRule) +
2 calculation fields + 1 manifest page. No PHP service classes (per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Settlement mode classification | Declarative (enum field on Expense + ExpenseClaim) | Pure state machine; no logic |
| Settlement approval routing (optional) | Consumed from OR approval-workflow | ADR-022; optional per policy |
| Markup calculation | Declarative (x-openregister-calculations) | Pure lookup + multiply; no state |
| GL posting (dual-path) | Lifecycle action invoking T1/T2 patterns | Materialisation is well-defined; no custom logic |
| SEPA notification | Event emission on claim posting | Fire-and-forget; no polling or state |
| Customer AR linking | Validation at submission | Check that customer record exists; no service logic |

No service class authored (per ADR-031).

## Seed Data

Two master-data tables:

**ReimbursementPolicy** (per administration):
```
| policyId | name | description | autoApproveThreshold | requiresMarkupApprovalThreshold | employeeBankAccountMapping | notes |
| POL-NL-01 | Netherlands Standard | Standard Dutch expense policy | 500.00 | null | standard | Expenses ≤€500 auto-approve |
| POL-NL-02 | Netherlands High-Touch | For complex multi-customer claims | 100.00 | 0.15 | standard | Lower threshold; markup ≥15% requires approval |
```

**PassThroughMarkupRule** (per administration):
```
| ruleId | targetCustomerId | targetCategory | markupType | markupValue | currency | effectiveFromYear | notes |
| RULE-001 | null | null | percentage | 0.15 | EUR | 2026 | Global default: +15% on all pass-through |
| RULE-002 | CUST-123 | travel | percentage | 0.10 | EUR | 2026 | Customer ABC: travel at +10% (negotiated rate) |
| RULE-003 | CUST-456 | null | percentage | 0.20 | EUR | 2026 | Customer XYZ: all categories +20% (VIP client) |
| RULE-004 | null | meals | fixed | 2.50 | EUR | 2026 | Meals: flat €2.50 markup per receipt |
```

Operators maintain these per fiscal year; rules lock at claim submission for
audit immutability.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Customer AR record missing at claim submission | System validates before post; operator prompted if customer not found or AR account not configured per policy |
| Markup approval threshold creates approval bottleneck | Policy-configurable; admin can raise threshold or disable if workflow too strict |
| SEPA notification lost or not consumed by treasury | Event logged in audit trail; operator can manually trigger SEPA generation if event delivery fails |
| Multi-currency pass-through markup complexity | T2 applies single EUR markup; T3+ adds per-currency rules; no breaking change |
| GL entry posted with wrong path (reimbursable vs. pass-through) | Settlement mode immutable at submission; operator warned if changing mode post-posting would reverse GL entry |
| Customer contract renewal changes markup mid-year | Rule change applies only to new claims; historical claims retain locked rate on GL entry |

## Out-of-Scope Decisions (T3+)

- **Revenue recognition**: When to recognize income (invoice date vs. cash
  receipt vs. delivery)? Per IFRS 15 or Dutch SMB simplification? Deferred.
- **Multi-customer cost splitting**: One expense benefiting two customers;
  how to allocate cost + markup? Deferred.
- **Multi-currency markup rules**: Different markups per currency or customer
  jurisdiction? Deferred.
- **Time & materials billing**: Integration with timesheet expenses (hourly
  labor). Deferred.
- **Pass-through analytics**: Revenue per customer per category per fiscal
  period. Deferred to T3+ reporting.
