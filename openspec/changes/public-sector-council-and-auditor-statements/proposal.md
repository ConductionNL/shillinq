---
kind: code
depends_on: []
---

# Proposal: public-sector-council-and-auditor-statements

## Summary

Each year the college gives the council a lawfulness statement and an ENSIA statement, and the auditor works from an audit protocol with samples. Shillinq has the records and pages for all three and the arithmetic for some, but nothing computes the lawfulness paragraph, draws a sample or produces the ENSIA statement. This change adds those three steps.

## Motivation

On 2026-09-29 the build-all pass decided these `building` rows of the shillinq capability matrix (`openspec/parity/capabilities.json`) `decided-no`, because no bookkeeping competitor sells to government and so none rates them yes. Ruben reversed that the same day: municipalities are the market. The rows move back to `building` with `built.change` naming this change, and the change sits in the tender tier of the build queue.

**`pub-rechtmatigheid`**, "Write the lawfulness statement with tolerances and findings." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "src/manifest.d/bookkeeping-rechtmatigheidsverantwoording.json declares index pages for assessment, findings, tolerances, the paragraph and an audit export; no service under lib/Service computes the statement"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; the lawfulness statement not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; the lawfulness statement not stated
- odoo (no): grep for rechtmatigheid over odoo/odoo@19.0 addons and OCA/l10n-netherlands@19.0 finds nothing; no findings or tolerance model

**`pub-audit-protocol`**, "Run an audit protocol with samples and findings for the auditor." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "four index pages under /bado (src/manifest.d/bookkeeping-bado-controleprotocol.json); lib/Service/BadoControleprotocolService.php is reached only by /api/bado/controleprotocol/* (appinfo/routes.php:597-599), uncalled from src/"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; audit protocols with samples not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; audit protocols with samples not stated
- odoo (partial): https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting/annual-report.html audit report with Attestation, statements, annexes and supporting documents; odoo/odoo@19.0 addons/account/views/mail_message_views.xml:60 audit trail report Note: Audit file and trail for the auditor; no sampling or findings workflow

**`pub-ensia`**, "Run the ENSIA self-evaluation and prepare the council statement." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "ENSIA cycles, evaluations, findings and the college statement are manifest pages (src/manifest.json ENSIACycles..ENSIACollegeVerklaring); lib/Service/ENSIAVerklaringGenerator.php and ENSIABevindingGenerator.php have no caller in lib/"

- exact-online (unknown): not checked: searched exact.com/nl product pages (including /nl/branche/overheid, which lists only the generic business and accountancy products) and the Exact knowledge base today: no Dutch public sector function is described; Exact Online targets businesses and accountants; ENSIA not stated
- moneybird (unknown): not checked: searched helpcenter.moneybird.nl (573 articles), feature pages and API reference for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; Moneybird targets entrepreneurs (https://helpcenter.moneybird.nl/nl/articles/207349-voor-welke-bedrijven-is-moneybird-geschikt) and does not state public-sector support or its absence
- snelstart (unknown): not checked: searched Kennisplein (SnelStart 12, Web, Polaris), package pages and release notes for BBV, Iv3, SiSa, BCF, Fido, EMU, gemeente and related public-sector terms: no hits; SnelStart targets entrepreneurs, associations and foundations and does not state public-sector support or its absence
- twinfield (unknown): not checked: searched the Twinfield support portal (help articles, release notes 2025 and 2026) today: no Dutch public sector function is described; Twinfield targets accountants and businesses; ENSIA not stated
- odoo (no): grep for ENSIA over odoo/odoo@19.0 addons and OCA/l10n-netherlands@19.0 finds nothing

## What is already built

- `Rechtmatigheidstoets`, `Rechtmatigheidsbevinding`, `Rechtmatigheidsparagraaf`, `Tolerantiegrens` in `lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json` with index pages (`src/manifest.d/bookkeeping-rechtmatigheidsverantwoording.json`) and `lib/Lifecycle/RechtmatigheidGuard.php` (finalise, resolve, adopt, export checks). No service computes the paragraph's totals.
- `Controleprotocol`, `ToleranceMatrix`, `Materialiteit`, `AuditSample`, `AuditFinding`, `VerklaringDraft` in `lib/Settings/register.d/bookkeeping-bado-controleprotocol.json`; `BadoControleprotocolService` and `BadoControleprotocolCalculator` classify findings and derive the opinion, reached by `/api/bado/controleprotocol/aggregation` and `/accountantsdossier`, which nothing under `src/` calls. No code draws a sample.
- `ENSIAJaarcyclus`, `Evaluatievraag`, `Bevinding` with their lifecycles and manifest pages; `ENSIABevindingGenerator`, `ENSIAVerklaringGenerator` and `ENSIAXmlExporter` in `lib/Service/` have no caller.

## What this change adds

- The lawfulness paragraph is computed: total expenses including reserve mutations from the ledger, the tolerance amounts from the tolerance percentages, the sum of errors and uncertainties from the findings, and whether they stay within tolerance.
- Audit samples: a reproducible sample is drawn from a population of posted lines with a stored seed, and the protocol page shows the aggregation and the proposed opinion.
- ENSIA: moving a cycle to peer review creates the findings from the answers, moving it to college approval renders the statement, and submitting produces the XML for download.
