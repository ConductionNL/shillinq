# Design: sales-time-and-expense-billing

Read at shillinq development `79f438f33` and openregister development on
2026-09-27.

## Context

**The page.** `BillableInvoiceGenerate` (`src/manifest.d/invoice-from-time-and-expense.json:43`,
route `/invoice/generate`, menu Sales > Invoices > Generate invoice) renders
`src/components/invoice/InvoiceGenerator.vue`. Two textareas at :98-104 take
"Time entry IDs (comma-separated)" and "Expense IDs (comma-separated)", and
`payload()` splits them (`parseIds`, around :206-213). The form already asks
for a customer, a project, a billing model, a rate card and a period.

**The service.** `InvoiceGenerationService::draftInvoice()`
(`lib/Service/InvoiceGenerationService.php:90`) deduplicates the ids through
`InvoiceDeduplicationService::deduplicateSourceIds()` (which scans every
`BillableInvoice` in state `draft` or `posted` for the same ids,
`lib/Service/InvoiceDeduplicationService.php:64`), loads hours with
`loadTimeEntries()` (:511, `UrenRegistratie`, rated through
`RateCardResolver::resolveRate()` at :528) and expenses with `loadExpenses()`
(:570). `loadExpenses()` loads whole `ExpenseClaimEntry` claims and bills each
claim's `amount` as one line; it does not look at the claim's items or their
settlement mode. The T&M model is called without a markup
(`calculateTAndM(timeEntries, expenses)` at :427), `BillingModelEngine::expenseLine()`
(`lib/Service/BillingModelEngine.php:361`) hard-codes `'markup' => 0.0`, and the
line write copies that zero (`InvoiceGenerationService.php:199`).

**The hours.** `UrenRegistratie` (`lib/Settings/shillinq_register.json:7374`)
has `projectId` (a planninq project, shillinq declares no Project schema) and
no billable or approval field. `hours-to-humaniq` moves the hour to humaniq's
`TimeEntry` and repoints `InvoiceGenerationService` (its task 4.3).

**The markup rules.** `PassThroughMarkupRule`
(`lib/Settings/register.d/expense-reimbursement-or-passthrough.json:546`) holds
per customer and category a `markupType` (`percentage` or `fixedAmount`) and
`markupValue` (0.15 means 15 percent), and the page `PassThroughMarkupRules`
keeps them. The same fragment adds `settlementMode`, `linkedCustomerId`,
`markupRuleId`, `markupRateApplied` and `markupAmountCalculated` to `Receipt`,
`MileageEntry` and `PerDiem`, and declares a calculation `markupLookup` on
each (:68, :131, :193) with a string expression built on `lookup(...)`, a
`targets` map and `lockOn: ExpenseClaimEntry.submit`. OpenRegister's
`CalculationEvaluator` (openregister `lib/Service/Calculation/CalculationEvaluator.php:83`)
dispatches a JSON-AST operator table (`prop`, `lit`, `if`, arithmetic,
comparison, date and rounding operators) with no `lookup`, so the targets are
never written.

**Matrix correction (`exp-reinvoice`).** The matrix says the rule is "read
only by the unregistered ExpenseReimbursementGuard". The rule is read by
`SettlementGuard::matchMarkupRule()` (`lib/Lifecycle/SettlementGuard.php:299`)
and applied by `computeMarkupAmount()` (:258), and neither has a caller in
`lib/`, `src/` or any register fragment. `ExpenseReimbursementGuard`, which the
fragment does reference by FQCN (:333-422), reads `markupRateApplied` from the
items (`lib/Lifecycle/ExpenseReimbursementGuard.php:524`), and that field is
never filled. The conclusion (rules kept, never applied) stands.

**Claim states.** `ExpenseClaimEntry` gains `markInvoiced` (`posted` to
`invoiced`, guarded by `requirePassThroughMode`) in the same fragment, so a
pass-through claim already has a place to say it was billed.

## Goals / Non-Goals

**Goals**

- A user generates a time-and-expense invoice by ticking unbilled hours and expenses, never by typing an id.
- A pass-through expense is billed with the markup its rule prescribes, or at cost when none applies.
- Nothing can be billed twice.

**Non-Goals**

- Recording or approving hours.
- A new invoice schema or model.

## Decisions

### D1. One read endpoint for billable work

`GET /api/v1/invoices/billable-work?customerId&projectId&from&to` returns two
lists for the caller's administration:

- hours: from `BillableHoursSource::unbilled(projectId, from, to)`, each with person, date, hours, description, the rate `RateCardResolver` resolves for the selected rate card and the amount;
- expenses: `Receipt`, `MileageEntry` and `PerDiem` items with `settlementMode = pass-through`, `linkedCustomerId = customerId`, whose claim is `approved` or `posted`, each with cost, locked markup rate, markup amount and billed amount.

Both lists leave out every reference a `draft` or `posted` `BillableInvoice`
already carries, using the same rule as `deduplicateSourceIds()`.

Alternative considered: filter the objects API from the browser. Rejected:
the dedup rule and the rate resolution live in PHP and must give the same
answer as the draft.

### D2. Hours come through an interface that hours-to-humaniq implements

`BillableHoursSource` has one method returning approved, billable hours for a
project and period. This change ships the interface and the endpoint against
it; the implementation is the read that `hours-to-humaniq` task 3.3 builds.
Until an implementation is bound, the endpoint returns the hours list as
unavailable with the reason, and the page shows "Hours are not available: the
hours app is not connected" rather than an empty list.

Alternative considered: read `UrenRegistratie` now and move later. Rejected:
`hours-to-humaniq` exists to stop new readers of the old hour, and a second
reader would be one more consumer to repoint.

### D3. The markup is resolved in PHP and locked at submit

`PassThroughMarkupResolver` holds the priority rule now in `SettlementGuard`
(customer and category, then customer, then category, then global, within the
administration and fiscal year) and computes percentage or fixed markup. A
lifecycle action `lock-passthrough-markup` on `ExpenseClaimEntry.submit`
writes `markupRuleId`, `markupRateApplied` and `markupAmountCalculated` on
every pass-through item of the claim. The three `markupLookup` declarations
are removed, since they cannot run. `SettlementGuard::computeMarkupAmount()`
delegates to the resolver so the two stay one rule.

Alternative considered: add a `lookup` operator to OpenRegister's evaluator.
Rejected for this change: a cross-object lookup with priority tiers is a
foundation feature with its own design; the resolver is an ADR-031 exception
that can be retired when such an operator exists.

### D4. Expense lines are per item and carry the recharge

`loadExpenses()` accepts item references (`{schema, id}`) next to the claim ids
it takes today. `BillingModelEngine::expenseLine()` takes the locked markup:
the line amount is cost plus markup, `markup` holds the rate, and a `recharge`
group keeps `costAmount`, `markupRate`, `markupAmount` and `vatTreatment`.
`BillableInvoice.expenseItemRefs` records the items, and when every
pass-through item of a claim sits on a posted invoice the claim takes
`markInvoiced`.

### D5. VAT on a recharge follows the main supply, or the disbursement rule

A recharged cost is part of the price of the service it belongs to, so the
line takes the VAT rate of the invoice's main supply (the rate of the hour
lines, 21 percent for advisory work), not the rate on the original receipt. A
cost the supplier paid in the client's name and for the client's account (a
verschot, for example court fees or a land registry extract) is outside the
VAT base; the user marks such an item as a disbursement in the picker and the
line is written with `vatTreatment = disbursement` at 0 percent with the
mention "Verschotten, buiten de btw-grondslag".

Alternative considered: keep the receipt's own VAT rate. Rejected: a train
ticket bought at 9 percent and recharged as part of consultancy is taxed at
21 percent.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Listing unbilled work | Imperative, `BillableWorkService` | Joins two apps' data and a dedup rule; not an aggregation OpenRegister declares. |
| Markup lookup and lock | Imperative, `PassThroughMarkupResolver` behind a lifecycle action on `ExpenseClaimEntry.submit` | The declared `lookup()` cannot be evaluated; the lock point stays the declared transition. |
| Claim billed state | Declarative, the existing `markInvoiced` transition | The state machine exists. |
| Line VAT treatment | Imperative, `BillingModelEngine::expenseLine()` | Part of the line calculation that already lives there. |

## Seed Data

No new schema. `BillableInvoice` gains `expenseItemRefs` and
`BillableInvoiceLine` gains `recharge`.

Seed objects for the administration "Adviesbureau Kade B.V.":

- `PassThroughMarkupRule` for customer "Woningcorporatie Het Anker", category travel, percentage 0.10.
- A posted pass-through claim of consultant S. de Vries with a receipt "Treinreis Utrecht-Zwolle v.v." with a cost of EUR 42.80 excluding VAT (travel, bought at 9 percent) and a receipt "Parkeren Zwolle" EUR 12.50 (parking, no rule).
- A receipt "Uittreksel Kadaster Kade 12" EUR 3.70 marked as a disbursement.
- 12.5 approved hours on project "Renovatie Kade 12" at EUR 95 an hour from the rate card "Standaard 2026" (through the hours source).

The expected invoice: hours EUR 1,187.50, travel EUR 47.08 (42.80 plus 10 percent), parking EUR 12.50 at cost, all at 21 percent, and the land registry extract EUR 3.70 outside the VAT base.

## Risks / Trade-offs

- [No hours source bound at release] → the page says so and expenses still bill; the change is useful before `hours-to-humaniq` finishes, and complete after.
- [A claim billed at claim level before this change] → the dedup rule treats a claim id on an older invoice as billing all its items.
- [`markupValue` has `multipleOf 0.01`, so 12.5 percent cannot be stored] → out of scope; the release note names it.

## Migration Plan

No data migration. Receipts submitted before this change have no locked rate;
the resolver computes the rate at billing for them and writes it once.
Rollback is reverting the PR.

## Open Questions

- Should the picker group hours per person and rate, as the T&M lines are grouped, or list them one by one? This change lists them one by one and groups on the invoice.
