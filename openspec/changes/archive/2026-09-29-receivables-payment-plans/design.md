# Design: receivables-payment-plans

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The grant-only plan.** `RepaymentInstallment` is declared twice, in
`lib/Settings/shillinq_register.json:15534` and in
`lib/Settings/register.d/add-shillinq-bookkeeping-operations.json:1419` (the
matrix cites :1424, inside the same block). It carries `subsidyId`,
`installmentNumber`, `dueDate`, `amount`, `paidDate` and `status` (`pending`,
`due`, `overdue`, `paid`); the matrix's "isOverdue" is that status value, not a
field. Its RBAC gives write access to `subsidie-coordinator` only, and it is
shown on `SubsidieDetail` (`src/manifest.json:18619`, list at :18725). A
`SubsidieRepaymentGuard` (`lib/Guard/SubsidieRepaymentGuard.php`) checks the
reclaim balance. Nothing ties it to a customer or an `ARInvoice`.

**Dunning pauses.** `DunningPauseDispute`
(`register.d/bookkeeping-credit-control-dunning.json:975`) already offers
`reason` `PAYMENT_PLAN` next to `DISPUTED` and `OTHER`.
`DunningRunService::pause()` (`lib/Service/DunningRunService.php:482`) creates
an active pause and writes `hardDeadlineEindigt` as start plus
`dunning.dispute_pause_hard_deadline_days` (default 60, :490);
`resumePause()` (:537) closes it. `hasActivePause()` (:1251) checks only
`lifecycleState = active`, and no code reads `hardDeadlineEindigt`, so the
60-day deadline is not enforced today.

**Partial payments.** `ARInvoice` has `amountDue` and `paidAmount`
(`register.d/checks-invoicing-extra.json:14,20`) and a lifecycle whose
settlement goes from `issued` or `overdue` to `paid`; there is no
partially-paid state on `ARInvoice` (the quote-order `Invoice` schema has one,
`register.d/bookkeeping-quote-order-invoice.json:766`).

**Bank matching.** `BankfeedMatcher::matchTransaction()`
(`lib/Service/BankfeedMatcher.php:66`) scores a bank line against candidate
invoices; `ReconciliationMatch` records the match.

## Goals / Non-Goals

**Goals**

- A bookkeeper agrees a plan in one step from the customer or the invoice, and the customer receives the schedule.
- Nobody chases the covered invoices while the plan is kept, and dunning resumes by itself when it is not.
- Each instalment is recognised and allocated without manual bookkeeping.

**Non-Goals**

- Interest calculation, grant reclaims, legal debt procedures.

## Decisions

### D1. Two new schemas, not a generalised `RepaymentInstallment`

`PaymentPlan`: `planNumber`, `customerId`, `invoiceIds`, `totalAmount` (the
covered invoices' open amount, plus costs when `includesCharges`),
`instalmentCount`, `frequency` (`weekly`, `monthly`), `firstDueDate`,
`graceDays` (default 14), `paymentReference` (for example `RGL-2026-0007`),
`agreedOn`, `agreedWith`, `note`, `administrationId`, and a lifecycle
`draft`, `active`, `completed`, `broken`, `cancelled`.
`PaymentPlanInstalment`: `planId`, `instalmentNumber`, `dueDate`, `amount`,
`paidAmount`, `paidDate`, `allocations` (invoice id and amount), `state`
(`pending`, `due`, `paid`, `missed`), optional `paymentRequestId`.

Alternative considered: add a subject to `RepaymentInstallment`. Rejected: its
RBAC, guard, retention trigger and page are all about subsidies, and a customer
plan needs a plan header with its own lifecycle.

### D2. Activating a plan pauses dunning, only the plan lifts it

`activate` writes one `DunningPauseDispute` per covered invoice with reason
`PAYMENT_PLAN`, details naming the plan, and `hardDeadlineEindigt` set to the
last instalment's due date plus `graceDays`, and stamps `paymentPlanId` on each
invoice. `break`, `complete` and `cancel` resume those pauses through
`resumePause()`.

### D3. The schedule adds up to the cent

Instalment amounts are the total divided by the count, rounded to cents, with
the last instalment taking the difference; with an instalment amount given, the
count follows and the last instalment is the remainder. `activate` is refused
when the sum differs from `totalAmount`.

### D4. Payments reach the plan three ways

1. Bank: a bank line whose remittance contains the plan's `paymentReference`, or whose amount equals the next due instalment and whose counterparty IBAN matches the customer, is offered as a match to the plan, confidence high for the reference and medium otherwise.
2. Payment link: when `receivables-payment-links` is present, each instalment can carry a `PaymentRequest` whose capture pays it.
3. By hand: a settle action on the instalment with method and reference.

`PaymentPlanAllocator` allocates the amount to the covered invoices oldest
first, raising `paidAmount` and lowering `amountDue`; an invoice whose
`amountDue` reaches zero moves to `paid`. An amount above the instalment pays
the next instalments in order.

### D5. A daily monitor keeps the states honest

`PaymentPlanMonitorJob`, a `TimedJob` in `lib/BackgroundJob/` registered in
`appinfo/info.xml`, moves `pending` instalments to `due` on their date and to
`missed` when unpaid `graceDays` after it, and breaks the plan on the first
missed instalment: pauses resumed, the customer mailed that the arrangement has
ended, the `ar-controller` members notified. A plan whose instalments are all
paid moves to `completed`.

### D6. The confirmation is a mail with the schedule

On activation the customer receives, through the invoice mail path of
`sales-invoice-sending` when present and `IMailer` otherwise, a mail listing
the covered invoices, each instalment's date and amount, the IBAN and the
payment reference.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Plan and instalment states | Declarative: `x-openregister-lifecycle` on both schemas | Auditable state machines. |
| Refusing an unbalanced schedule | Declarative guard on `PaymentPlan.activate`: `PaymentPlanGuard::requireBalancedSchedule` | A precondition. |
| Pausing and resuming dunning | Imperative, `PaymentPlanService` calling `DunningRunService::pause()` and `resumePause()` | Cross-schema side effects of a transition. |
| Due and missed instalments, breaking a plan | Imperative, `PaymentPlanMonitorJob` | Time-driven. |
| Telling staff a plan broke | Declarative: `x-openregister-notifications` on `PaymentPlan` entering `broken` | A state notification. |

## Seed Data

Adds `PaymentPlan` and `PaymentPlanInstalment` with the fields of D1, and
`ARInvoice.paymentPlanId`.

Seed objects for the administration "Installatiebedrijf Hoekstra":

- `PaymentPlan` RGL-2026-0007 for customer "Café De Zwaan" covering invoices 2026-0231 (EUR 1,815.00) and 2026-0266 (EUR 605.00), total EUR 2,420.00, six monthly instalments of EUR 403.33 with a last one of EUR 403.35, first due 2026-11-01, grace 14 days, active.
- The first instalment paid on 2026-11-01 by a bank line with remittance "RGL-2026-0007 termijn 1", allocated in full to invoice 2026-0231.
- A broken plan RGL-2026-0004 whose third instalment was missed, with its pauses resumed.

## Risks / Trade-offs

- [The customer pays without the reference] → the amount-and-IBAN match is offered at medium confidence for a bookkeeper to confirm; nothing is allocated silently on a guess.
- [An invoice in a plan is disputed later] → a dispute pause is its own record; breaking or completing the plan resumes only the plan's pauses.
- [Plans outlive the period close] → allocation writes are ordinary payments in the period they arrive.

## Migration Plan

New schemas only. No data migration. Rollback is reverting the PR; pauses of
active plans then need resuming by hand, which the release note lists.

## Open Questions

- Should a plan that is broken be reinstatable once? This change ends it; a new plan can be agreed.

## As built (2026-09-29)

- The guard is declared as `requires: OCA\Shillinq\Lifecycle\PaymentPlanGuard` (a `LifecycleGuardInterface` class, the form OpenRegister's guard registry resolves); `requireBalancedSchedule()` is its method.
- The mail goes through `IMailer` (`PaymentPlanMailer`), because sales-invoice-sending has no mail path at HEAD. Its strings are translated with the app's IL10N.
- `DunningRunService::pause()` gained an optional deadline, so a plan's pause ends at the last due date plus grace, as D2 says.
- A bank match to a plan is a confirmed `ReconciliationMatch` of type `ar-invoice`, partial, naming the plan's invoices and `paymentPlanId`. It is partial, so the settlement listener leaves the invoices to the allocator.
- D4.2 (payment links) is deferred to receivables-payment-links: no code creates a payment link at HEAD.
- The Due this month filter reads `instalmentThisMonth`, `thisMonthAmount` and `thisMonthPaid`, which the daily monitor and every payment refresh.
- Seed data: no hand-written plan objects. They would name invoices the seed does not ship. The demo data generator covers the schemas (gate 101).
