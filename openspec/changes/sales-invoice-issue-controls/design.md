# Design: sales-invoice-issue-controls

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **ARInvoice lifecycle** (`lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`): states `draft`, `issued`, `paid`, `overdue`, `disputed`, `written-off`; transitions `issue` (draft to issued), `mark-paid`, `mark-overdue`, `dispute`, `pay-overdue`, `write-off-issued`, `write-off-overdue`. `issue` carries the requires `RuleComplianceGuard::validateInvoice` through `register.d/add-shillinq-rule-compliance-guard.json`.
- **Numbers.** `ARInvoice.invoiceNumber` is a free string ("Sequential invoice number", example `2026-0042`). The rule `vatdir-art226-2` in `lib/Standards/Checks/InvoicingTailChecks.php:355` only checks that a number is present. `InvoiceGenerationService::generateInvoiceNumber()` (`lib/Service/InvoiceGenerationService.php:760`) counts `BillableInvoice` records of the administration and returns `BIL-<year>-<count+1>`.
- **Four eyes precedent.** `lib/Lifecycle/FourEyesPaymentRunGuard.php::check()` refuses a payment run action by the user who created it (spec `payment-run-four-eyes`).
- **Page.** `ARInvoiceDetail` (`src/manifest.json:7870`, extended by `src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json`) renders the lifecycle buttons.

## Goals / Non-Goals

**Goals**
- Every issued invoice gets the next number of an unbroken yearly series, never twice, never by hand.
- Invoices above a threshold are approved by a second person before they can be issued.

**Non-Goals**
- Several series per administration, approval chains.

## Decisions

### D1. The number is taken at issue, not at creation

A draft has no number. The lifecycle action handler
`lib/Lifecycle/Action/AssignInvoiceNumberAction.php`, referenced by FQCN on
`ARInvoice.issue`, takes the administration's `InvoiceNumberSequence` for
the invoice's fiscal year under a lock (`ILockingProvider`, key
`shillinq-invoice-seq-<administrationId>-<kind>`), formats the number and
writes it with the next counter value. A deleted draft therefore never
leaves a gap.
A self-billed invoice (made by the customer, `sales-einvoice-exchange`)
keeps the customer's number: the action skips it, so the seller's series
only counts invoices the seller made.

New schema `InvoiceNumberSequence`: `administrationId`, `kind` (`sales`,
`billable`, `credit`), `pattern` (tokens `{year}`, `{seq:N}`, literal
text; default `{year}-{seq:4}`), `fiscalYear`, `lastValue`.

Alternative considered: a counter field on `Administration`. Rejected: the
series resets per year and differs per kind, so it is its own record.

### D2. The number is read-only once assigned

`invoiceNumber` becomes `readOnly` in the page config and the update guard
refuses a change to it on any state other than `draft`.

### D3. Time-and-expense invoices use the same sequence

`generateInvoiceNumber()` takes kind `billable` from the sequence instead of
counting records.

### D4. Approval is two transitions and a guard on issue

`ARInvoice` gains states `awaiting-approval` and `approved`, transitions
`requestApproval` (draft to awaiting-approval), `approve`
(awaiting-approval to approved, requires `SalesInvoiceApprovalGuard::canApprove`,
refusing the creator), `rejectApproval` (awaiting-approval to draft, with a
reason), and `issue` also from `approved`. `SalesInvoiceApprovalGuard::canIssue`
refuses `issue` from `draft` when the invoice total exceeds the
administration's `salesInvoiceApprovalThreshold` (null means no approval
needed). Because `issue` takes one `requires`, `RuleComplianceGuard::validateInvoice`
calls `canIssue`, the delegation pattern it already uses for `BalanceGuard`.

### D5. A gap report per year

`lib/Service/InvoiceNumberAuditService.php::gaps(administrationId, year)`
lists missing and duplicate numbers across issued, paid, overdue, disputed
and written-off invoices, shown as a card on the Reports page.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Approval states and transitions | Declarative: `x-openregister-lifecycle` | Lifecycle. |
| Threshold and four eyes | Imperative, lifecycle guards | Guards are the declared `requires`. |
| Taking the next number | Imperative, a lifecycle action handler under a lock | A counter that must never hand out a value twice. |
| Gap report | Imperative, a report service (ADR-031 exception: report generation) | Reads a year of numbers. |

## Seed Data

Adviesbureau Van Dijk: sequence `sales`, pattern `{year}-{seq:4}`, fiscal
year 2026, last value 41, so the next issued invoice is `2026-0042`;
approval threshold EUR 10,000. Invoice to Gemeente Voorbeeld for EUR 24,200
created by j.devries needs approval by another user (a.bakker); an invoice
of EUR 1,210 to Bakkerij Jansen issues directly.

## Risks / Trade-offs

- [Users used to typing numbers] → the field shows "assigned when issued" on drafts.

## Migration Plan

A repair step creates each administration's sequences and sets `lastValue`
to the highest counter among the current year's issued invoice numbers that
match the default pattern, logging what it found.

## Open Questions

None.
