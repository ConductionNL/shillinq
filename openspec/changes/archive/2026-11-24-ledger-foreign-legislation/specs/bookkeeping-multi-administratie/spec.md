# bookkeeping-multi-administratie Specification (delta)

## Purpose

An administration is kept under its own country's rules next to Dutch ones.
From shillinq matrix row `led-multi-legislation`.

## ADDED Requirements

### Requirement: An administration has a jurisdiction (REQ-LFL-001)

`Administration` SHALL carry `jurisdiction` (ISO 3166 alpha-2, default NL).
It MUST NOT change once the administration has a posted transaction.

#### Scenario: An accountant creates a Belgian administration

- GIVEN an accountant in the setup wizard for a new administration
- WHEN they choose jurisdiction BE
- THEN the wizard offers the Belgian legislation pack as the chart and rate template

#### Scenario: The jurisdiction is fixed after posting

- GIVEN a Belgian administration with a posted transaction
- WHEN an administrator tries to change its jurisdiction to NL on the administration detail page
- THEN the change is refused with the reason that postings exist

### Requirement: Rule checks follow the administration's jurisdiction (REQ-LFL-002)

The posting and invoice rule checks SHALL evaluate the rules of the
administration's jurisdiction plus EU and global rules, and SHALL NOT apply
Dutch-only rules to a non-Dutch administration.

#### Scenario: A Belgian invoice is checked against Belgian rules

- GIVEN an invoice in Van Dijk Services BV/SRL
- WHEN it is issued
- THEN the rule check runs the BE, EU and global rules and no NL-only rule

### Requirement: A legislation pack seeds the chart and VAT rates (REQ-LFL-003)

Shillinq SHALL ship a Belgian pack with the Belgian minimum chart of accounts
and the VAT rates 0, 6, 12 and 21 percent, and SHALL seed a new
administration of that jurisdiction from it.

#### Scenario: The Belgian chart and rates are present

- GIVEN a new administration seeded from the BE pack
- WHEN the bookkeeper opens its chart of accounts page
- THEN accounts 400000 Handelsdebiteuren and 451000 Te betalen btw are listed
- AND a sales line offers VAT rates 0, 6, 12 and 21 percent

### Requirement: Dutch-only outputs refuse other jurisdictions (REQ-LFL-004)

The Dutch VAT return, SBR and ICP outputs MUST refuse an administration whose
jurisdiction is not NL, with a message naming the jurisdiction.

#### Scenario: A Dutch VAT return is not produced for a Belgian company

- GIVEN Van Dijk Services BV/SRL with jurisdiction BE
- WHEN a user asks for a Dutch VAT return for it from the report dialog
- THEN the dialog refuses with a message that the administration is Belgian
