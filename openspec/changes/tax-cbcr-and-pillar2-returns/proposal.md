---
kind: code
depends_on: []
---

# Proposal: tax-cbcr-and-pillar2-returns

## Summary

A group that crosses EUR 750 million in revenue has to file a
country-by-country report and, two years later, a GloBE information return.
Shillinq has the eight schemas for both and a lifecycle guard, and nothing a
tax director can open. This change builds the half a user touches: a yearly
scope test that says whether the group is in, figures per jurisdiction read
from the books of every administration in the group, a CbC XML file that
validates against the OECD schema before anyone can download it, the GloBE
information return and the Dutch top-up tax (QDMTT, "binnenlandse
bijheffing") to the extent the schemas carry the data, and three pages under
Taxes to review and file it all.

## Motivation

Matrix row **`tax-cbcr`**, "Detect when a group passes the EUR 750M
threshold and prepare the country-by-country report and Pillar 2 top-up
tax." Rated `no`, state `specified`. The row's evidence: "the tree holds
only declarations and a guard: lib/Settings/register.d/bookkeeping-cbcr-pillar2.json
declares 8 schemas ... and lib/Lifecycle/CbcrPillar2Guard.php:99-229 guards
four transitions. git grep -i 'cbcr|pillar2' over src/ finds no page, and
lib/ has no service for the EUR 750M threshold, the per-jurisdiction
aggregation or the CbCR, GIR and QDMTT XML exports." Ruben decided on
7 October 2026 (decision 84) to build the user-facing half under a new
change.

The delivered change is
`openspec/changes/archive/2026-06-14-bookkeeping-cbcr-pillar2`. It ticked
all 35 tasks; what it shipped is the register fragment, the guard, and
contract blocks (`x-openregister-threshold-watcher`,
`x-openregister-export-target`, five `*-source` blocks) that nothing reads.
Its manifest task says five menu entries and ten pages shipped; none are in
`src/`. This change keeps the schemas and the guard and replaces the
contract blocks with code that runs.

The filing obligations, read at the authority on 7 October 2026:

- CbC report: groups with consolidated revenue of EUR 750 million or more
  in the year before the reporting year file within 12 months of year end
  (Belastingdienst, Handleiding CbCNL deel 1 versie 4.1, 14 October 2025,
  https://odb.belastingdienst.nl/wp-content/uploads/2025/10/HL_CBCNL_4.1_Deel_1_Algemeen.pdf).
- Bijheffing-informatieaangifte (the Dutch GIR): groups with EUR 750
  million in at least two of the four preceding years (Wet minimumbelasting
  2024 art. 2.1) file within 15 months, 18 for the first year; the tax
  return for the bijheffing follows within 17 months, 20 for the first year
  (https://odb.belastingdienst.nl/bijheffing-informatieaangifte-bia/informatie-bijheffing-informatie-aangifte/).

## Affected Projects

- [ ] Project: `shillinq`: three new services, two report generators with
  XSD validation, one schema fragment that extends the eight CbCR schemas
  and `Account`, two new small schemas, three menu entries with their index
  and detail pages, and the bundled OECD XSD sets.

## Scope

### In Scope

- A yearly scope assessment per group: consolidated revenue per year, CbC
  in scope from the year before, Pillar Two in scope from two of the four
  years before, and the filing deadlines that follow.
- Figures per entity and per jurisdiction from the books: revenue from
  unrelated and from related parties, profit before tax, income tax paid
  and accrued, stated capital, accumulated earnings, employees, tangible
  assets. Entities whose books are not in Shillinq get the same figures
  entered or imported.
- The CbC XML file per OECD CbC XML Schema v2.0, validated against the
  bundled XSD before it is stored.
- The transitional CbCR safe harbour tests per jurisdiction, from the same
  figures, and the jurisdiction computation for jurisdictions that fail
  them.
- The GIR XML file per the OECD GIR XML Schema (January 2025 user guide),
  generated only when the records hold every element the XSD requires; the
  page lists what is missing otherwise.
- The QDMTT amount for the Netherlands, reported in the GIR's jurisdiction
  section and on a QDMTT return record with its payment deadline.
- Pages: Group entities, Country-by-country reports, Minimum tax returns,
  all under Taxes.

### Out of Scope

- Transport over Digipoort. Filing is marked by hand with the reference the
  Belastingdienst returns, as `tax-digipoort-filing` will later automate.
- The notification of the reporting entity (art. 29d Wet Vpb), which goes
  through https://gegevensportaal.net/cbc/aanmelden/ and has no file format.
- A machine-readable file for the bijheffing tax return itself. The
  Belastingdienst's BIA manual excludes it ("Deze handleiding gaat NIET
  over de belastingaangifte") and no XSD for it was found; see Open
  Questions.
- The full GloBE computation per constituent entity (the 35 adjustments per
  entity, deferred tax recast, election tracking). The schemas carry it per
  jurisdiction only; this change does not widen them to entity level.
- Consolidation itself. Figures are read from each administration's own
  ledger; eliminations stay where `bookkeeping-consolidation-commercial`
  puts them.

## Approach

Read the books per administration, write per-entity figures, sum them per
jurisdiction into the existing `CbcrJurisdictionSummary`, and render the
files from the stored records. Every number on a page and in a file comes
from a stored record, so what was reviewed is what is filed. Details in
design.md.

## New Dependencies

None in composer or npm. PHP's `DOMDocument::schemaValidate()` (ext-dom,
already required) validates the files. The OECD XSD sets are bundled as
data under `lib/Standards/Xsd/`.

## Impact

- `lib/Settings/register.d/tax-cbcr-and-pillar2-returns.json` (new): fields
  on `GroupEntityRegistry`, `CbcrReturn`, `Pillar2JurisdictionComputation`
  and `Account`; new schemas `GroupScopeAssessment` and `CbcrEntityFigures`.
- `lib/Service/Cbcr/GroupScopeService.php`, `CbcrFiguresService.php`,
  `Pillar2SafeHarbourService.php` (new).
- `lib/Reporting/Generator/CbcXmlGenerator.php`, `GirXmlGenerator.php`
  (new), registered in `ReportCatalogue`.
- `lib/Standards/Xsd/oecd-cbc-v2.0/`, `lib/Standards/Xsd/oecd-gir-2025-01/`
  (new).
- `src/manifest.d/tax-cbcr-and-pillar2-returns.json` (new): three entries
  under Taxes and their pages.
- The `x-openregister-threshold-watcher` and `x-openregister-export-target`
  blocks in `bookkeeping-cbcr-pillar2.json` are removed: the services
  replace the contracts they described.

## Cross-Project Dependencies

- OpenRegister: `ObjectService`, the lifecycle engine and the audit trail as
  they stand.
- humaniq (optional): headcount per administration through the existing
  `HrmqCostRateAdapter` resolution, which already knows both register
  slugs. Without humaniq the employee number is entered.
- `GLLine.signedAmount`, `accountClass` and `countsInResult`, delivered by
  the archived `2026-09-29-reporting-segment-results`, which the figures read.

## Risks

### Risk 1: Account mapping is incomplete
**Severity:** High. **Mitigation:** an account without a CbC category that
carries a balance or movement in the year is listed on the entity's figures
and fails the "all accounts mapped" check, which blocks generating the file.

### Risk 2: A group's figures are partly outside Shillinq
**Severity:** Medium. **Mitigation:** entities without an administration
take entered or imported figures, marked as such on every row, and the
reconciliation against the consolidated statements already required by
REQ-CBC-010 shows the gap.

### Risk 3: The XSD changes
**Severity:** Medium. **Mitigation:** the XSD set is a versioned folder with
its source URL and checksum in a `SOURCE.md`; a new version is a new folder
and a new generator constant, and the file records which version it was
validated against.

### Risk 4: A GIR that validates is still wrong
**Severity:** High. **Mitigation:** the GIR covers what the schemas hold and
says so on the page; the page shows "prepared by Shillinq for the
jurisdictions under safe harbour, review the rest with your adviser" until
entity-level computation exists.

## Rollback Strategy

Remove the fragment, the services, the generators and the manifest
fragment. The eight schemas and their records stay as they are.

## Open Questions

1. The Dutch bijheffing tax return (belastingaangifte). Its format is not in
   the BIA manual and no XSD was found on the ODB pages that are public. This
   change stores the amount and the deadline only. Ruben: ask the
   Belastingdienst (CBC-reporting@belastingdienst.nl or the ODB servicedesk)
   for the specification, or accept a PDF worksheet as the output.
2. The Dutch XSDs for the CbC report and the BIA sit behind an ODB account
   (both manuals, section 1.5). This change validates against the OECD sets.
   With an ODB account the Dutch sets can be added as a second validation
   pass.
