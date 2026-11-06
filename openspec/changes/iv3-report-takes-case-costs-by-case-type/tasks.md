# Tasks: iv3-report-takes-case-costs-by-case-type

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

- [ ] 1 `BbvTaakveld` overlay: `aggregatesUnder` (string, a code in the same catalogue) and `deprecated` (boolean); seed the 18 refinement codes with Dutch name, authored English label and parent, flag `6.71`, `6.72`, `6.81`, `6.82` deprecated (REQ-BBV-013). Verify: PHPUnit over the seed: every `aggregatesUnder` resolves, no code lacks `nameEn`.
- [ ] 2 `ClassifiedSubjectCostSource`: for a configured source and a period, list the objects with hours in the period, read each object's type and the type's taakveld, cost each through `SubjectCostService` (REQ-IV3-010). Verify: PHPUnit red first with two cases on `6.71` and `6.71a`, one incomplete cost, one type without taakveld.
- [ ] 3 Source configuration in `lib/Settings/` with the dossiq entry (`app: dossiq`, `register: dossiq`, `schema: case`, `typeProperty: caseType`, `taskFieldProperty: iv3TaskField`); an absent app gives a named empty state (REQ-IV3-011).
- [ ] 4 `Iv3ReportGenerator`: section and CSV `subjectCostsPerTaskField` next to the GL totals, never added to them (REQ-IV3-010). Verify: PHPUnit; GL totals identical with and without the source.
- [ ] 5 Report page (board ShOverheid): the case cost section with the incomplete and no-taakveld rows. Verify: vitest, `npm run check:manifest`.
- [ ] 6 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 7 Live: book hours on two dossiq cases whose types carry `6.71` and `6.71a`, open the Iv3 report for the quarter, see one row 6.71 with both; uninstall dossiq and see the named empty state.
- [ ] 8 `lib/Reporting/Generator/SubjectCostsPerTypeReportGenerator.php`: group the rows of `ClassifiedSubjectCostSource` (task 2) by type for a month, quarter or year, with title, object count, hours, cost and incomplete objects; register it in `lib/Reporting/ReportCatalogue.php` (`id: subject-costs-per-type`, label "Zaakkosten per zaaktype", formats `csv`) (REQ-IV3-012). Verify: `tests/Unit/Reporting/SubjectCostsPerTypeReportGeneratorTest.php` red first with two types, one incomplete case and one type without taakveld; the period total equals the Iv3 case cost section total for the same rows.
- [ ] 9 Missing dossiq or humaniq in `SubjectCostsPerTypeReportGenerator` gives the named message and no figure, from the same check as task 3 (REQ-IV3-012). Verify: PHPUnit for both absent apps, asserting no amount and no EUR 0.00.
- [ ] 10 Report page and CSV (board pending, see screens.md): the per case type table with incomplete cases listed under their type, the export button and the named empty state; strings in `l10n/en.json` and `l10n/nl.json` (REQ-IV3-012). Verify: vitest, `npm run check:manifest`, `npm run test:l10n`.
- [ ] 11 Live: with the hours of task 7 plus a case of a second type, open "Zaakkosten per zaaktype" for the quarter, see one row per type whose total matches the Iv3 case cost section, export the CSV and compare; uninstall humaniq and see the named message.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
