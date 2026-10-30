# Tasks: iv3-report-takes-case-costs-by-case-type

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

- [ ] 1 `BbvTaakveld` overlay: `aggregatesUnder` (string, a code in the same catalogue) and `deprecated` (boolean); seed the 18 refinement codes with Dutch name, authored English label and parent, flag `6.71`, `6.72`, `6.81`, `6.82` deprecated (REQ-BBV-013). Verify: PHPUnit over the seed: every `aggregatesUnder` resolves, no code lacks `nameEn`.
- [ ] 2 `ClassifiedSubjectCostSource`: for a configured source and a period, list the objects with hours in the period, read each object's type and the type's taakveld, cost each through `SubjectCostService` (REQ-IV3-010). Verify: PHPUnit red first with two cases on `6.71` and `6.71a`, one incomplete cost, one type without taakveld.
- [ ] 3 Source configuration in `lib/Settings/` with the dossiq entry (`app: dossiq`, `register: dossiq`, `schema: case`, `typeProperty: caseType`, `taskFieldProperty: iv3TaskField`); an absent app gives a named empty state (REQ-IV3-011).
- [ ] 4 `Iv3ReportGenerator`: section and CSV `subjectCostsPerTaskField` next to the GL totals, never added to them (REQ-IV3-010). Verify: PHPUnit; GL totals identical with and without the source.
- [ ] 5 Report page (board ShOverheid): the case cost section with the incomplete and no-taakveld rows. Verify: vitest, `npm run check:manifest`.
- [ ] 6 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 7 Live: book hours on two dossiq cases whose types carry `6.71` and `6.71a`, open the Iv3 report for the quarter, see one row 6.71 with both; uninstall dossiq and see the named empty state.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
