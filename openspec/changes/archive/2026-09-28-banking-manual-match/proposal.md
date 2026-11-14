---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: banking-manual-match

## Summary

A bank line that did not match anything can today only be classified as a
timing difference, pending or an adjustment. Nobody can say "this line is
invoice 2026-0412" or "this line is bank costs". This change adds pairing a
bank line with one or more open invoices by hand, booking a line to a ledger
account by hand, the settlement step that makes a confirmed match mark the
invoice paid, and fixes the unmatched items bulk actions, which call a URL
that does not reach shillinq.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`). The OpenSpec pass of 2026-09-27
decided `build`. This change covers it.

**`bnk-manual-match`**, "Resolve the bank lines that did not match, by
hand." Rated partial, built. The matrix evidence:
"src/manifest.json:17146-17169 UnmatchedItems bulk actions POST
/api/reconciliations/:reconId/matches/bulk-resolve ->
ReconciliationResolutionService (classify as timing/pending/adjustment with
reason + audit event); no screen pairs a bank line to a specific invoice or
GL entry (ReconciliationMatch rows must already exist)". The note:
"Unmatched items can be classified, not matched by hand; the bulk-action
URLs lack the /apps/shillinq prefix used elsewhere, worth a live check."
No demand row. All five competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Concept-purchase-purch-efcntprcspurinvc?language=en_GB, "This can be done manually or automatically".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207583-factuur-of-categorie-koppelen-aan-transactie, "koppel je ze aan een factuur of categorie".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/boekhouden-handmatig-bankafschriften-inboeken, "In het scherm kun je de factuur zoeken op factuurnummer, klant, leverancier".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/afletteren-3041076, manual matching, with unmatched lines in "Toewijzen Bankregels" (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/bankafschiften-3041022).
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/bank/reconciliation.html, "users can manually reconcile by selecting counterpart items or writing off amounts".

The note's "worth a live check" is settled by reading the code: the bulk
URL is passed through `generateUrl()` by nextcloud-vue's action dispatcher,
so it resolves outside the shillinq app, and `:reconId` is not a token the
dispatcher replaces (design.md, Context).

## Affected Projects

- [ ] Project: `shillinq`: a match modal, a match service and route, a settlement listener, and three corrected manifest URLs.

## Scope

### In Scope

- A "Match by hand" action on an unmatched bank statement line, from the bank statement page and the unmatched items page.
- Pairing one line with one or more open sales invoices or AP transactions, fully or partly.
- Booking one line to a ledger account, with an optional VAT code, as a posted journal entry.
- A settlement step: when a `ReconciliationMatch` is confirmed, the invoice it names moves to paid or partially paid. `banking-connected-accounts` relies on it.
- The unmatched items bulk actions reaching `POST /apps/shillinq/api/reconciliations/{reconId}/matches/bulk-resolve`.

### Out of Scope

- Automatic matching and matching rules. `bookkeeping-bank-reconciliation` REQ-BR-004 and REQ-BR-011 already cover them; `banking-connected-accounts` adds exact matches from the feed.
- Undoing a confirmed match. A wrong match is corrected with a reversing journal entry today; an undo is its own change if asked for.
- Foreign-currency lines. `banking-fx-at-booking` covers the rate.

## Approach

A modal under `src/modals/` searches candidates through OpenRegister and
posts the choice to one new endpoint. The service writes the
`ReconciliationMatch`, or a `JournalEntry` for a ledger booking, and
confirms. A listener on the match's `confirm` transition runs the
invoice's own paid transition, for every confirmer. Details in design.md.

## New Dependencies

None.

## Impact

- `src/modals/BankLineMatchModal.vue` (new), opened from `BankReconciliationDetail` and `UnmatchedItems`.
- `lib/Service/Bank/ManualMatchService.php` and `lib/Controller/ManualMatchController.php` (new), one route.
- `lib/Listener/ReconciliationMatchSettlementListener.php` (new).
- `src/manifest.json`: the three bulk action URLs on `UnmatchedItems`.

## Cross-Project Dependencies

- OpenRegister: `ObjectService`, the lifecycle engine and `ObjectTransitionedEvent` as they stand. No change needed there.
- `ledger-posting-path` (open change in this repo): a ledger booking by hand posts a `JournalEntry`, which needs its `materialise-gl-transaction` handler.

## Risks

### Risk 1: A line is matched to more than its amount
**Severity:** High. **Mitigation:** the service refuses a selection whose total exceeds the line amount; a smaller total is a partial match that names the remainder.

### Risk 2: A settlement fires twice
**Severity:** Medium. **Mitigation:** the listener acts only on the `confirm` transition and only when the invoice is still in a payable state; a second event for the same match finds the invoice paid and does nothing.

## Rollback Strategy

Remove the modal's actions and unregister the listener. Matches already
confirmed stay as records; invoices already settled stay paid, which is
correct. The URL fix is independent and should stay.

## Open Questions

None.
