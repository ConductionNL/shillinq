# Design: tax-vat-rates-and-deductibility

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The calculator.** `VATCalculationService`
(`lib/Service/VATCalculationService.php:38`) holds
`VALID_RATES = [0.0, 6.0, 9.0, 21.0]`; `isValidRate()` (line 116) checks a
line against it, and `calculateVAT()` defaults a missing rate to 21. Its
only caller is `InvoiceGenerationService`
(`lib/Service/InvoiceGenerationService.php`), which generates invoices from
time and expense. The 6 percent rate has not been statutory since 2019.

**The tariff register that already exists, empty.** `bookkeeping-vat-btw-filing`
REQ-VBTW-003 says rates "SHALL be loaded as a register, not hard-coded as
enums". `VatTariff` is declared three times: in
`lib/Settings/shillinq_register.json:18152` (`code`, `rate`, `description`,
`category`, `section`, `effectiveFrom`, `effectiveTo`, `defaultAccounts`),
in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json:566`
(`code`, `label`, `ratePercentage`, `rgsAccountHint`, `reverseCharge`,
`effectiveFrom`, `effectiveTo`, `legalBasis`), and as an audit-trail block
in `add-shillinq-audit-trail.json:785`. The seed file
`lib/Settings/seeds/btw-tariffs-2026.json` exists and carries both shapes'
fields. But `SettingsService::seedBtwTariffs()`
(`lib/Service/SettingsService.php:561`) asks `seedGenericFile()` for
`vat-tariffs-2026.json`, which does not exist, and `seedGenericFile()`
returns "Seed file not found" (line 1080). It is called from
`SetupController.php:261` and `Repair/InitializeSettings.php:1060`. So on
every install the register is empty. No page shows `VatTariff`. The matrix
note ("the rates are a code constant, not configurable") is right about the
calculator and misses the register.

**Lines and accounts.** Cost lines carry a free-text `taxCode`
(`APTransaction.lines[].taxCode`, "e.g. BTW21, BTW9, BTW0, VERLEGD";
`CommitmentLine.vat_code`; `RecurringInvoiceProfile.lines[].vatCode`).
`Account` (`shillinq_register.json:1340`) has `vatApplicable` and `vatRate`.
`GLLine.taxTreatment` (normal, deductible, nonDeductible, special) is
merged in by `register.d/bookkeeping-vpb-corporate-tax.json:15`, and
`TaxReportCalculator` (`lib/Service/TaxReportCalculator.php:202`) adds
`nonDeductible` lines back on the tax return. Nothing writes a split.

## Goals / Non-Goals

**Goals**

- The tariffs are in the register on every install, visible, and extendable with dates.
- Every VAT calculation uses the tariff valid on the document's date.
- A mixed cost is booked in two parts with the right input VAT, without typing two lines.

**Non-Goals**

- The VAT return mapping, which `tax-vat-return-from-books` owns.
- Per-administration overrides of statutory rates (REQ-VBTW-003 forbids them).

## Decisions

### D1. Fix the seed and settle one `VatTariff` shape

`seedBtwTariffs()` reads `btw-tariffs-2026.json`. The `register.d` shape
becomes the one declaration, with `section` (the return box) and
`defaultAccounts` from the monolith shape added to it; the monolith
declaration is removed. Seeded records are marked `statutory` and cannot be
edited or deleted; an administrator may add a tariff with its own code and
dates.

Alternative considered: keep both shapes. Rejected: the seed already writes
both names for the same values, which is how the two drifted.

### D2. A resolver, and no default rate

`VatTariffResolver::forCode(code, date)` returns the tariff whose code
matches and whose dates contain the date. `VATCalculationService` takes a
tariff code per line instead of a bare rate, asks the resolver, and throws
for an unknown or expired code. The legacy codes on existing lines (BTW21,
BTW9, BTW0, VERLEGD) are mapped once to the seeded codes (high, low, zero,
reverse-charge) by the same resolver.

Alternative considered: keep a rate per line and validate it against the
register. Rejected: a rate does not say whether a 0 is the zero rate,
exempt or reverse charge, and the return needs to know.

### D3. The split is a percentage, defaulted from the account

`Account` gets `deductiblePercentage` (0 to 100, default 100) and
`privateShareAccountNumber` (for example 1810 Privé-opnames for a sole
trader, or a non-deductible cost account for an organisation). A cost line
on `JournalEntry` and `APTransaction` gets an optional
`deductiblePercentage` that overrides the account's. When the posting
mappers write a cost line with a percentage below 100, they write: the
deductible share of the net amount to the cost account, the input VAT on
that share to the input VAT account, and the private share of the net
amount plus the VAT on it to the private-share account with
`taxTreatment` nonDeductible. The creditor side is unchanged.

Alternative considered: a separate "fiscal split" rule schema, as
moneybird names it. Rejected for now: one percentage per account covers
the cases the rows name, and the line override covers the rest.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which tariffs exist and when they apply | Declarative `VatTariff` register with seed | REQ-VBTW-003 says so. |
| Choosing a tariff for a line and date | Imperative, `VatTariffResolver` | A dated lookup with a legacy-code map. |
| The deductible percentage | Declarative fields on `Account` and cost lines | Plain data. |
| Writing the split | Imperative, inside the posting mappers | The mappers are the one writer of posted lines. |

## Seed Data

The existing `btw-tariffs-2026.json` (high 21, low 9, zero 0, exempt,
reverse-charge) is loaded. Its records carry no `section` today; the seed
gains the return box per tariff for output tax, which
`tax-vat-return-from-books` reads. Added fields: `VatTariff.statutory`,
`Account.deductiblePercentage`, `Account.privateShareAccountNumber`,
`deductiblePercentage` on cost lines. Test data for "Bakkerij De Korenbloem"
(a sole trader): account 4520 Autokosten at 70 percent deductible with
private share to 1810 Privé-opnames; a fuel invoice of EUR 121.00 (EUR
100.00 plus EUR 21.00 VAT).

## Risks / Trade-offs

- [Lines with unknown legacy codes] → the resolver refuses them and names the code; the release note lists the mapped codes.
- [Organisations have no private share] → for them the second account is a non-deductible cost account; the field name says share, not private withdrawal.

## Migration Plan

The repair step seeds the tariffs on upgrade. No other data changes.

## Open Questions

None.
