# Design: expenses-category-mapping

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The receipt.** `Receipt` (`lib/Settings/shillinq_register.json:9164`) has
`category` as a free string, "Expense category (e.g. travel, meals, supplies,
accommodation)" (:9239), plus `amount`, `amountInBaseCurrency`,
`costCentreCode` and `claimId`. `register.d/gl-account-suggestion-consume.json:39`
adds `glAccount`, the account an operator confirmed ("never auto-filled from a
suggestion without operator confirmation"), and `suggestedGlAccount` from
docudesk. No schema named `ExpenseCategory` exists; the matrix is right.

**The claim's posting.** `ExpenseClaimEntry` (`shillinq_register.json:9773`)
declares on `post` the action `materialise-gl-transaction` with
`debitSources` per item type and a `creditMapping`:

| Source | Declared account lookup | Line |
|---|---|---|
| `Receipt` | `Account[category=@receipt.category].accountNumber` | `shillinq_register.json:10024` |
| `MileageEntry` | `Account[isExpenseMileageAccount=true].accountNumber` | same block |
| `PerDiem` | `Account[isExpensePerDiemAccount=true].accountNumber` | same block |
| credit | `Account[isExpenseControlAccount=true].accountNumber` | `shillinq_register.json:10041` |

`register.d/expense-reimbursement-or-passthrough.json:344` repeats the pattern
for the reimbursable path. **`Account` (`shillinq_register.json:1340` and its
fragments) declares none of `category`, `isExpenseMileageAccount`,
`isExpensePerDiemAccount` or `isExpenseControlAccount`**, so every one of these
lookups resolves to nothing, whatever handler serves the action. The matrix's
"no service posts ExpenseClaimEntry/Receipt" is true today; `ledger-posting-path`
adds the handler and an expense claim mapper, which needs these accounts to
exist.

**The chart.** The RGS seed (`lib/Settings/seeds/rgs-3.5-mkb.json`) has the
expense accounts a category maps to: 4050 Personeelsopleidingen, 4060 Overige
personeelskosten, 4210 Brandstof, 4310 Kantoorartikelen, 4330 Telefoon en
internet, 4340 Software en SaaS-abonnementen, 4440 Representatiekosten, 4510
OV-reiskosten, 4520 Buitenlandse reizen, 4530 Kilometervergoeding, and 2200
Personeelsschulden for what the business owes its staff.

**Pages.** `Receipts` (`src/manifest.json:9824`) and `ExpenseClaims`, with the
receipt form of `expense-capture-core`.

## Goals / Non-Goals

**Goals**

- Every expense line of a claim resolves to an expense account without anyone typing an account number.
- A line without an account stops the post and says which line.

**Non-Goals**

- The posting handler, partial VAT deduction rules, learned suggestions.

## Decisions

### D1. `ExpenseCategory` is data per administration

Fields: `code` (slug, unique per administration), `name` (`nl`, `en`),
`expenseAccount` (an `Account.accountNumber` of type expenses), `vatDeductionRule`
(`full`, `none`, or a reference to a partial rule of
`tax-vat-rates-and-deductibility`; default `full`), `defaultCostCentre`, `active`, `administrationId`. A guard refuses an
account that does not exist or is not of type expenses.

Alternative considered: a `category` field on `Account`, as the declaration
assumes. Rejected: one account serves several categories (train and bus both
go to 4510), and a category is an expense-claim concept the chart should not
carry.

### D2. The receipt picks a code

`Receipt.category` keeps its name and type and holds a category code; a relation
filter limits the picker to the administration's active categories. A repair
step maps existing text by exact code, then by known Dutch and English names
(travel, reiskosten, trein; meals, lunch, maaltijd; parking, parkeren; and so on),
and sets the rest to `uncategorised`, listing them for a bookkeeper.

### D3. One resolver, in a fixed order

`ExpenseAccountResolver::forReceipt(receipt)` returns `glAccount` when an
operator confirmed one, else the category's `expenseAccount`, else null with the
reason. `forMileage()` and `forPerDiem()` return the administration's mileage
and per diem accounts; `payable()` returns the expense payable account. The
docudesk suggestion is shown beside the resolved account and never replaces it.

### D4. The declarations name the resolver

The two `ExpenseClaimEntry.post` declarations replace their `accountLookup` and
`creditMapping` strings with `"resolver": "expense-account"` per source, which
the expense claim mapper of `ledger-posting-path` reads by calling
`ExpenseAccountResolver`. A null answer makes the mapper refuse the post with the
line named, which aborts the transition.

Alternative considered: keep the lookup strings and add the four fields to
`Account`. Rejected: it would put claim semantics on the chart of accounts and
still leave `category` meaning two different things.

### D5. Three settings, seeded from RGS

Administration expense settings: `mileageAccount` (default 4530),
`perDiemAccount` (default 4060), `expensePayableAccount` (default 2200), editable
on the settings page next to the categories.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Categories and their accounts | Declarative: `ExpenseCategory` schema, settings page | Configuration. |
| Refusing a non-expense account | Declarative guard on `ExpenseCategory` save | A validation rule. |
| Resolving a line's account | Imperative, `ExpenseAccountResolver` | An ordered choice across three sources; OpenRegister's evaluator has no cross-object lookup. |
| Posting the claim | Declarative action on `ExpenseClaimEntry.post`, executed by the handler of `ledger-posting-path` | The declaration stays the source of truth; only its account source changes. |

## Seed Data

Adds `ExpenseCategory` and three administration settings.

Seeded categories for an administration on the RGS template:

| Code | Name (nl) | Account | VAT deduction |
|---|---|---|---|
| travel-public | Openbaar vervoer | 4510 | full |
| travel-abroad | Buitenlandse reis | 4520 | full |
| fuel | Brandstof | 4210 | full |
| meals | Maaltijden en representatie | 4440 | none (food and drink in horeca) |
| office | Kantoorartikelen | 4310 | full |
| phone | Telefoon en internet | 4330 | full |
| software | Software en abonnementen | 4340 | full |
| training | Opleiding en cursus | 4050 | full |
| other | Overige personeelskosten | 4060 | full |

Example: consultant S. de Vries submits a claim with a train receipt
(travel-public, EUR 42.80 including EUR 3.53 VAT at 9 percent), a lunch with a
client (meals, EUR 37.50, VAT not deductible) and 86 km of mileage at EUR 0.23;
on posting EUR 39.27 goes to 4510, EUR 3.53 to VAT receivable, EUR 37.50 to
4440, EUR 19.78 to 4530, and the total of EUR 100.08 to 2200.

## Risks / Trade-offs

- [Two categories on one account lose detail] → reporting by category stays possible from the receipts; the ledger only needs the account.
- [Partial deduction rules are not there yet] → `full` and `none` cover the seeded categories; a partial rule from `tax-vat-rates-and-deductibility` can be referenced once that change ships.

## Migration Plan

The repair step maps existing receipt categories and seeds the categories and
settings for every administration on the RGS template. Rollback is reverting
the PR.

## Open Questions

- Should the category list be shared across administrations of one organisation? This change keeps it per administration, like the chart.
