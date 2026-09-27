# Design: ledger-posting-path

Read at shillinq development `79f438f33` and openregister development
`84352bae` on 2026-09-27.

## Context

OpenRegister runs a transition's declared `actions` after its `requires`
guard passes. `LifecycleActionRegistry::resolve()` (openregister
`lib/Service/Lifecycle/LifecycleActionRegistry.php`) ships two built-ins,
`set-fields` and `set-field`. Any other name is looked up in OpenRegister's
container and then in the Nextcloud server container. A name nobody
registered throws a `RuntimeException`, and the transition aborts. That is
deliberate: the registry's own docblock names `materialise-gl-transaction` as
a fleet-declared name "intentionally absent", which "an app that declares one
MUST register a handler under that id".

Shillinq registers exactly one handler today,
`lib/Lifecycle/Action/AppendReopenHistoryAction.php`, and it is referenced by
FQCN from `lib/Settings/register.d/bookkeeping-period-close.json:321`. The two
names below are declared and never registered.

Every declaration, parsed from the register JSON on 2026-09-27:

| Schema.transition | File | Action | A PHP poster already books it? |
|---|---|---|---|
| `GLTransaction.post` (draft to posted) | `lib/Settings/shillinq_register.json` | `evaluate-allocation-rules` | No |
| `JournalEntry.post` (pending to posted) | `register.d/add-shillinq-bookkeeping-foundation.json` | `materialise-gl-transaction` | No |
| `JournalEntry.postDirect` (draft to posted) | same | `materialise-gl-transaction` | No |
| `APInvoice.post` | `lib/Settings/shillinq_register.json` | `materialise-gl-transaction` | No |
| `ARInvoice.issue` (draft to issued) | `register.d/add-shillinq-bookkeeping-compliance.json:395` | none declared | No: the issued state's description says the invoice is booked, but no action is declared and no PHP poster books it (found by the OpenSpec pass review of batch 2); this change adds the declaration |
| `ExpenseClaimEntry.post` | `shillinq_register.json` and `register.d/expense-reimbursement-or-passthrough.json` (two entries) | `materialise-gl-transaction` | No |
| `InventoryValuation.postCOGS`, `postReceipt`, `postVariance` | `register.d/inventory-cogs-posting.json` | `materialise-gl-transaction` | No caller in `lib/` or `src/` |
| `StockMove.post` | `register.d/inventory-stock-movement-ledger.json` | `materialise-gl-transaction` | Yes: `StockMoveTransitionedListener` calls `CogsPosterService::postCogs` on `posted` |
| `Payroll.issue` | `register.d/bookkeeping-detachering-payroll-administratie.json` | `materialise-gl-transaction` | Not applicable: payroll runs in humaniq (matrix row `ppl-payroll`, decided no) |

The ledger pages reach these transitions through the lifecycle buttons of
`GeneralLedgerDetail` (`src/manifest.json`, page id `GeneralLedgerDetail`,
route `/general-ledger/:id`). humaniq reaches `JournalEntry` through its
payroll hand-off (humaniq `lib/Flow/PayrollGlPostNode.php`, matrix row
`pay-gl-journal`), which writes a balanced loonjournaalpost into this
register.

`AllocationRule` records exist and are seeded per administration by
`SettingsService::seedAllocationRules()` (`lib/Service/SettingsService.php:1311`).
Nothing evaluates them.

## Goals / Non-Goals

**Goals**

- Posting from the ledger pages succeeds for a balanced transaction and is refused for an unbalanced one, in any open fiscal year.
- A journal entry posts into exactly one balanced `GLTransaction`, whoever wrote the entry.
- No transition posts twice.

**Non-Goals**

- Changing the balance guard. `RuleComplianceGuard::validateTransaction` stays the effective `requires` on `GLTransaction.post` (`register.d/add-shillinq-rule-compliance-guard.json`).
- Retiring shillinq's payroll engine. That follows from `ppl-payroll` and is its own change.
- New allocation cadences.

## Decisions

### D1. Register by declared name, not by rewriting declarations to FQCNs

Both handlers are registered in `Application::register()` with
`$context->registerServiceAlias('<declared-name>', <HandlerClass>::class)`.
The server container then answers the registry's lookup by name.

Alternative considered: rewrite each declaration to the handler's FQCN, as
`bookkeeping-period-close.json` does. Rejected because the names are fleet
vocabulary (the registry's docblock lists `materialise-gl-transaction`), ten
declarations would change, and a sibling app declaring the same name later
would still find nothing.

### D2. `materialise-gl-transaction` writes one balanced transaction, once

The handler implements OpenRegister's `LifecycleActionInterface`. From the
transitioned object and the declaration's `actionParameters`
(`register`, `schema`, `linesSchema`, `keepBalanced`, `backReferenceField`,
`idempotencyKey`) it:

1. Looks for an existing `GLTransaction` whose `backReferenceField` points at
   the source object. If one exists, it returns without writing; this is the
   idempotency the declaration already asks for.
2. Builds the `GLTransaction` header and one `GLLine` per source line, 1:1.
3. Refuses to write when `keepBalanced` is true and debit does not equal
   credit, by throwing, which aborts the transition with the imbalance named.
4. Writes through `ObjectService`, then sets the source object's
   `glTransactionId`.

`ARInvoice.issue` gains the declaration too, with an `ARInvoice` mapper that
debits the receivables control account and credits revenue and VAT per line;
`sales-down-payments` adds its down-payment and deduction rules to that
mapper. The `ExpenseClaimEntry` mapper resolves its accounts through
`ExpenseAccountResolver` from `expenses-category-mapping`, because the
declaration's lookups (`Account[category=...]`, `isExpenseControlAccount`
and siblings) name fields `Account` does not declare.

The mapping from a source line to a `GLLine` differs per schema
(`JournalEntry.lines` already carries account, side and amount; `APInvoice`
lines carry an expense account and VAT). The handler holds one small mapper
per source schema in the inventory it serves, and throws for a schema it has
no mapper for, so an unplanned declaration fails loudly instead of posting
something invented.

### D3. `evaluate-allocation-rules` adds lines, it does not create transactions

On `GLTransaction.post`, the handler reads `AllocationRule` records matching
the declaration's `filter` (`lifecycleState: active`, `cadence: per-posting`)
whose `sourceAccountPattern` matches a line of the transaction, and appends
the balanced `GLLine` pairs each rule prescribes. It keys idempotency on
`transactionId` as the declaration says. With no matching rule it writes
nothing and the post completes.

### D4. Remove the declarations a PHP poster already serves

`StockMove.post` is booked by `StockMoveTransitionedListener` and
`CogsPosterService`; serving the declaration too would post the COGS twice.
The declaration is removed from `inventory-stock-movement-ledger.json`.
`Payroll.issue` is removed for the reason in the table. Every other row of
the table is served by the handlers.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which transitions post to the ledger | Declarative, unchanged: `x-openregister-lifecycle.transitions.*.actions` | The declarations already exist and say what they mean. |
| Writing the `GLTransaction` and allocation lines | Imperative, a lifecycle action handler | A declared action needs an executor; OpenRegister ships none for these names and says so. This is the executor, not a parallel service. |

## Seed Data

No schema is added or changed. The existing `AllocationRule` seed
(`SettingsService::seedAllocationRules`) is the data the allocation handler
reads. For the tests, one administration ("Gemeente Voorbeeld", RGS BBV
template) with a balanced memorial entry (debit 4000 Huisvesting EUR 1,200,
credit 1100 Bank EUR 1,200) and one unbalanced draft (debit 1,200, credit
1,000) cover both outcomes; a humaniq-shaped payroll journal (gross wages
EUR 5,000, loonheffing EUR 1,450, net pay EUR 3,550) covers the hand-off.

## Risks / Trade-offs

- [A source schema's lines map ambiguously] → the handler throws for a schema without a mapper; each mapper has a unit test with a balanced and an unbalanced source.
- [Allocation rules nobody has run before move money] → rules run only when `active`; the release note lists the seeded rules.
- [A future PHP poster is added without removing the declaration] → idempotency on `backReferenceField` makes the second write a no-op rather than a duplicate.

## Migration Plan

No data migration. Transitions that aborted start succeeding. Rollback is
removing the two aliases.

## Open Questions

None.
