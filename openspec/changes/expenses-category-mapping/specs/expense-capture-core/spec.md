# expense-capture-core Specification (delta)

## Purpose

Expense categories name their ledger account, so a claim's lines resolve to
accounts and the claim posts without anyone typing account numbers. From
shillinq matrix row `exp-category-mapping`.

## ADDED Requirements

### Requirement: Expense categories name an expense account (REQ-ECM-001)

Shillinq SHALL keep per administration a list of expense categories, each with a
code, a Dutch and an English name, an expense account, a VAT deduction rule and
whether it is active,
editable on an Expense categories settings page. Saving a category MUST be
refused when its account does not exist in the administration's chart or is not
an expense account. New administrations on the RGS template SHALL receive a seeded
list.

#### Scenario: A bookkeeper maps software subscriptions

- GIVEN a bookkeeper on the Expense categories page of Adviesbureau Kade B.V.
- WHEN they set category Software en abonnementen to account 4340
- THEN the category shows 4340 Software en SaaS-abonnementen

#### Scenario: A balance sheet account is refused

- GIVEN the same page
- WHEN the bookkeeper sets a category to account 1100
- THEN the save is refused with the message that the account is not an expense account

### Requirement: A receipt takes its category from the list (REQ-ECM-002)

The receipt form SHALL offer the administration's active categories for
`Receipt.category`, and existing free-text categories SHALL be mapped once to a
category code, with those that match none set to uncategorised and listed for a
bookkeeper.

#### Scenario: Capturing a lunch receipt

- GIVEN a consultant capturing a receipt of EUR 37.50 for a lunch with a client
- WHEN they open the category field
- THEN they choose Maaltijden en representatie from the list rather than typing text

### Requirement: Each expense line resolves to an account in a fixed order (REQ-ECM-003)

When an expense claim posts, each receipt line SHALL take the account an operator
confirmed on the receipt, else its category's account; mileage and per diem lines
SHALL take the administration's mileage and per diem accounts, and the claim
total SHALL go to the administration's expense payable account. A line without
an account MUST stop the post with a message naming the line. A docudesk account
suggestion SHALL be shown next to the resolved account and MUST NOT replace it
without a person confirming it.

#### Scenario: A claim posts itself

- GIVEN an approved claim of S. de Vries with a train receipt of EUR 42.80 including EUR 3.53 VAT in Openbaar vervoer, a lunch receipt of EUR 37.50 in Maaltijden en representatie whose VAT is not deducted, and 86 km of mileage at EUR 0.23
- WHEN the bookkeeper posts the claim on its expense claim page
- THEN the posted transaction debits 4510 EUR 39.27, VAT receivable EUR 3.53, 4440 EUR 37.50 and 4530 EUR 19.78, and credits 2200 EUR 100.08

#### Scenario: An uncategorised receipt stops the post

- GIVEN a claim with one receipt left uncategorised and no confirmed account
- WHEN the bookkeeper posts it
- THEN the post is refused naming that receipt and the claim stays approved
