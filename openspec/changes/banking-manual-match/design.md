# Design: banking-manual-match

Read at shillinq development `79f438f33` and nextcloud-vue development on
2026-09-27.

## Context

**Lines and matches.** `BankStatementLine` is declared in
`lib/Settings/shillinq_register.json:16039` with `matchState` unmatched,
candidate, confirmed, routed-to-suspense, and again in
`lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json:991` with
`status` unmatched, matched, routed-to-suspense. `ReconciliationMatch`
(`shillinq_register.json`) carries `bankLineRefs`, `targetType`,
`targetRefs`, `confidence`, `isPartial`, `state`, `confirmedBy`,
`confirmedAt`; its lifecycle is candidate to confirmed or rejected. The
reconciliation reports fragment
(`register.d/bookkeeping-reconciliation-reports.json`) adds `reconId`,
`bankLineId`, `glTransactionId`, `arInvoiceId`, `apTransactionId`,
`resolutionStatus` and `resolutionReason`.

**The pages.** `BankReconciliationDetail` (`src/manifest.json`, route
`/bookkeeping/bank-reconciliation/:id`, schema `BankStatement`) shows a
statement with lifecycle actions and no per-line action. `UnmatchedItems`
(`src/manifest.json:17095`, route `/bookkeeping/unmatched-items`) lists
`ReconciliationMatch` rows with an empty `resolutionStatus`, grouped by
`reconId`, so a line with no match row never appears there. Its three bulk
actions (lines 17146 to 17169) classify through
`ReconciliationResolutionService::resolveMatch()`
(`lib/Service/ReconciliationResolutionService.php:86`) as timing, pending
or adjustment.

**The bulk URL defect, confirmed by reading the dispatcher.** The bulk
actions declare `"url": "/api/reconciliations/:reconId/matches/bulk-resolve"`.
nextcloud-vue's `src/utils/actionsDispatcher.js` (`executeApiCall`) resolves
a non-absolute URL with `generateUrl(url)`, which yields
`/index.php/api/reconciliations/...`, not the app route
`/index.php/apps/shillinq/api/reconciliations/{reconId}/matches/bulk-resolve`
(`appinfo/routes.php:471`). And its token grammar replaces `{objectId}` and
`@object.<field>` tokens, not `:reconId`, so the literal `:reconId` would be
sent even with the prefix. The other 23 `url` values in the manifests that
call a shillinq API use
the `/apps/shillinq/api/` prefix; these three are the only ones without it.

**Settlement.** `bookkeeping-bank-reconciliation` REQ-BR-006 says a
confirmed match emits a lifecycle event that AR and AP consume to move an
invoice to paid. No consumer does. The one listener on a confirmed match,
`lib/Listener/ReconciliationMatchToReportListener.php`, stamps report
fields. The invoices' own transitions exist: `ARInvoice` (lifecycle field
`lifecycleState`, `register.d/add-shillinq-bookkeeping-compliance.json`)
has `mark-paid` from issued and `pay-overdue` from overdue; `APTransaction`
(`register.d/bookkeeping-accounts-payable-core.json:298`, field `state`)
has `matchFull` and `matchPartial`.

## Goals / Non-Goals

**Goals**

- A bookkeeper pairs any unmatched line with the invoice or invoices it pays, or books it to a ledger account, from the statement.
- A confirmed match settles its invoice, whoever confirmed it.
- The existing bulk classification works.

**Non-Goals**

- New matching rules, or scoring changes.
- Undo of a confirmed match.

## Decisions

### D1. One modal, two outcomes

`BankLineMatchModal.vue` opens on a line. Tab "Invoices" lists open
`ARInvoice` records for a credit to the bank and open `APTransaction`
records for a debit, searchable by number, counterparty and amount, with
exact amounts first. Tab "Ledger account" takes an account, an optional
VAT code and a description. The modal posts to
`POST /api/v1/bank-lines/{lineId}/match` with either `targets[]` or
`ledgerAccount`.

Alternative considered: extend the classification bulk action with a
target field. Rejected because classification works on existing match rows
and has no candidate search.

### D2. The service writes a confirmed match, or a posted journal entry and a match

`ManualMatchService::match()` checks the line is unmatched and the
statement is not reconciled, refuses a selection whose total exceeds the
line amount, writes a `ReconciliationMatch` with `confidence` manual,
`isPartial` when the total is lower, and runs its `confirm` transition as
the user. For a ledger booking it writes a two-line `JournalEntry` (bank
ledger account against the chosen account, VAT split out when a code is
given) and posts it through `postDirect`, then records the match with
`targetType` gl. The line's match state becomes confirmed.

Alternative considered: write a `GLTransaction` directly. Rejected: journal
entries are the one way into the ledger that runs the balance and period
guards, and `ledger-posting-path` makes posting them work.

### D3. Settlement is a listener on the confirm transition

`ReconciliationMatchSettlementListener` listens for OpenRegister's
`ObjectTransitionedEvent` on `ReconciliationMatch` with transition
`confirm`. For each target it runs the invoice's own transition: for
`ARInvoice`, `mark-paid` from issued or `pay-overdue` from overdue; for
`APTransaction`, `matchFull` or, when `isPartial`, `matchPartial`. An
invoice not in a payable state is left alone and logged. The listener is
the one settlement path for manual, rule and feed matches.

Alternative considered: settle inside `ManualMatchService`. Rejected
because the feed's automatic matches (`banking-connected-accounts`) would
then need a second copy.

### D4. Correct the bulk URLs, and pass the group key as an object token

The three bulk actions change to
`/apps/shillinq/api/reconciliations/@object.reconId/matches/bulk-resolve`.
If the mass action bar does not supply an object context for a selection,
the route changes to take `reconId` in the body instead and the URL loses
the segment; the task verifies which in a live call.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Choosing targets for a line | Imperative UI modal plus one service | Candidate search across two schemas and a sum check. |
| Recording and confirming the match | Declarative `ReconciliationMatch.confirm` transition | The lifecycle exists. |
| Settling the invoice | Imperative listener that calls the invoice's declared transitions | The event exists and no declarative consumer is possible across schemas; the outcomes stay declared on the invoice schemas. |
| Booking to a ledger account | Declarative `JournalEntry.postDirect` | The posting path is the ledger's. |

## Seed Data

No schema is added or changed. Test data for "Gemeente Voorbeeld":
unmatched line of EUR 2,420.00 from "Schoonmaakbedrijf De Vries" with
remittance "factuur sept"; open AP transaction DV-7781 of EUR 2,420.00; a
line of EUR 12.50 debit with remittance "Kosten zakelijk pakket", booked by
hand to 4910 Bankkosten; a line of EUR 1,000.00 against sales invoice
VF-2026-0901 of EUR 1,500.00 as a partial.

## Risks / Trade-offs

- [Two `BankStatementLine` declarations with different state fields] → the service writes both `matchState` confirmed and `status` matched until the schema consolidation removes one; named in the PR.
- [A partial on a sales invoice] → `ARInvoice` has no partially-paid transition; the listener leaves the invoice issued and records the partial on the match, and the open amount is read from the matches. Named as a limit in the release note.

## Migration Plan

No data migration. Existing classified matches are untouched.

## Built at development `83d19fc8d` (28 Sep 2026): where the design moved

- **Bulk actions (D4).** CnIndexPage does not dispatch a bulk `api-call` at all: without a `handler` it only emits `bulk-action`, and the endpoint also needs a reason the declaration never sent. The three bulk actions now name the `classifyUnmatched` handler (registered in `src/main.js` customComponents), which opens `UnmatchedClassifyDialog` for the reason and posts once per `reconId` (`src/utils/bankMatchApi.js`). `ReconciliationResolutionService` now patches the match: its `updateObject` call had replace semantics and would have erased every other field of the match.
- **Line action (D1).** The statement's lines are an `openregister-related-list` widget in a sidebar tab, which offers no row action. The line action lives on a new index page `UnmatchedBankLines` (`/bookkeeping/bank-lines/unmatched`, Banking menu) and as a row action on `UnmatchedItems`; both open `BankLineMatchModal` through the `openBankLineMatch` handler with `spawnDialog`.
- **Over-selection (D2).** Several invoices must add up to the line; one invoice larger than the line is the partial case. A line larger than its selection is refused too, so an overpayment never hides inside a confirmed match.
- **VAT (D2).** Shillinq has no input VAT account mapping (`VATGLAccounts` lists output accounts only), so the ledger tab takes a VAT rate and a VAT account instead of a VAT code.
- **Bank ledger account.** `BankAccount.ledgerAccountNumber` (the field `banking-connected-accounts` task 1.1 names) is declared here, because the ledger booking needs it; `BankAccount` goes to 0.2.0. A bank account without one refuses the booking by name.
- **Effective ReconciliationMatch.** The merged schema requires thirteen fields from two declarations (`confidence` is the string enum `auto`/`manual`, the lifecycle field is `status` from `pending`); `ManualMatchService::buildMatch` fills all of them and the tests validate the payload against the merged schema with opis.

## Open Questions

None.
