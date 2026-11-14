---
kind: code
depends_on: []
---

# Proposal: public-sector-task-field-allocation

## Summary

A municipality spreads its costs three ways: over BBV task fields, over the participants of a joint arrangement, and between public tasks and commercial activities. Shillinq stores the keys for all three but runs none of them. This change runs each allocation from the posted lines and shows the result on the screen that already lists the keys.

## Motivation

On 2026-09-29 the build-all pass decided these `building` rows of the shillinq capability matrix (`openspec/parity/capabilities.json`) `decided-no`, because no bookkeeping competitor sells to government and so none rates them yes. Ruben reversed that the same day: municipalities are the market. The rows move back to `building` with `built.change` naming this change, and the change sits in the tender tier of the build queue.

**`pub-bbv`**, "Keep a municipality's books under BBV with every line on a task field." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "BbvMapping /overheid/bbv-mapping lists mappings to taakvelden seeded from lib/Settings/seeds/bbv-taakvelden-*.json; src/components/Dashboard/BBVComplianceDashboard.vue:290 calls /api/bbv-dashboard, which reads materialised aggregation values (lib/Controller/BBVDashboardController.php:12)"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; BBV and task fields not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; BBV and task fields not stated
- odoo (partial): odoo/odoo@19.0 addons/analytic/models/analytic_plan.py:77 default_applicability with 'mandatory' (:80) per plan and applicability rules (:87), so a 'taakveld' plan can be required on every line Note: Generic mandatory analytic plan; no BBV task field list, no BBV chart or reports; grep for BBV/taakveld over the clone finds nothing

**`pub-gr`**, "Split costs over the participants of a joint arrangement." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "GRDeelnemers, GRVerdeelsleutels and GRConsolidated are index pages in src/manifest.json over lib/Settings/register.d/add-shillinq-bookkeeping-gr-consolidation.json"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; joint arrangements not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; joint arrangements not stated
- odoo (partial): odoo/odoo@19.0 addons/account/models/account_analytic_distribution_model.py distribution models split a cost by percentage over analytic accounts, one per participant Note: Percentage split only; recharging participants means hand-made invoices, no joint arrangement model

**`pub-market-separation`**, "Separate commercial activities from public tasks and set integral cost prices." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "eight index pages under /wmo (src/manifest.d/bookkeeping-market-government-separation.json); lib/Service/IntegralCostPriceCalculator.php and CrossSubsidyDetector.php have no caller in lib/"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; market separation and integral cost prices not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; market separation and integral cost prices not stated
- odoo (no): grep for markt en overheid or integrale kostprijs over odoo/odoo@19.0 addons finds nothing Note: Activities can be tagged analytically, but no integral cost price calculation

## What is already built

- `BbvAccountMapping` maps every account to a task field (`lib/Settings/register.d/add-shillinq-bookkeeping-operations.json`, overlay `add-shillinq-bbv-compliance.json`), seeded from `lib/Settings/seeds/bbv-taakvelden-*.json`. `GLTransaction.post` refuses an unmapped line for a municipal administration (`lib/Lifecycle/BbvComplianceGuard.php::allLinesMappedForMunicipalAdmin`). The BBV dashboard (`src/components/Dashboard/BBVComplianceDashboard.vue`) reads `/api/bbv-dashboard` (`lib/Controller/BBVDashboardController.php`).
- `GRDeelnemer` (participant, share) and `GRVerdeelsleutel` (key over cost clusters) with index pages `/gr/deelnemers` and `/gr/verdeelsleutels`; `/gr/geconsolideerd` lists GL lines. No code applies a key.
- `CommercialActivity`, `IntegralCostPrice`, `ActivityCostAllocation`, `AlertLog` and eight index pages under `/wmo`. `lib/Service/IntegralCostPriceCalculator.php`, `CrossSubsidyDetector.php` and `IntegralCostPriceLockService.php` hold the arithmetic; nothing in `lib/` calls them.

## What this change adds

- Realisation per task field: the BBV dashboard shows expenses and income per task field for a year from posted GL lines through the account mapping, and lists accounts that carry postings but no mapping.
- A joint arrangement allocation run: for a period and a key, the costs on the key's cost clusters are split over the active participants by share, stored as a run with one line per participant, and each line can be turned into a draft contribution invoice.
- Integral cost price: a calculate action on a commercial activity fills `IntegralCostPrice` for a period through `IntegralCostPriceCalculator`, and a daily job runs `CrossSubsidyDetector` and writes an `AlertLog` per signal.
