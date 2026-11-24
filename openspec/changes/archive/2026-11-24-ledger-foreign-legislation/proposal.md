---
kind: code
depends_on: [tax-vat-rates-and-deductibility]
---

# Proposal: ledger-foreign-legislation

## Summary

A Dutch group with a Belgian subsidiary wants that company's books in the
same environment, kept under Belgian rules. Every shillinq administration is
Dutch today: Dutch RGS charts, Dutch VAT rates, and a rule check that always
assumes the Netherlands. This change gives an administration a
jurisdiction, lets the rule check, the chart and the VAT rates follow it,
and ships Belgium as the first legislation pack.

## Motivation

One ledger row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27 (roadmap demand, an odoo yes, and the
ledger core area).

**`led-multi-legislation`**, "Keep a company's books under another country's
rules next to your Dutch companies in one environment." Rated no, built
state none. Matrix note: "Administrations are Dutch only: RGS templates
(lib/Service/SettingsService.php:182) and Dutch VAT; no ledger rules for
another country." Roadmap demand: https://www.exact.com/nl/vooruitblik
("Multi-legislation functionaliteit ... een volledige Belgische
administratie voeren volgens de Belgische wet en regelgeving"). odoo rates
yes (odoo/odoo@19.0 `addons/account/models/company.py:117`, a chart template
per company, "so each company in one database runs its own country
localization"). moneybird is partial
(https://helpcenter.moneybird.nl/nl/articles/207351-boekhouden-voor-niet-nederlandse-ondernemers,
"Aan de hand van het gekozen land worden de juiste btw-tarieven
toegevoegd").

## Affected Projects

- [ ] Project: `shillinq`: a jurisdiction per administration, jurisdiction-aware rule checks, chart and VAT rates, and the Belgian pack.

## Scope

### In Scope

- `Administration.jurisdiction` (ISO 3166 alpha-2, default NL).
- The rule check reading the administration's jurisdiction instead of a fixed NL.
- A legislation pack format: a chart template, a VAT rate set and the jurisdiction's rule selection.
- The Belgian pack: the Belgian minimum chart of accounts (MAR/PCMN) and the Belgian VAT rates 0, 6, 12 and 21 percent.
- The setup wizard offering the pack of the jurisdiction chosen.

### Out of Scope

- Filing another country's returns (Belgian Intervat, periodic VAT return grids). A pack can add a filing later.
- Payroll under another country's rules; payroll is humaniq's.
- Countries other than Belgium in this change.

## Approach

The jurisdiction sits on the administration; everything that assumed NL
reads it. A pack is data under `lib/Settings/seeds/`. Details are in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-multi-administratie.json`: `Administration.jurisdiction`.
- `lib/Lifecycle/RuleComplianceGuard.php`: `context()`.
- `lib/Service/SettingsService.php`: seeding a pack.
- `lib/Settings/seeds/`: the Belgian chart and rate set.

## Cross-Project Dependencies

None. `tax-vat-rates-and-deductibility` (this repo) makes VAT rates
configuration rather than a code constant; this change stores a rate set
per jurisdiction in that configuration, hence `depends_on`.

## Risks

### Risk 1: A Dutch-only check runs on a Belgian administration
**Severity:** Medium. **Mitigation:** the rule engine already filters by jurisdiction (`lib/Standards/RuleEngine.php:11`); the change feeds it the right one, and Dutch-only services (Dutch VAT return, SBR, ICP) refuse an administration whose jurisdiction is not NL instead of producing a Dutch file.

## Rollback Strategy

Set every administration's jurisdiction to NL. Belgian accounts stay as
ordinary accounts.

## Open Questions

None.
