# Design: ledger-booking-rules

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Account** (`lib/Settings/shillinq_register.json`, schema `Account`) has `accountNumber`, `accountType`, `description`, `vatApplicable`, `vatRate` and a few sector fields. Fragments add `isSuspenseAccount`, `isTreasuryAccount`, `rgsCode` and others. It has **no** control-account property. The matrix note says "Account carries isAPControlAccount/isExpenseControlAccount flags"; those names appear only inside two `materialise-gl-transaction` mappings as `Account[isAPControlAccount=true].accountNumber` (`shillinq_register.json:21258`) and `Account[isExpenseControlAccount=true].accountNumber` (`shillinq_register.json:10041`), and no schema declares them. This design corrects that reading.
- **Posting guards.** `JournalEntry.post` and `postDirect` require `JournalEntryGuard::canPost` (`lib/Lifecycle/JournalEntryGuard.php:94`), which checks line count and balance. `GLTransaction.post` requires `RuleComplianceGuard::validateTransaction` (`lib/Lifecycle/RuleComplianceGuard.php:100`, set by `register.d/add-shillinq-rule-compliance-guard.json`), which runs the rule catalogue and delegates balance to `BalanceGuard`.
- **Rule catalogue** (`lib/Standards/rules/*.json`, `SCHEMA.md`) holds laws and standards as versioned reference data, "not OpenRegister config". An organisation's own restrictions do not belong there.
- **Dimensions.** `GLLine` carries `costCenterCode`, `costCarrierCode`, `projectCode` and `dimensions` (`shillinq_register.json`, schema `GLLine`). `AnalyticalDimension` lives in `register.d/bookkeeping-cost-centers-dimensions.json` with `code`, `dimensionType`, `startDate`, `endDate`, `lifecycleState`.
- **Pages.** `ChartOfAccounts` and `ChartOfAccountsDetail` (`src/manifest.json:4511`, `:4774`) edit accounts. `Journals` and `JournalDetail` (`src/manifest.json:4346`, `:4391`) edit journal entries. `GeneralLedgerDetail` edits a transaction's lines.
- **Posting path.** These guards only matter once posting works: `ledger-posting-path` registers the handlers the post transitions need, hence `depends_on`.

## Goals / Non-Goals

**Goals**
- A person cannot post by hand on a control account.
- A person cannot post a line combining an account, cost centre and project that a restriction blocks.
- The bookkeeper sees an account's guidance while choosing it.

**Non-Goals**
- Required or allowed dimensions per account.
- Checking postings made by sub-ledgers.

## Decisions

### D1. `Account.controlAccountFor` replaces two undeclared flags

A nullable enum `controlAccountFor`: `receivables`, `payables`, `vat`,
`expense-claims`, `payroll`. The two mappings that read
`isAPControlAccount` and `isExpenseControlAccount` change to
`Account[controlAccountFor=payables]` and
`Account[controlAccountFor=expense-claims]`.

Alternative considered: declare the two booleans the mappings already name
and add three more. Rejected: five booleans that must be mutually exclusive
are one enum written badly, and a VAT control account has no flag at all
today.

### D2. A posting by a person is one whose source is not a sub-ledger

`JournalEntry` is always a person's posting (a memorial entry, or humaniq's
payroll journal, which uses the payroll control accounts it is allowed to:
see D3). A `GLTransaction` is a person's posting when it has no
`journalEntryId` and no `sourceReference`; transactions a handler
materialises always carry one of them.

### D3. The control check allows the owning sub-ledger

A line on an account with `controlAccountFor` set is refused unless the
posting comes from that sub-ledger. A journal entry whose `sourceApp` is
humaniq may post on `payroll` control accounts; nothing else may post on a
control account by hand. The refusal names the account and its control
role.

### D4. Restrictions are data, checked by one guard both post guards call

A new schema `PostingRestriction` (`administrationId`, `accountPattern`
(prefix match on `accountNumber`), `costCenterCode`, `projectCode`,
`reason`, `validFrom`, `validTo`, `lifecycleState`). A line matches when its
account starts with `accountPattern` and its cost centre and project equal
the restriction's (an empty value on the restriction matches any). A new
`lib/Lifecycle/PostingRestrictionGuard.php` evaluates both the control
check and the restrictions for a set of lines; `JournalEntryGuard::canPost`
and `RuleComplianceGuard::validateTransaction` delegate to it, the same way
`validateTransaction` already delegates to `BalanceGuard`, because a
transition takes one `requires` value.

Alternative considered: a rule in the rule catalogue. Rejected by the
catalogue's own contract (laws and standards, not an organisation's
configuration).

### D5. Guidance is the account's description, shown on the line

No new field. The line editors on `JournalDetail` and `GeneralLedgerDetail`
show the chosen account's `description` under the account select, and the
account select's options show it as secondary text. The field's schema
description changes from "Operator-authored free-text description" to
"When to use this account; shown to the bookkeeper on every booking line."

### Amended at build (29 Sep)

Read against the code at build time; the decisions above hold except:

- **D2.** Every posting needs a `sourceReference` (VAT Directive art. 244 is a mandatory rule under NL jurisdiction), so it cannot tell a person's posting apart. A `GLTransaction` is a sub-ledger's when it carries `journalEntryId` (its journal entry was checked already) or `journalCode` (the GR/IR and inventory posters, which leave drafts a person then posts). Everything else is a person's.
- **D3.** `JournalEntry` had no `sourceApp`; it is added (`bank`, `humaniq`). A bank booking by hand splits out VAT on 1230, so `ManualMatchService` marks its journal `bank`, and the bank may post on `vat` accounts. humaniq writes no `sourceApp` yet, so no payroll account gets a role by default; once humaniq sets it, marking 2120 as `payroll` is safe.
- **D5.** The detail pages have no line editor and the account field has no options list. A lines panel on both pages shows each line's account, its guidance under it and a control-account mark.
- **Refusal message.** A guard returns a bool and the adapter showed one fixed message. The booking rules throw `PostingRefusedException`, whose message the adapter shows as it stands.
- **Journal lines** gain `costCenterCode` and `projectCode`, carried to the GL line, so a restriction can see them on a journal entry.

**Seed and migration.** The RGS MKB seed carries no `rgsCode`, and its control accounts are 1100 Debiteuren, 2000 Crediteuren, 1230 BTW-vordering and 2110 BTW-schuld (the accounts `MaterialiseGlTransactionAction` books to), not 1300, 1600 and 1500. The seed sets the role on those four; `BackfillControlAccountRoles` sets it on existing accounts with that number and the seed's name.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which accounts are control accounts | Declarative: `Account.controlAccountFor` | Data on the record it describes. |
| Which combinations are blocked | Declarative data: `PostingRestriction` records | Per administration, edited on a page. |
| Refusing a posting | Imperative, inside the existing lifecycle guards | A guard is the declared `requires`; this adds a delegated check, not a new service. |
| Showing guidance | Declarative: manifest field config | Page configuration. |

## Seed Data

Adviesbureau Van Dijk (RGS MKB template):

| Account | Name | controlAccountFor |
|---|---|---|
| 1300 | Debiteuren | receivables |
| 1600 | Crediteuren | payables |
| 1500 | Te betalen btw | vat |
| 1610 | Te betalen declaraties | expense-claims |
| 4000 | Huisvesting | (none), description "Huur, energie en schoonmaak van het kantoor. Niet voor thuiswerkvergoedingen." |

Gemeente Voorbeeld (RGS BBV template), one `PostingRestriction`: account
pattern `4600` (subsidies verstrekt), cost centre `KP-100` (Bestuursondersteuning),
project `P-2026-014` (Sportakkoord), reason "Sportakkoord-subsidies lopen via
Sociaal Domein, niet via bestuursondersteuning", valid from 2026-01-01.

## Risks / Trade-offs

- [The `sourceApp` of a humaniq entry is spoofable by any caller that writes a JournalEntry] → the payroll exemption covers only `payroll` control accounts, and every JournalEntry write is already audit-trailed.
- [Prefix patterns are coarse] → they match how RGS numbers group; a list of exact accounts can be added later without changing the schema's meaning.

## Migration Plan

A repair step sets `controlAccountFor` on existing accounts whose `rgsCode`
is one of the RGS control codes (BVorDeb, BSchCre, BSchBtw), and logs every
account it sets. No posted data changes.

## Open Questions

None.
