# Tasks: tax-cbcr-and-pillar2-returns

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 17. -->

## 1. Schema

- [ ] 1.1 Add `lib/Settings/register.d/tax-cbcr-and-pillar2-returns.json` (ADR-037 fragment, never the monolith): on `GroupEntityRegistry` `administrationId`, `tin`, `tinIssuedBy`, `address`, `incorporationCountry`, `bizActivities` (enum `CBC501` to `CBC513`), `counterpartyRefs`; on `CbcrReturn` `reportingRole` (`CBC701` to `CBC704`), `correctsReturn`, `docRefIds`, `lastValidation`; on `GlobeInformationReturn` `docRefIds`, `lastValidation`, `missingElements`; on `Account` `cbcrCategory`; new schemas `GroupScopeAssessment` and `CbcrEntityFigures` per design D1 and D2, each property with a `title`, nl and en. Remove `x-openregister-threshold-watcher`, the three `x-openregister-export-target` blocks and the five `x-openregister-*-source` blocks from `bookkeeping-cbcr-pillar2.json` (REQ-CBC-001, REQ-CBC-002). Verify: `npm run check:registers`; a re-import logs no `PARTIAL IMPORT` and `failed.schemas` is empty.
- [ ] 1.2 Add a repair step that maps `mainBusinessActivity` into `bizActivities` and fills `tin` from the linked administration's `rsin`, with an `info.xml` version bump (REQ-CBC-002). Verify: PHPUnit; the repair run twice changes the seed records once.

## 2. XSD sets

- [ ] 2.1 Fetch the OECD CbC XML Schema v2.0 package (`CbcXML_v2.0.xsd`, `oecdcbctypes_v5.0.xsd`, `isocbctypes_v1.1.xsd`) and the GIR XML Schema package published with the January 2025 user guide (doi 10.1787/c594935a-en) from the OECD publication pages in a browser (the site returns 403 to scripts), into `lib/Standards/Xsd/oecd-cbc-v2.0/` and `lib/Standards/Xsd/oecd-gir-2025-01/`, each with a `SOURCE.md` giving URL, download date, the `targetNamespace` read from the main XSD, and SHA-256 per file. Read the OECD terms of use first and record in `SOURCE.md` that redistribution is allowed; if it is not, stop and report instead of committing the files (REQ-CBC-008, REQ-CBC-009). Verify: a PHPUnit test recomputes every checksum.

## 3. Services

- [ ] 3.1 Add `lib/Service/Cbcr/GroupScopeService.php` with `assess(upeId, fiscalYear)` per design D1: revenue from `ConsolidatedIncomeStatement` else entered, the one-year CbC rule, the two-in-four Pillar Two rule, the three deadlines with first-year terms, writing `GroupScopeAssessment` and `thresholdCrossed`/`thresholdCrossedAt` on the UPE (REQ-CBC-001). Verify: PHPUnit for the 720/805/790 series giving CbC in 2026 and Pillar Two first in 2027, and for a group under the threshold.
- [ ] 3.2 Add `lib/BackgroundJob/GroupScopeAssessmentJob.php` (weekly `TimedJob`) and the owner notification on a changed outcome, registered in `info.xml` (REQ-CBC-001). Verify: PHPUnit with the real `INotificationManager` interface mocked at the boundary; `occ background-job:list` shows the job after upgrade.
- [ ] 3.3 Add `lib/Service/Cbcr/CbcrFiguresService.php` with `readFromBooks(entityId, fiscalYear)` and `summarise(upeId, fiscalYear)` per design D2: the figure table, related-party resolution through `counterpartyRefs` and `IntercompanyJournalEntry`, `revenue-excluded`, year-not-closed handling, FX at average and closing rate from the assessment, headcount through the `HrmqCostRateAdapter` register resolution, skipping reconciled or locked summaries (REQ-CBC-002). Verify: PHPUnit on the Voorbeeld seed (unrelated EUR 300M, related EUR 40M), an unmapped account, a reconciled DE summary left alone.
- [ ] 3.4 Add `lib/Service/Cbcr/Pillar2SafeHarbourService.php` per design D4: the three tests with the 15/16/17% steps and the 2026/2028 window, draft computations for jurisdictions that pass none, the NL QDMTT return with amount and deadlines. Before coding, check the carve-out rate table against GloBE Model Rules art. 9.2 and Wet minimumbelasting 2024 and write the source in the docblock; if REQ-CBC-005's 2026 rates are wrong, say so in the PR body (REQ-CBC-006, REQ-CBC-009). Verify: PHPUnit per test pass and fail, a 2027 year with no safe harbour, the NL 12% case giving EUR 165,000.

## 4. Files

- [ ] 4.1 Add `lib/Reporting/Generator/CbcXmlGenerator.php` (`ReportGeneratorInterface`, type `cbc-report`) per design D3 with `DOMDocument::schemaValidate()` against the bundled set, no storage on failure, errors into `lastValidation`, schema version and checksum on the stored record; list it in `ReportCatalogue` under `tax` (REQ-CBC-008). Verify: PHPUnit renders the Voorbeeld 2026 report and validates it against the real XSD; a missing address country fails validation and stores nothing.
- [ ] 4.2 Add corrections to the CbC generator: `CBC402`, `OECD2` with `CorrDocRefId` for changed records only, `DocRefId` kept per record (REQ-CBC-013). Verify: PHPUnit for a one-jurisdiction correction validating against the XSD.
- [ ] 4.3 Add `lib/Reporting/Generator/GirXmlGenerator.php` (type `globe-information-return`) per design D5: FilingInfo with the Dutch TIN rule (RSIN padded, `issuedBy` NL, `GIR3001`), CorporateStructure, Summary and JurisdictionSection, the missing-element list written to `missingElements` and no rendering while it is not empty, XSD validation as in 4.1 (REQ-CBC-009). Verify: PHPUnit for an all-safe-harbour group validating against the real GIR XSD, and a computed IE jurisdiction listing its missing elements.

## 5. Checks and guards

- [ ] 5.1 Add `lib/Standards/Checks/CbcrChecks.php` and `GirChecks.php` with the checks and severities of design D7, registered with `RuleEngine`; make Generate XML refuse on a failing blocking check, naming it (REQ-CBC-012). Verify: PHPUnit per check, pass and fail.
- [ ] 5.2 Add `canSubmitCbcrReturn` and `canApproveGir` to `CbcrPillar2Guard` as the `requires` of `CbcrReturn.submit` and `GlobeInformationReturn.approve`, failing closed (REQ-CBC-012). Verify: PHPUnit with the real guard and lifecycle interfaces; a live submit without a valid file is refused.

## 6. Pages

- [ ] 6.1 Add `src/manifest.d/tax-cbcr-and-pillar2-returns.json` with the Group entities index and detail (tabs Figures, Scope, Account mapping, Audit trail; actions Assess scope, Read figures from the books, Enter figures, Import figures) under Taxes (REQ-CBC-011, REQ-CBC-001, REQ-CBC-002). Dialogs in `src/modals/`. Verify: `npm run check:manifest` and the nav reachability check pass; Playwright `tests/e2e/tax-cbcr.spec.ts` assesses 2026 for the Voorbeeld group and reads the Productie figures.
- [ ] 6.2 Add the Country-by-country reports index and detail (tabs Jurisdictions, Entities, Reconciliation, Checks, Files; actions Prepare from the books, Generate XML, Mark reconciled, Mark submitted with reference, Create correction) (REQ-CBC-011, REQ-CBC-008, REQ-CBC-013). Verify: Playwright generates and downloads the 2026 file and marks it submitted with CBC-2027-0001.
- [ ] 6.3 Add the Minimum tax returns index and detail (tabs Safe harbour, Computations, QDMTT, Checks, Files; actions Run safe harbour tests, Generate XML, Approve, Mark submitted), with the line "GloBE adjustments are entered here, not derived" on the computation page (REQ-CBC-011, REQ-CBC-006, REQ-CBC-009). Verify: Playwright runs the tests for 2026 and shows pass per jurisdiction.

## 7. Docs and l10n

- [ ] 7.1 Dutch and English strings for every label, action, check message and tab, and a user page under `docs/` on preparing and filing the CbC report and the minimum tax return, naming what the GIR does not cover yet (REQ-CBC-011). Verify: `npm run test:l10n` and the manifest copy-style gate pass.
- [ ] 7.2 Point the matrix row `tax-cbcr` at this change with evidence from the merged code, and set its state when the pages are reachable (REQ-CBC-011). Verify: `python3 -m json.tool openspec/parity/capabilities.json`.

Quality reminders (not tracked as tasks): `@spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-N` on every new method; scenario names quoted in the Playwright tests for the e2e coverage gate; `composer check:strict` once before push; no `_rbac: false` writes.
