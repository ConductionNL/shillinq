# Design: ledger-foreign-legislation

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Administration** (`lib/Settings/register.d/bookkeeping-multi-administratie.json`) has `functionalCurrency`, `presentationCurrency`, `defaultLanguage`, `vatNumber`, `vatRegime`, `vatFilingFrequency`, `kvkNumber`, `rsin`, `legalForm` and more. It has no country or jurisdiction.
- **Rule check.** `RuleComplianceGuard::context()` (`lib/Lifecycle/RuleComplianceGuard.php:135`) returns `'jurisdiction' => 'NL'`; its docblock (line 128) calls this "the seam for per-administration jurisdiction resolution later". `RuleEngine` (`lib/Standards/RuleEngine.php:11`) applies a rule to its own country plus EU-wide and global rules. The catalogue (`lib/Standards/rules/*.json`) already carries rules for BE (15), DE (86), FR (59) and others.
- **Charts.** `SettingsService::seedRgsTemplate()` (`lib/Service/SettingsService.php:175`) seeds from `lib/Settings/seeds/rgs-3.5-mkb.json`, `rgs-3.5-zzp.json`, `rgs-bbv.json` and `rgs-decentraal-2025.json`, all Dutch.
- **VAT rates.** `lib/Service/VATCalculationService.php:31` holds `VALID_RATES` (0, 6, 9, 21) as a constant; `tax-vat-rates-and-deductibility` turns them into configuration.

## Goals / Non-Goals

**Goals**
- A Belgian administration books on a Belgian chart with Belgian VAT rates and is checked against Belgian, EU and global rules.
- Dutch-only outputs refuse a non-Dutch administration.

**Non-Goals**
- Foreign filings, foreign payroll, more countries now.

## Decisions

### D1. Jurisdiction on the administration

`Administration.jurisdiction`, ISO 3166 alpha-2, default `NL`, fixed once
the administration has a posted transaction (changing it later would put
existing postings under other rules).

### D2. The rule check reads it

`RuleComplianceGuard::context()` looks up the transaction's or invoice's
administration and returns its jurisdiction, NL when absent. No rule file
changes.

### D3. A pack is data

`lib/Settings/seeds/legislation/<cc>/`: `chart.json` (the same shape as the
RGS seeds), `vat-rates.json` (rate code, percentage, label, valid from) and
`pack.json` (country, name, the chart and rate files, the Dutch-only
services it replaces or disables). `SettingsService` seeds from the pack of
the chosen jurisdiction; NL keeps its existing RGS variants.

Alternative considered: a PHP class per country. Rejected: charts and rates
are data that accountants correct, and the RGS seeds are already data.

### D4. Dutch-only services refuse other jurisdictions

The Dutch VAT return generator, the SBR and ICP generators and the Dutch
fiscal checks check `jurisdiction === 'NL'` first and return a clear refusal
otherwise. The list is taken from `lib/Reporting/ReportCatalogue.php`
entries marked Dutch.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Jurisdiction | Declarative: a schema field with a lifecycle-free lock once posted (guard on update) | Data on the record. |
| Chart and rates per country | Declarative: seed data files | Reference data. |
| Rule selection | Existing engine, fed the right context | No new behaviour, only the right input. |
| Refusing Dutch-only outputs | Imperative, a check at the top of each generator | A precondition in existing code. |

## Seed Data

Holding Van Dijk B.V. (NL) and its subsidiary Van Dijk Services BV/SRL
(BE, Antwerpen, ondernemingsnummer 0123.456.789 as a placeholder): the
Belgian administration is seeded from the BE pack with, among others,
400000 Handelsdebiteuren, 440000 Leveranciers, 451000 Te betalen btw,
700000 Omzet, and VAT rates 0, 6, 12 and 21 percent.

## Risks / Trade-offs

- [The Belgian chart in the pack is incomplete] → the pack ships the legal minimum classes 1 to 7 and an accountant extends it like any chart.
- [A Dutch-only service is missed in D4] → the list is generated from the report catalogue and each entry gets a test that a BE administration is refused.

## Migration Plan

Existing administrations get `jurisdiction: NL` from a repair step. No
posted data changes.

## Open Questions

None.
