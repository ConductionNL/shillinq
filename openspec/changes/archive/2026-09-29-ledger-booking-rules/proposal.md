---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: ledger-booking-rules

## Summary

Bookkeepers book on the wrong accounts in two ways a tender names: they post
by hand on a control account (VAT, receivables, payables) that only the
sub-ledgers may touch, and they combine an account, cost centre and project
that the organisation does not allow together. This change blocks both at
post time and shows each account's own guidance on the booking line, so the
bookkeeper sees when to use an account before choosing it.

## Motivation

Three ledger rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). Ledger is a core
area of the matrix; the OpenSpec pass of 2026-09-27 decided `build` for all
three (`openspec/parity/gap-decisions.json`).

**`led-control-account-lock`**, "Block control accounts such as VAT,
receivables and payables from manual posting." Rated no, built state none.
Matrix note: "no guard in lib/Lifecycle or lib/Guard rejects a manual
journal line on a control account." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. No competitor is
rated yes; odoo is rated no (odoo/odoo@19.0
`addons/account/models/account_move_line.py:1478` "only checks archived
accounts and currency").

**`led-dimension-block`**, "Block combinations of account, cost centre and
project that may not be booked together." Rated no, built state none. Matrix
note: "no rule set forbids combinations of account, cost centre and
project." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. Three competitors
are partial: exact-online
(https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-costanalysis-costcenter-fincctr-linkglacctt?language=en_GB,
"Specify which cost centres or cost units an employee can or must select"),
twinfield
(https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/dimensies-koste-3041118,
"Verdere analyse op dimensie 2 ... is niet toegestaan") and odoo
(`addons/analytic/models/analytic_plan.py:77-80`, applicability mandatory or
unavailable per account prefix). None of them blocks a specific combination
of cost centre and project.

**`led-account-guidance`**, "Add guidance to each ledger account explaining
when to use it." Rated partial, built: "A description field, not guidance
shown when booking." Changelog demand:
https://www.odoo.com/odoo-19-release-notes. odoo is rated yes
(`addons/account/models/account_account.py:34`, release note "Add
descriptions on accounts to explain when to use each account").

## Affected Projects

- [ ] Project: `shillinq`: an account's control role, a posting restriction record, two checks inside the existing post guards, and the account guidance on the booking lines.

## Scope

### In Scope

- `Account.controlAccountFor`, declaring which sub-ledger owns a control account.
- A `PostingRestriction` record: an account pattern with a cost centre and a project that may not be booked together, per administration.
- Both checks inside `JournalEntryGuard::canPost` and `RuleComplianceGuard::validateTransaction`, for postings a person makes.
- The account's `description` shown under the account on every booking line.

### Out of Scope

- Allowed or required dimensions per account (twinfield's "verplicht" setting). The tender row asks to block combinations; required dimensions is another row if it is ever raised.
- Postings the sub-ledgers make through `materialise-gl-transaction`. They are the reason control accounts exist and stay allowed.

## Approach

Declare the control role on the account, keep restrictions as data per
administration, and add both checks where posting is already guarded. The
account guidance reuses the existing `Account.description`. Details are in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/`: one new fragment for `Account.controlAccountFor` and the `PostingRestriction` schema.
- `lib/Lifecycle/JournalEntryGuard.php`, `lib/Lifecycle/RuleComplianceGuard.php`: one delegated check each.
- The `materialise-gl-transaction` mappings that read `Account[isAPControlAccount=true]` and `Account[isExpenseControlAccount=true]` read the new field.
- `src/manifest.json`: the Journals, JournalDetail and GeneralLedgerDetail line editors show the guidance.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Existing manual entries on control accounts stop posting
**Severity:** Medium. **Mitigation:** only drafts are checked; posted entries are never re-validated. The setup wizard seeds `controlAccountFor` only on the RGS control accounts, and an administrator can clear it.

### Risk 2: A restriction nobody can see blocks a posting
**Severity:** Low. **Mitigation:** the refusal names the restriction and its reason, and restrictions have their own settings page.

## Rollback Strategy

Remove the two delegated checks. The new field and schema stay as inert data.

## Open Questions

None.
