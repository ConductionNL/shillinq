# bookkeeping-chart-of-accounts Specification (delta)

## Purpose

Control accounts are declared on the account and closed to posting by hand,
and every account can tell the bookkeeper when to use it. From shillinq
matrix rows `led-control-account-lock` and `led-account-guidance`.

## ADDED Requirements

### Requirement: An account declares the sub-ledger that owns it (REQ-LBR-001)

`Account` SHALL carry a nullable `controlAccountFor` (`receivables`,
`payables`, `vat`, `expense-claims`, `payroll`). The posting mappings that
select a control account SHALL select it by this field.

#### Scenario: An administrator marks the VAT account as a control account

- GIVEN an administrator on the chart of accounts detail page of account 2110 BTW-schuld
- WHEN they set control account for to vat and save
- THEN the account shows control account for vat

### Requirement: A person cannot post by hand on a control account (REQ-LBR-002)

When a journal entry or a general ledger transaction made by a person is
posted, the post guard MUST refuse it if any line is on an account with
`controlAccountFor` set, unless the posting comes from that sub-ledger. The
refusal SHALL name the account and its control role. Postings materialised
by a sub-ledger SHALL NOT be refused by this check.

#### Scenario: A bookkeeper tries a memorial entry on receivables

- GIVEN a draft journal entry on the journal detail page with a debit line on 1100 Debiteuren and a credit line on 8000 Netto-omzet
- WHEN the bookkeeper presses Post without approval
- THEN the post is refused with a message naming 1100 Debiteuren as the receivables control account
- AND the entry stays in draft

#### Scenario: A posted sales invoice still books receivables

- GIVEN an issued sales invoice whose posting materialises a debit on 1100 Debiteuren
- WHEN the invoice is posted
- THEN the ledger transaction is written with the line on 1100

#### Scenario: A bank booking posts its VAT line

- GIVEN a bank line booked by hand to 4910 with 21 percent VAT
- WHEN the booking is confirmed
- THEN the journal entry posts with its VAT line on 1230 BTW-vordering

### Requirement: The booking line shows the account's guidance (REQ-LBR-003)

The lines on the journal detail page and the general ledger detail page
SHALL show each line's account with its `description` under it, and SHALL
mark a control account.

#### Scenario: A bookkeeper sees when to use the housing account

- GIVEN account 4000 Huisvesting with the description "Huur, energie en schoonmaak van het kantoor. Niet voor thuiswerkvergoedingen."
- WHEN a bookkeeper opens a journal entry with a line on 4000
- THEN that description is shown under the account on the line
