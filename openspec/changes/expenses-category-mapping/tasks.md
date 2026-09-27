# Tasks: expenses-category-mapping

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Categories

- [ ] 1.1 Add `ExpenseCategory` with its account guard and the three administration expense settings in `lib/Settings/register.d/expenses-category-mapping.json`, and seed the categories and settings for administrations on the RGS template (REQ-ECM-001). Verify: `npm run check:registers`, `npm run check:seeds`; PHPUnit for the guard with an expense and a balance sheet account.
- [ ] 1.2 Add `ExpenseCategories` index and detail under the settings gear with the account name shown next to the number (REQ-ECM-001). Verify: `npm run check:manifest`, `npm run check:nav-reachability`; Playwright for the software example.

## 2. Receipts

- [ ] 2.1 Put the relation filter on `Receipt.category` and the category picker on the receipt form (REQ-ECM-002). Verify: Vitest for the picker listing active categories only.
- [ ] 2.2 Add the repair step mapping existing category text and listing the unmatched receipts (REQ-ECM-002). Verify: PHPUnit for an exact code, a Dutch name, an English name and an unknown text.

## 3. Resolution and posting

- [ ] 3.1 Add `ExpenseAccountResolver` (confirmed account, category account, settings, null with reason) (REQ-ECM-003). Verify: PHPUnit for each branch.
- [ ] 3.2 Replace the `accountLookup` and `creditMapping` strings in both `ExpenseClaimEntry.post` declarations with the resolver reference, and have the expense claim mapper of `ledger-posting-path` call the resolver and refuse on null (REQ-ECM-003). Verify: PHPUnit for the S. de Vries claim balancing to EUR 100.08, and for an uncategorised line refusing the post.
- [ ] 3.3 Show the docudesk suggestion next to the resolved account on the receipt and claim pages (REQ-ECM-003). Verify: Playwright with a seeded suggestion that differs from the category account.

## 4. Docs

- [ ] 4.1 User guide section on expense categories and a release note listing the seeded mapping and the repair step's unmatched list. Verify: the page in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/expenses-category-mapping/tasks.md#task-N` on every new method, English source strings with Dutch translations for every category name.
