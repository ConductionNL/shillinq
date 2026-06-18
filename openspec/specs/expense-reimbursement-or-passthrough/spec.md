---
status: done
---

# Spec: expense-reimbursement-or-passthrough

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../expense-capture-core/specs/expense-capture-core/spec.md` (Expense, ExpenseClaim, Receipt, MileageEntry, PerDiem),
`../add-shillinq-general-ledger/specs/bookkeeping-general-ledger/spec.md` (T1 GL posting),
`../add-shillinq-accounts-receivable-core/specs/accounts-receivable-core/spec.md` (T2 AR posting)

## Purpose

This specification defines the requirements for expense reimbursement or passthrough in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/schema: expense reimbursement flow — not browser-testable


### REQ-ERP-001: Settlement mode (reimbursable or pass-through) SHALL be an enum field on Expense and ExpenseClaim

`Expense` and `ExpenseClaim` MUST declare a `settlementMode` enum field with
values `reimbursable | pass-through`. The field is immutable after claim
submission. Per ADR-031, the enum is purely declarative; no PHP service logic.

#### Scenario: Expense is marked reimbursable

- **GIVEN** an `Expense` for travel mileage €50.00
- **WHEN** the operator selects `settlementMode: reimbursable`
- **THEN** the field is set; claim post will trigger reimbursement GL posting +
  SEPA notification.

#### Scenario: Expense is marked pass-through

- **GIVEN** an `Expense` for a meal €35.00 for a client project
- **WHEN** the operator selects `settlementMode: pass-through` and links to
  customer CUST-123
- **THEN** the field is set; claim post will trigger AR posting to customer
  account.

#### Scenario: Settlement mode is immutable after submission

- **GIVEN** an `ExpenseClaim` in state `submitted`
- **WHEN** operator attempts to change `settlementMode` from `reimbursable` to
  `pass-through`
- **THEN** validation MUST fail with a "settlement mode is immutable after
  submission" error.

### REQ-ERP-002: Expense schema MUST carry pass-through linking and markup fields

The `Expense` schema MUST declare the following new fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `settlementMode` | enum | Yes | One of: `reimbursable`, `pass-through` |
| `linkedCustomerId` | string | No | FK to Organization (customer) if pass-through; null if reimbursable |
| `markupRuleId` | string | No | FK to PassThroughMarkupRule master table; populated if pass-through |
| `markupRateApplied` | number | No | The actual markup percentage applied (e.g., 0.15 for 15%) |
| `markupAmountCalculated` | number | No | Auto-calculated: `amount × (1 + markupRateApplied)` |
| `passthrough DebitAccountCode` | string | No | GL account code for pass-through (customer AR or deferred revenue) |

Schema.org annotation: remains `schema:Invoice` (per existing Expense definition in
ADR-000).

#### Scenario: Pass-through expense has cost + markup calculated

- **GIVEN** the schema and markup rule: 15% for customer CUST-123
- **WHEN** an `Expense` with `{amount: 100.00, settlementMode: "pass-through", linkedCustomerId: "CUST-123"}` is saved
- **THEN** system looks up `PassThroughMarkupRule` for CUST-123; sets
  `markupRateApplied: 0.15`; calculates
  `markupAmountCalculated: 100.00 × 1.15 = 115.00`.

#### Scenario: Customer AR account is required for pass-through

- **GIVEN** a pass-through expense with missing `passthrough DebitAccountCode`
- **WHEN** operator attempts to submit the claim
- **THEN** validation MUST fail with a "AR account not configured for this
  customer or settlement policy" error.

### REQ-ERP-003: ExpenseClaim schema MUST declare settlement aggregates and dual-path state tracking

The `ExpenseClaim` schema MUST be extended with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `settlementMode` | enum | Yes | Claim-level mode; all line expenses MUST match or null (mixed mode not allowed) |
| `totalReimbursableAmount` | number | No | Sum of all Expense.amount where settlementMode = `reimbursable` |
| `totalPassThroughAmount` | number | No | Sum of all Expense.markupAmountCalculated where settlementMode = `pass-through` |
| `passthrough CustomerIds` | array | No | Unique list of customers in pass-through lines |
| `glReimbursableTransactionId` | string | No | Back-reference to materialised GL entry (reimbursable path) once posted |
| `glPassThroughTransactionId` | string | No | Back-reference to materialised GL entry (pass-through path) once posted |

#### Scenario: Claim auto-aggregates pass-through totals

- **GIVEN** a claim with:
  - Reimbursable receipt: €50.00
  - Pass-through meal (CUST-123, +15%): €35 × 1.15 = €40.25
  - Pass-through mileage (CUST-456, +10%): €100 × 1.10 = €110.00
- **WHEN** the claim is created
- **THEN** `totalReimbursableAmount = 50.00`; `totalPassThroughAmount = 40.25 +
  110.00 = 150.25`.

#### Scenario: Mixed-mode claim is rejected

- **GIVEN** a claim with `settlementMode: null` (mixed reimbursable and
  pass-through)
- **WHEN** operator attempts to submit
- **THEN** validation MUST fail with a "claim must have a single settlement mode"
  error.

### REQ-ERP-004: ReimbursementPolicy master data MUST define per-administration settlement rules

A new `ReimbursementPolicy` schema SHALL declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `policyId` | string | Yes | Unique policy identifier per administration |
| `name` | string | Yes | Human-readable policy name |
| `description` | string | No | Policy description and scope |
| `autoApproveThreshold` | number | No | Expense claims ≤ this amount auto-approve (e.g., €500) |
| `requiresMarkupApprovalThreshold` | number | No | Pass-through markup amounts ≥ this amount require extra approver |
| `employeeBankAccountMapping` | string | No | Reference to default SEPA account for reimbursement (or "standard") |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Thing`.

#### Scenario: Policy defines auto-approval threshold

- **GIVEN** policy "autoApproveThreshold: 500.00"
- **WHEN** a reimbursable claim totaling €350 is submitted
- **THEN** claim MUST transition `draft → approved` directly with
  `approvalState: not-required`.

#### Scenario: Markup approval threshold triggers extra gate

- **GIVEN** policy "requiresMarkupApprovalThreshold: 100.00" and claim with
  pass-through markup of €150
- **WHEN** the claim is submitted
- **THEN** approval workflow MUST include a second approver gate (e.g., manager
  sign-off on markup).

### REQ-ERP-005: PassThroughMarkupRule master data MUST define per-customer / per-category rates

A new `PassThroughMarkupRule` schema SHALL declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `ruleId` | string | Yes | Unique rule identifier |
| `targetCustomerId` | string | No | FK to Organization (customer); null = global default |
| `targetCategory` | string | No | Expense category (travel, meals, etc.); null = all categories |
| `markupType` | enum | Yes | One of: `percentage`, `fixedAmount` |
| `markupValue` | number | Yes | Markup as % (e.g., 0.15) or EUR amount (e.g., 2.50) |
| `currency` | string | Yes | ISO 4217 (EUR) |
| `effectiveFromYear` | integer | Yes | Fiscal year this rule applies to (e.g., 2026) |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Offer`.

**Priority**: Rules are matched in order: (customer + category) > (customer only)
> (global default).

#### Scenario: Rule matches customer + category

- **GIVEN** rules:
  - Rule A: customer=CUST-123, category=null, markup=15%
  - Rule B: customer=CUST-123, category=travel, markup=10%
  - Rule C: customer=null, category=null, markup=5% (global)
- **WHEN** an expense is marked pass-through for CUST-123 with category=travel
- **THEN** Rule B MUST be matched; markup=10% applied.

#### Scenario: Percentage vs. fixed-amount rules

- **GIVEN** two rules:
  - Rule X: markupType=percentage, markupValue=0.15 (15%)
  - Rule Y: markupType=fixedAmount, markupValue=2.50 (EUR 2.50 per item)
- **WHEN** an operator applies Rule X to €100 expense
- **THEN** final amount = €100 × 1.15 = €115.00
- **WHEN** an operator applies Rule Y to 1 meal receipt
- **THEN** final amount = cost + €2.50.

### REQ-ERP-006: Settlement approval policy MAY require markup sign-off if threshold exceeded

The system SHALL satisfy this requirement: Settlement approval policy MAY require markup sign-off if threshold exceeded.

If `ReimbursementPolicy.requiresMarkupApprovalThreshold` is set, the
`ExpenseClaim` lifecycle MUST consume OR's approval-workflow extension with an
additional gate on the `submitted → approved` transition when markup amount
exceeds threshold.

Per ADR-022, no app-local approval table. Markup approval is optional and
configured through OR UI.

#### Scenario: Below-threshold pass-through auto-approves

- **GIVEN** policy "requiresMarkupApprovalThreshold: 500.00" and claim with
  total pass-through markup €200
- **WHEN** the claim is submitted
- **THEN** markup approval is not required; claim proceeds to standard approval
  gate.

#### Scenario: Above-threshold pass-through requires markup approver

- **GIVEN** policy "requiresMarkupApprovalThreshold: 500.00" and claim with
  total pass-through markup €600
- **WHEN** the claim is submitted
- **THEN** an extra approver (e.g., finance manager) MUST sign off on markup
  before claim can proceed to payment/invoicing.

### REQ-ERP-007: GL materialisation MUST emit one balanced entry per claim on post, branching by settlement mode

The system SHALL satisfy this requirement: GL materialisation MUST emit one balanced entry per claim on post, branching by settlement mode.

When an `ExpenseClaim` transitions to `posted`:

**Reimbursable path**:
- Debit: expense-payable account (GL expense-control account for reimbursable
  mode); one line per cost centre
- Credit: cost-centre expense GL accounts per line item

**Pass-through path**:
- Debit: customer AR account (GL account per policy) or deferred-revenue account
- Credit: cost-centre expense GL accounts per line item

Per T1 REQ-JE-007, the GL entry is balanced and immutable; audit trail records
the settlement mode on the entry.

#### Scenario: Reimbursable claim materialises GL entry

- **GIVEN** a reimbursable `ExpenseClaim` for €500 (2 cost centres: €300 + €200)
  in `approved` state
- **WHEN** the operator posts it
- **THEN** GL entry MUST be created:
  - Debit: expense-payable account €500
  - Credit: cost-centre-A €300 + cost-centre-B €200

#### Scenario: Pass-through claim materialises AR GL entry

- **GIVEN** a pass-through `ExpenseClaim` for customer CUST-123, total €115
  (€100 cost + €15 markup)
- **WHEN** the operator posts it
- **THEN** GL entry MUST be created:
  - Debit: customer AR account €115
  - Credit: cost-centre-A €100 + revenue-deferral €15

#### Scenario: GL entry is immutable; settlement mode is recorded

- **GIVEN** a posted claim with `glReimbursableTransactionId` or
  `glPassThroughTransactionId`
- **WHEN** the GL entry is inspected
- **THEN** the entry MUST carry metadata (e.g., in description or custom field)
  identifying settlement mode + claim ID for traceability.

### REQ-ERP-008: Reimbursable claim SHALL trigger SEPA payment notification event on post

On `ExpenseClaim.post` (reimbursable mode), a notification event MUST be emitted:

```
Event: ExpenseClaimReimbursementNotification
Payload: {
  claimId: "EXP-2026-0001",
  employeeId: "EMP-123",
  employeeName: "Jan Jansen",
  employeeBankAccount: "NL12ABCD0123456789",
  amount: 500.00,
  currency: "EUR",
  glEntryId: "GL-2026-001234",
  policyId: "POL-NL-01",
  metadata: { ... }
}
```

The treasury module consumes the event and generates SEPA/ACH/wire files per
deployment choice. Shillinq is neutral to payment infrastructure.

#### Scenario: Reimbursement event is logged and forwarded

- **GIVEN** a posted reimbursable claim for €500 to employee NL12ABCD...
- **WHEN** the claim transitions to `posted`
- **THEN** event `ExpenseClaimReimbursementNotification` MUST be logged in the
  audit trail AND published to the event bus (for treasury module consumption).

#### Scenario: Event missing does not block claim posting

- **GIVEN** event-bus temporarily unavailable
- **WHEN** claim is posted
- **THEN** claim MUST still transition to `posted`; event is retried asynchronously
  (per company-wide event retry policy).

### REQ-ERP-009: Pass-through claim SHALL link to customer AR on post

On `ExpenseClaim.post` (pass-through mode), the claim's AR debit entry MUST be
linked to the customer AR record. On the next invoice cycle, the claim's
cost + markup are accumulated on the customer's AR and included in the next
customer invoice.

#### Scenario: Pass-through claim is accumulated on customer AR

- **GIVEN** a pass-through claim for CUST-123, total €115
- **WHEN** the claim is posted
- **THEN** the GL debit entry links to customer CUST-123's AR record;
- **WHEN** next month's invoice is generated for CUST-123
- **THEN** the €115 (cost + markup) MUST be included in the invoice line items.

#### Scenario: Multiple pass-through customers in one claim

- **GIVEN** a claim with line items for CUST-123 (€40) and CUST-456 (€110)
- **WHEN** the claim is posted
- **THEN** TWO GL entries are created: one for CUST-123 AR, one for CUST-456 AR.

### REQ-ERP-010: Markup rate MUST be locked at claim submission for audit immutability

The system SHALL satisfy this requirement: Markup rate MUST be locked at claim submission for audit immutability.

Once a claim is submitted (or posted), the `markupRateApplied` and
`markupAmountCalculated` fields on each `Expense` MUST be locked and immutable.
Future changes to `PassThroughMarkupRule` do NOT affect historical claims.

#### Scenario: Markup rule change applies only to new claims

- **GIVEN** existing rule: customer CUST-123, markup 15%
- **WHEN** operator submits claim with CUST-123 expense
- **THEN** claim locks in 15% markup
- **WHEN** admin later updates rule to 20%
- **THEN** the historical claim retains 15%; new claims use 20%.

#### Scenario: GL entry carries locked markup for traceability

- **GIVEN** a posted pass-through claim
- **WHEN** the GL entry is inspected
- **THEN** entry metadata MUST record the applied markup rate and the
  `PassThroughMarkupRule` ID for audit trail clarity.

### REQ-ERP-011: Settlement mode change post-submission SHALL require GL reversal

The system SHALL satisfy this requirement: Settlement mode change post-submission SHALL require GL reversal.

If an operator (with high privilege) changes `settlementMode` after a claim is
submitted, the existing GL entry MUST be reversed per T1 REQ-GL-004 before a new
GL entry is created with the updated mode.

#### Scenario: Reversing GL entry on settlement-mode change

- **GIVEN** a posted reimbursable claim with GL entry GL-123
- **WHEN** operator changes settlement mode to pass-through and re-posts
- **THEN** GL entry GL-123 MUST be reversed (credit/debit reversed); new GL
  entry GL-124 created for pass-through AR posting.

## EXTENDED Lifecycle

`ExpenseClaim` lifecycle states are extended to track dual-path settlement:

| From | To | Trigger | Guard | GL Action |
|---|---|---|---|---|
| `draft` | `submitted` | operator submit | all items have cost centre + settlementMode | none |
| `submitted` | `approved` | approver action | REQ-EC-006 approval-workflow + REQ-ERP-006 (optional markup approval) | none |
| `submitted` | `rejected` | approver action | claim returned to draft | GL reversal if previously posted |
| `approved` | `posted` | operator post | settlementMode determines path | Reimbursable: GL to exp-payable + SEPA notification; Pass-through: GL to customer AR |
| `posted` | `reimbursed` (reimbursable) | payment record | reimbursement amount ≥ claim total | GL entry immutable; SEPA instruction confirmed |
| `posted` | `invoiced` (pass-through) | invoice generation | invoice includes claim cost + markup | GL entry immutable; AR updated |
| `posted` | `disputed` | operator action | payment/invoice hold | GL entry remains valid; investigation note logged |
| `disputed` | `posted` | resolution | GL entry stands | none |
| `posted` | `voided` | operator action | GL entry MUST be reversed per T1 REQ-GL-004 | GL reversal |

Paths:
- **Reimbursable**: draft → submitted → approved → posted → reimbursed
- **Pass-through**: draft → submitted → approved → posted → invoiced

Both paths allow: submitted → rejected (return to draft); posted → disputed (hold)
→ posted (resolved) or voided (reverse GL).
