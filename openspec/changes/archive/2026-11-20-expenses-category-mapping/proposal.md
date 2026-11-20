---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: expenses-category-mapping

## Summary

An expense receipt carries a free-text category, and nothing turns that
category into a ledger account, so an expense claim cannot post itself: the
posting it declares looks up account fields that the chart of accounts does not
have. This change adds expense categories that each name their expense account,
lets a receipt pick one, fixes the account resolution the claim's posting uses
(the receipt's own confirmed account first, then its category's account, never
a guess), and replaces the unresolvable account flags for mileage, per diem and
the claim payable with administration settings.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). The OpenSpec pass
of 2026-09-27 decided `build` (`openspec/parity/gap-decisions.json`).

**`exp-category-mapping`**, "Map expense categories to ledger accounts so they
post themselves." Rated no, built state none. The matrix evidence:
"Receipt.category and ExpenseClaimEntry are free fields in shillinq_register.json
with no category->GL account map; no ExpenseCategory schema (grep
ExpenseCategory only hits docudesk-templates.json and a description); no
service posts ExpenseClaimEntry/Receipt to the ledger". Reached on: "nothing
reaches it: no mapping table or page exists". Note: "The nearest thing is the
per-draft AI GL-account suggestion from docudesk (GlAccountSuggestionClient),
which is a suggestion, not a mapping." No demand row. Three competitors rate it
yes:

- moneybird: https://helpcenter.moneybird.nl/nl/articles/207276-categorie-toevoegen, "In Moneybird gebruiken we de term Categorieen in plaats van grootboekrekeningen. Je categoriseert factuurregels".
- snelstart: https://www.snelstart.nl/ondernemer/inzicht, "Grootboekrekening koppelen aan inkoopfacturen"; https://kennisplein.snelstart.nl/snelstartpolaris/boekingsvoorstellen-aan-of-uitzetten-in-snelstart-polaris, booking proposals learn the account per description.
- odoo: odoo/odoo@19.0 `addons/hr_expense/models/hr_expense.py:680` `_compute_account_id` takes the expense account from the category product.

This change covers the row. It supplies the account that the expense claim
mapper of `ledger-posting-path` needs; posting itself is that change's handler.

## Affected Projects

- [ ] Project: `shillinq`: an `ExpenseCategory` schema with a settings page, a category picker on receipts, an account resolver, expense account settings, and corrected posting declarations on `ExpenseClaimEntry`.

## Scope

### In Scope

- `ExpenseCategory` per administration: code, name in Dutch and English, expense account, VAT deduction rule, optional default cost centre, active.
- Seeded categories mapped to the RGS template's expense accounts.
- `Receipt.category` chosen from the administration's active categories, with existing free-text values mapped once.
- One account resolver for an expense line: the receipt's confirmed `glAccount`, else its category's account; no account means the claim does not post and says which line lacks one.
- Administration expense settings for the mileage account, the per diem account and the expense payable account, replacing the `Account[...]` flag lookups the posting declarations use.
- The docudesk account suggestion shown next to the category's account, never applied without a person.

### Out of Scope

- The posting handler and its expense claim mapper (`ledger-posting-path`).
- Partial VAT deductibility rules (`tax-vat-rates-and-deductibility` in this OpenSpec pass); a category references a rule from there.
- Learning an account from past bookings.
- Categories on supplier invoices.

## Approach

`ExpenseCategory` is a declarative schema with a settings page. `Receipt.category`
keeps its name and holds a category code, with an enum-like relation filter on
the administration's active categories. `ExpenseAccountResolver` answers the
account for a receipt, a mileage entry or a per diem, and the `ExpenseClaimEntry`
posting declarations name the resolver instead of `Account[category=...]` and
the three `Account[is...=true]` flags, which no `Account` carries. Details are in
design.md.

## New Dependencies

None.

## Impact

- Schemas: `ExpenseCategory` added; `Receipt.category` gains a relation filter; administration settings gain three expense accounts (additive). The `accountLookup` and `creditMapping` values in the two `ExpenseClaimEntry.post` declarations change.
- Code: new `ExpenseAccountResolver`; a repair step mapping existing category text; the expense claim mapper of `ledger-posting-path` calls the resolver.
- Manifest: `ExpenseCategories` index and detail under the settings gear; the category picker on the receipt form.

## Cross-Project Dependencies

- docudesk: the account suggestion (`GlAccountSuggestionClient`) is shown as today; no change there.

## Risks

### Risk 1: Old receipts with categories that match nothing
**Severity:** Medium. **Mitigation:** the repair step maps exact codes and common Dutch and English names, sets the rest to `uncategorised`, and lists them; a claim with an `uncategorised` line without a confirmed account does not post and says so.

### Risk 2: A category pointed at the wrong account posts many claims wrongly
**Severity:** Medium. **Mitigation:** the category page shows the account's name and type next to the number and refuses an account that is not of type expenses; a change of account applies to claims posted after it, with the audit trail on the category.

## Rollback Strategy

Revert the PR. Categories stay as records; `Receipt.category` keeps its codes
as plain text, and the posting declarations return to their unresolvable
lookups.

## Open Questions

- Should a category also carry a spending limit for the approval step? Not quoted by any competitor in the matrix; left out.
