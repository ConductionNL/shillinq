---
kind: code
depends_on: []
---

# Proposal: public-sector-quarterly-returns

## Summary

Every quarter a municipality claims VAT back from the BCF and reports its treasury limits under the Wet Fido. Shillinq computes the BCF claim but no screen reaches it, and it stores the Fido limits without computing them. This change puts both quarterly returns on their pages, computed from the books.

## Motivation

On 2026-09-29 the build-all pass decided these `building` rows of the shillinq capability matrix (`openspec/parity/capabilities.json`) `decided-no`, because no bookkeeping competitor sells to government and so none rates them yes. Ruben reversed that the same day: municipalities are the market. The rows move back to `building` with `built.change` naming this change, and the change sits in the tender tier of the build queue.

**`pub-bcf`**, "Claim VAT back from the VAT compensation fund (BCF)." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "lib/Service/BcfClaimService.php and BcfCompensationCalculator.php compute the claim, reached only by GET /api/bcf/compensation (appinfo/routes.php:458), which nothing under src/ calls"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; the BCF not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; the BCF not stated
- odoo (no): grep for BCF/compensatiefonds over odoo/odoo@19.0 addons finds nothing; addons/l10n_nl/data/template/account.tax-nl.csv has no BCF tax or claim

**`pub-fido`**, "Check the Wet Fido limits and file the quarterly treasury report." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "TreasuryDashboard /treasury/dashboard plus index pages for loans, derivatives, statutes and quarterly reports (src/manifest.d/bookkeeping-wet-fido-treasury.json); lib/Service/Treasury holds the calculations"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; Wet Fido not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; Wet Fido not stated
- odoo (no): grep for Fido over odoo/odoo@19.0 addons finds nothing; no kasgeldlimiet or renterisiconorm calculation

## What is already built

- `BcfClaim` (submit, accept, settle) and the index page `/overheid/bcf-claims`; `lib/Service/BcfClaimService.php::computeClaim` and `BcfCompensationCalculator` compute the compensable VAT from `BbvAccountMapping.bcfCompensable` and `compensablePercentage`; the only way in is `GET /api/bcf/compensation` (`appinfo/routes.php`), which nothing under `src/` calls.
- `Treasurystatuut`, `KasgeldLimiet`, `RenteRisicoNorm`, `Lening`, `Derivaat`, `QuartaalrapportageFido` in `lib/Settings/register.d/bookkeeping-wet-fido-treasury.json`, the treasury dashboard and index pages (`src/manifest.d/bookkeeping-wet-fido-treasury.json`), and `lib/Lifecycle/FidoTreasuryGuard.php` (record loan, record derivative, submit report). Nothing fills `KasgeldLimiet.currentExposure` or `headroom` or the report's status fields.

## What this change adds

- A compute action on a BCF claim fills the compensable total and the breakdown per account for the quarter; the claim page shows them and the claim can be submitted once the quarter is closed.
- A Fido quarter run computes the cash limit exposure and headroom from bank balances and short-term loans, and the interest risk norm from refinancing and rate revisions of long-term loans, and fills the quarterly report; the treasury dashboard shows both.
