---
kind: code
depends_on: []
---

# Proposal: public-sector-grant-accountability

## Summary

A municipality gives grants, receives specific grants from the state and spends EU funds, and must account for each. Shillinq keeps the grant and EU records but no code settles a grant, fills the SiSa appendix or builds an EU declaration. This change adds the three settlements on the grant and EU project screens.

## Motivation

On 2026-09-29 the build-all pass decided these `building` rows of the shillinq capability matrix (`openspec/parity/capabilities.json`) `decided-no`, because no bookkeeping competitor sells to government and so none rates them yes. Ruben reversed that the same day: municipalities are the market. The rows move back to `building` with `built.change` naming this change, and the change sits in the tender tier of the build queue.

**`pub-subsidies`**, "Keep grants given and received, including reclaims." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "SubsidiesOverzicht, SubsidieAanvragen and SubsidieTerugvorderingen are index pages; lib/Service/SubsidieVerantwoordingService.php has no caller in lib/"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; a grants register not stated
- moneybird (unknown): not checked: searched helpcenter for subsidie: no hits; a register of grants given and received with reclaims is not stated
- snelstart (unknown): not checked: searched Kennisplein for subsidie: no hits; a register of grants given and received with reclaims is not stated
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; a grants register not stated
- odoo (no): No grant or subsidy register in odoo/odoo@19.0 addons/account, addons/analytic or addons/l10n_nl (grep for subsid/grant finds only unrelated test text)

**`pub-sisa`**, "Produce the SiSa appendix for specific grants." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "SisaRapportages /sisa-rapportages lists records; lib/Service/SisaReportingService.php has no caller in lib/; the bzk-sisa connection is unavailable in lib/Settings/connections.json"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; SiSa not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; SiSa not stated
- odoo (no): grep for SiSa over odoo/odoo@19.0 addons and OCA/l10n-netherlands@19.0 finds nothing; no grant accountability model

**`pub-eu-funds`**, "Declare EU fund spending with its evidence and report irregularities." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "five index pages under /eu-fondsen (src/manifest.d/40-eu-fondsen.json); no service under lib/Service handles declarations"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; EU fund declarations not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; EU fund declarations not stated
- odoo (no): No EU fund declaration or irregularity report in odoo/odoo@19.0 addons; not in https://www.odoo.com/documentation/19.0/applications/finance/accounting.html table of contents

## What is already built

- `Subsidie` (direction, requested, granted, determined, paid out and reclaimed amounts, state) with index pages `/subsidies/overzicht`, `/subsidies/aanvragen`, `/subsidies/terugvorderingen`. `SubsidieVerantwoording` and `AuditorStatement` in `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`; `lib/Service/SubsidieVerantwoordingService.php` builds an accountability report and has no caller.
- `SisaReport` with the page `/sisa-rapportages` holds an audit summary per year (finding counts, opinion), not the SiSa appendix itself; `lib/Service/SisaReportingService.php` has no caller in `lib/`; the `bzk-sisa` connection is unavailable in `lib/Settings/connections.json`.
- `EuProject`, `EligibilityRule`, `SegregatedLedger`, `EuExpenditure` (declare, submit, certify), `SupportingDocument`, `IrregularityReport` in `lib/Settings/register.d/bookkeeping-single-audit-eu-fondsen.json` with guards `EuExpenditureGuard`, `SupportingDocumentGuard`, `IrregularityReportGuard`, and five index pages under `/eu-fondsen`. No code bundles expenditures into a declaration. The archived change `2026-06-14-bookkeeping-single-audit-eu-fondsen` never folded into a main spec.

## What this change adds

- Grant settlement: a determine action on a grant sets the determined amount, compares it with what was paid out and, for a grant given, writes a draft reclaim invoice for the difference; for a grant received, a draft payable. The accountability report is generated from the grant page.
- The SiSa appendix: per specific grant received, the indicators for the year (spending, income, output) from the grant's lines, shown on the grant and exported as the BZK appendix table for the annual accounts.
- An EU declaration: per project and period, the eligible expenditures with complete evidence are bundled into a declaration with the EU share; an expenditure without evidence is left out with the reason.
