# Proposal: expense-reimbursement-or-passthrough

`kind: config` per ADR-032 — declarative configuration for the
expense reimbursement decision point: mark expenses reimbursable (pay employee
via SEPA transfer) or pass-through (bill to client invoice at cost + markup).
Extends `Expense` and `ExpenseClaim` with settlement-mode classification,
FX markup handling, and pass-through billing integration.

## Summary

Introduce the **expense reimbursement or pass-through** capability for Shillinq,
allowing finance operators to classify captured expenses as either:

1. **Reimbursable** — employee expense reimbursed via SEPA direct transfer to
   employee bank account + cost-centre GL posting.
2. **Pass-through** — expense cost passed to client invoice at cost + configurable
   markup percentage (e.g., +15% handling fee), linked to customer AR record for
   downstream billing.

This change declares two classification enums (`ReimbursementMode` and
`PassThroughMarkupRule`), extends the `Expense` and `ExpenseClaim` schemas with
settlement metadata, and integrates with the T1 general ledger (reimbursable path)
and T2 accounts-receivable (pass-through path) for dual-mode posting.

The capability materialises one GL entry per posted expense — either to
employee-reimbursement payable account or to client AR/deferred-revenue account,
per the classification. Mileage and per-diem reimbursements follow the same
dual-path logic.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure.

**Depends on:** [`expense-capture-core`](../expense-capture-core/proposal.md)
(captures Receipt, MileageEntry, PerDiem, ExpenseClaimEntry),
[`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(GL posting for reimbursable path),
[`add-shillinq-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md)
(AR posting for pass-through path).

## Motivation

Market intelligence spans 11 of 26 competitors offering dual-mode expense handling:
bigtime (Reimbursement and pass-through), replicon (Reimbursement + pass-through),
exact-online-hrm, loket, pivot-hr, adp-nl, bezala, rippling, yuki, and others.
Service firms, consulting teams, and contractor-management platforms routinely
charge clients for employee expenses (mileage, meals, travel) at cost + markup.

Shillinq's dual-entity model (employee reimbursement vs. customer billing) requires
that a single captured expense can flow either direction:
- **Reimbursement path**: Post to expense-payable GL account, trigger SEPA payment
  instruction, mark employee as paid.
- **Pass-through path**: Accumulate on customer AR, include in next invoice at cost +
  markup, recognise revenue on invoice date.

The legacy expense-management cluster from intelligence-db calls out settlement-mode
classification, multi-client billing allocation, and per-expense markup rules as
standard. This is a T2 capability, deferring T3+ enhancements (e.g., revenue
recognition policy per IFRS 15, multi-currency pass-through markup rules, time &
materials billing integration).

## Affected Projects

- [x] Project: shillinq — extends 2 existing schemas (`Expense`, `ExpenseClaim`)
  with `settlementMode`, `passThrough*` fields; adds 2 new master-data schemas
  (`ReimbursementPolicy`, `PassThroughMarkupRule`) with lifecycle and calculations;
  adds 1 manifest form page (Expense Settlement Mode) for bulk classification.
- [ ] Project: openregister — no source changes; consumes existing GL posting
  pattern (T1 REQ-JE-007), AR posting pattern (T2 accounts-receivable), approval
  workflow (ADR-022).
- [ ] Project: nc-vue — uses standard Form and Selector components for
  settlement-mode classification and customer/markup selection.

## Scope

### In Scope

- Two new master-data schemas (`ReimbursementPolicy`, `PassThroughMarkupRule`)
  for configuring policies per administration.
- Extension of `Expense` schema with `settlementMode`, `linkedCustomerId`,
  `markupRuleId`, `isReimbursable`, `passThroug hRate` fields.
- Extension of `ExpenseClaim` schema with `settlementMode`, `passThrough*`
  aggregate fields, and dual-path lifecycle states.
- Enum types: `ReimbursementMode` (reimbursable | pass-through) and
  `SettlementTrigger` (on-approval | on-posting | on-invoice).
- **Reimbursable path**: GL posting to expense-payable account + SEPA payment
  instruction generation (notification to treasury module, not payment execution).
- **Pass-through path**: Linking to customer AR + accumulation on next invoice
  at cost + markup; revenue recognition on invoice date.
- Materialisation: on `ExpenseClaim.post`, one balanced GL entry — either debit
  to reimbursement-payable or debit to customer AR per mode.
- Approval workflow: settlement classification MAY require separate approver if
  pass-through markup exceeds threshold (configurable per policy).
- Bulk classification UI: finance operator marks multiple expenses in one form
  (settle now vs. bill to customer X at +Y%).

### Out of Scope

- **Payment execution** — SEPA file generation is downstream (treasury module).
- **Revenue recognition policy** — T3+ enhancement; T2 carries cost + markup,
  not accounting policy.
- **Multi-currency markup rules** — T2 applies single markup; T3+ adds
  per-currency/per-customer rules.
- **Time & materials billing** — future phase; T2 is expense-only.
- **Advanced pass-through analytics** — cost tracking per customer per category;
  deferred to T3+ reporting.
- **Customer AR dunning** — existing T2 accounts-receivable feature covers
  invoice collection; pass-through invoices follow standard AR lifecycle.

## Approach

One delta, adding EXTENDED Requirements to existing `Expense` and `ExpenseClaim`
schemas + new `ReimbursementPolicy` and `PassThroughMarkupRule` schemas:

1. **Extension to `Expense`** — adds settlement metadata (`settlementMode`,
   `linkedCustomerId`, `markupRuleId`).
2. **Extension to `ExpenseClaim`** — adds dual-mode state tracking and aggregate
   pass-through totals.
3. **New `ReimbursementPolicy`** — master data configuring which settlements
   require approval, thresholds, bank-account mapping for SEPA.
4. **New `PassThroughMarkupRule`** — master data defining markup policies per
   customer, category, or global default.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-ERP-*`
for traceability.

## New Dependencies

- **expense-capture-core** — for upstream `Expense`, `ExpenseClaim`,
  `Receipt`, `MileageEntry`, `PerDiem` schemas.
- **general-ledger (T1)** — for GL posting patterns and expense-payable
  accounts (reimbursable path).
- **accounts-receivable-core (T2)** — for AR posting patterns and customer
  link.
- **approval-workflow (ADR-022)** — optionally for pass-through markup
  approval thresholds.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 new schemas
  (`ReimbursementPolicy`, `PassThroughMarkupRule`); extends `Expense` and
  `ExpenseClaim` with settlement fields; declares calculations on
  `PassThroughMarkupRule.appliedMarkupAmount` and
  `ExpenseClaim.totalPassThroughAmount`.
- `src/manifest.json` — adds 1 new page (`Expense Settlement Classifier`)
  for bulk classification.
- No new PHP services (per ADR-031); settlement decision is declarative or
  consumes existing GL/AR extensions.
- Standard Vue Form components for settlement-mode selection and customer/markup picker.

## Cross-Project Dependencies

- **OpenRegister** — depends on approval-workflow (ADR-022 optional),
  `x-openregister-lifecycle` (ADR-031), `x-openregister-calculations` (ADR-031).
- **T1 general-ledger** — depends on the GL-posting pattern (REQ-JE-007)
  for reimbursable expense posting.
- **T2 accounts-receivable** — depends on AR schema and posting pattern for
  pass-through customer billing.
- **multi-currency** — leverages existing FX handling from expense-capture-core;
  pass-through markup is applied in base currency.

## Risks

### Risk 1: Pass-through markup calculations must be immutable for audit

**Severity**: Medium
**Mitigation**: Once a claim is posted, the applied markup rate and amount are
locked on the GL entry and AR record. If a markup rule is changed (e.g., customer
contract renewal), only future claims use the new rate; historical claims retain
their locked rate.

### Risk 2: Customer AR linking may be missing or incorrect

**Severity**: Medium
**Mitigation**: At claim submission, system validates that pass-through claims
are linked to a valid customer AR record. Operator prompted if customer not found.
Spec carries a `linkedCustomerId` field; AR posting is skipped if field is null
(claim treated as reimbursable fallback).

### Risk 3: SEPA payment instruction generation requires treasury module coordination

**Severity**: Low
**Mitigation**: T2 generates a notification event ("expense claim EXP-2026-0001
approved; generate SEPA EUR 500 to employee account NL12ABCD..."). Treasury module
consumes the event; Shillinq is neutral to payment file format. Integration point
is defined in spec but execution is asynchronous.

### Risk 4: Markup approval threshold policy may conflict with expense approval

**Severity**: Low
**Mitigation**: Separate approval chain can be configured (e.g., expense ≤€1000
auto-approve; pass-through ≥€2000 + markup ≥15% requires manager approval).
Spec defines optional `requiresMarkupApproval` flag per rule; logic is
declarative per ADR-022.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no
runtime impact. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR; extended schemas are non-destructive
— expenses remain queryable but unsettled.

## Open Questions

1. **SEPA generation source** — see Risk 3; resolved in `opsx-ff` discovery
   against treasury module's notification contract.
2. **Customer AR account mapping** — which GL accounts (customer deferred revenue
   vs. accounts-receivable vs. accrued expense)? Resolved during implementing cycle
   per Dutch chart-of-accounts RGS conventions.
3. **Multi-customer allocation** — if one expense benefits two customers
   (e.g., shared meal), split cost + markup or round-robin? Deferred to T3.
4. **Pass-through revenue recognition** — on invoice date or cash collection?
   Per IFRS 15 or Dutch SMB simplification? Deferred to T3+ accounting-policy phase.
