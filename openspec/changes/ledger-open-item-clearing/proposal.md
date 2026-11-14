---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: ledger-open-item-clearing

## Summary

Balance accounts such as kruisposten, a tussenrekening or a rekening-courant
should net to zero once every booking on them has its counterpart. A
bookkeeper clears matching debit and credit lines against each other
(afletteren) and wants to see which groups do not net to zero. Shillinq can
only do this for the GR/IR account. This change lets any balance account be
kept by open item, with a page to clear lines and a list of what is still
open.

## Motivation

One ledger row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27 (roadmap demand, a twinfield yes, and
the ledger core area).

**`led-open-item-clearing`**, "Clear matching debit and credit entries on a
balance account and see which groups do not net to zero." Rated no, built
state none. Matrix note: "Only the GR/IR clearing account has a saldo check
(lib/Controller/GRIRReconciliationController.php:9); no general matching of
debit and credit items on a balance account." Roadmap demand:
https://helpcenter.moneybird.nl/nl/articles/549560-afletteren-in-beta
("Met het afletter-systeem zie je snel welke balanscategorieën niet op nul
uitkomen en kun je journaalposten automatisch of handmatig afletteren").
twinfield rates yes
(https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/afletteren-3041076,
"Uit te zoeken postenrekeningen: voor het afletteren van op- en afboekingen
op tussenrekeningen, zoals kruisposten"); exact-online, moneybird and odoo
are partial.

## Affected Projects

- [ ] Project: `shillinq`: an open-item flag on accounts, clearing groups on ledger lines, an open items page and match suggestions.

## Scope

### In Scope

- `Account.openItemManaged` for balance accounts.
- Clearing a selection of posted lines on such an account into a group, and undoing a clearing.
- An open items page per account listing uncleared lines and groups that do not net to zero.
- Suggestions: pairs of uncleared lines with equal amounts on opposite sides and the same reference.

### Out of Scope

- Customer and supplier open items. Receivables and payables already match payments to invoices through their sub-ledgers.
- Clearing across administrations.
- The GR/IR saldo check, which stays as it is.

## Approach

A clearing group is a record naming its lines; a line knows its group. The
page reads posted lines of one account and writes groups. Details are in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/`: `Account.openItemManaged`, `GLLine.clearingGroupId`, a `ClearingGroup` schema.
- `lib/Service/`: the clearing and suggestion logic behind two routes.
- `src/manifest.d/`: the open items page and its menu entry under the ledger.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A cleared line is later reversed
**Severity:** Medium. **Mitigation:** reversing a posted transaction whose line is cleared reopens that line's group and lists it as not netting to zero.

### Risk 2: Suggestions pair the wrong lines
**Severity:** Low. **Mitigation:** suggestions are never applied without a person confirming them.

## Rollback Strategy

Hide the page. Groups stay as records and change no posted amount.

## Open Questions

None.
