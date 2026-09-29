---
kind: code
depends_on: [background-job-consolidation]
---

# Proposal: receivables-payment-plans

## Summary

When a customer cannot pay an overdue invoice at once, the bookkeeper agrees
instalments and then follows them by hand, because shillinq's only instalment
plan belongs to grant reclaims. This change adds a payment plan for ordinary
customer debts: one or more overdue invoices, a schedule of instalments, dunning
paused while the plan holds, payments recognised and allocated, and the plan
broken, with dunning resumed, when an instalment is missed.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). The OpenSpec pass
of 2026-09-27 decided `build` (`openspec/parity/gap-decisions.json`).

**`rec-payment-plan`**, "Agree a payment plan with a customer and follow its
instalments." Rated partial, built. The matrix evidence: "RepaymentInstallment
(instalments with dueDate, paidDate, isOverdue) on SubsidieDetail
/subsidies/:id covers repayment plans for grant reclaims only
(lib/Settings/register.d/add-shillinq-bookkeeping-operations.json:1424)."
Note: "Instalment plans exist only for grant reclaims, not for ordinary
customer debts." Demand: tender https://www.tenderned.nl/aankondigingen/overzicht/416109.

No competitor rates it yes. The partial ratings show the market's shape:

- exact-online (partial): https://support.exactonline.com/community/s/article/All-All-HNO-Content-rn-whatsnewlandingpage?language=en_GB (June 2026), "Payment arrangement layouts"; https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-bank-fin-bnkpymt-editsplitpymntt?language=en_GB, split a payment into several payment terms.
- snelstart (partial): https://www.snelstart.nl/ondernemer/debiteurenbeheer, the separate Debiteurenbeheer product includes "Betalingsregelingen".
- odoo (partial): odoo/odoo@19.0 `addons/account/models/account_payment_term.py:26`, instalment lines set at invoicing; no flow to agree a plan on an overdue invoice.

The row is built for the tender. This change covers it.

## Affected Projects

- [ ] Project: `shillinq`: `PaymentPlan` and `PaymentPlanInstalment`, a service and a daily monitor job, the dunning pause, payment allocation, and pages.

## Scope

### In Scope

- Agreeing a plan for one or more overdue invoices of one customer: number of instalments or instalment amount, frequency, first due date, a grace period, and whether collection costs already charged are part of the plan.
- Generating the instalment schedule so it adds up to the plan total to the cent.
- Mailing the customer a confirmation with the schedule and the payment reference.
- Pausing dunning of the covered invoices with the existing `PAYMENT_PLAN` pause reason while the plan is active.
- Recognising instalment payments from a bank line carrying the plan's payment reference, from a payment link, or by hand, and allocating them to the covered invoices oldest first.
- A monitor that marks instalments due and missed, and breaks the plan after the grace period, lifting the pauses so dunning continues.
- Completing the plan when all instalments are paid.
- A Payment plans page, and a plan section on the customer and invoice pages.

### Out of Scope

- Grant reclaim plans, which keep `RepaymentInstallment`.
- Interest on the plan. A plan can include costs already charged; it does not compute new interest.
- Debt counselling or statutory schuldhulp procedures.

## Approach

`PaymentPlanService` creates the plan and its instalments, pauses dunning
through `DunningRunService::pause()` with reason `PAYMENT_PLAN`, and mails the
confirmation. `PaymentPlanMonitorJob`, a daily `TimedJob`, moves instalments to
due and missed and breaks a plan whose missed instalment passed its grace
period. Payments reach the plan through the bank matching candidates, through a
`PaymentRequest` per instalment when payment links are available, or through a
settle action. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `PaymentPlan` and `PaymentPlanInstalment` added; `ARInvoice` gains `paymentPlanId` (additive).
- Code: new `PaymentPlanService`, `PaymentPlanAllocator`, `PaymentPlanMonitorJob`; a plan candidate in bank matching; `appinfo/info.xml` registers the job.
- Manifest: `PaymentPlans` index and detail, an Agree a payment plan action on `CustomerDetail` and on `ARInvoiceDetail` of an overdue invoice.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Dunning resumes while a plan is being kept
**Severity:** High. **Mitigation:** the plan's pause has no automatic end; only breaking or completing the plan lifts it. The existing pause deadline (`dunning.dispute_pause_hard_deadline_days`, 60 days) is written but not enforced anywhere, and the plan sets its pause's deadline to the last instalment date plus the grace period, so a later enforcement does not cut it short.

### Risk 2: A payment is allocated to the wrong invoice
**Severity:** Medium. **Mitigation:** allocation is oldest invoice first and recorded per instalment, so an auditor can follow each euro; a bookkeeper can reallocate on the plan page before the period closes.

### Risk 3: An instalment schedule that does not add up
**Severity:** Medium. **Mitigation:** the last instalment takes the rounding difference, and the plan refuses to activate unless the instalments sum to the total.

## Rollback Strategy

Revert the PR. Plans stay as records; the dunning pauses they created stay
active until a bookkeeper resumes them, which the release note says.

## Open Questions

- Should a broken plan restart the ladder at the stage where it paused, or at the next one? This change resumes where it paused, which is what `resumePause()` does today.
