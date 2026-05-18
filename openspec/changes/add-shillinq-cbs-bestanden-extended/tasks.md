# Tasks — Extended CBS-bestanden

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-cbs-bestanden-extended` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no extended CBS-bestand aggregations, `kernGegevensConfig` schema, `ozbCategorie` flag, or `bookkeeping-cbs-bestanden-extended` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [ ] Task 2: Author `specs/bookkeeping-cbs-bestanden-extended/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-iv3-reporting` header, `REQ-CBSE-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table; BBV-reviewer persona confirms the four bestanden + Kerngegevens denominators match CBS-spec
- [ ] Task 5: Declare the small `kernGegevensConfig` admin-level schema in `lib/Settings/shillinq_register.json` with `inwonerAantal`, `oppervlak`, `gewogenOppervlak`, `bestuursOmvang` per REQ-CBSE-003
- [ ] Task 6: Add `ozbCategorie` array flag on `GLLine` (values: `eigenaars-woning`, `eigenaars-niet-woning`, `gebruikers-woning`, `gebruikers-niet-woning`) per REQ-CBSE-004
- [ ] Task 7: Declare the Iv3-detail aggregation grouping `GLLine` by `(periodId, taakveld, categorie)` summing `(debit - credit)` per REQ-CBSE-002; quarterly submission
- [ ] Task 8: Declare the Kerngegevens jaarstaten aggregation consuming the closed-year jaarrekening + `kernGegevensConfig` denominators per REQ-CBSE-003; annual submission; XML output
- [ ] Task 9: Declare the Iv3-OZB aggregation grouping OZB-postings by `(periodId, ozbCategorie)` per REQ-CBSE-004; CSV per CBS Iv3-OZB layout
- [ ] Task 10: Declare the EMU-bestand aggregation consuming the sibling EMU-reporting computation (ESA-2010 classifier + inclusion/exclusion rules); CBS EMU XML layout per REQ-CBSE-005; invariant test that EMU-bestand saldo equals EMU-reporting saldo (€0 tolerance)
- [ ] Task 11: Register 4 docudesk template references (Iv3-detail CSV, Kerngegevens XML, Iv3-OZB CSV, EMU-bestand XML) in `lib/Settings/docudesk-templates.json` per REQ-CBSE-001
- [ ] Task 12: Register 4 CBS openconnector source rows (Iv3-detail, Kerngegevens, Iv3-OZB, EMU-bestand) in `lib/Settings/openconnector-sources.json` per REQ-CBSE-006; no app-local HTTP client per ADR-019
- [ ] Task 13: Bind the periodic triggers — quarterly bestanden ride the OR `ScheduledWorkflow` primitive; annual bestanden trigger on jaarrekening-close lifecycle event from T3
- [ ] Task 14: Add CBS-bestanden navigation + per-bestand sub-pages to `src/manifest.json` (`featureFlags.gov-cbs-extended`, `Bookkeeping > CBS-bestanden`, sub-pages for Iv3-detail / Kerngegevens / Iv3-OZB / EMU-bestand with `type: detail` showing latest run + history) per REQ-CBSE-007; `node tests/validate-manifest.js` exits 0
- [ ] Task 15: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `kernGegevensConfig` + `ozbCategorie` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. BBV-
reviewer persona confirms each bestand matches the 2026 CBS spec
and walks through a worked example proving Iv3-detail sum across
categorieën equals base IV3 per taakveld + EMU-bestand equals
EMU-reporting computation. Architecture reviewer confirms ADR-019 +
ADR-022 + ADR-024 + ADR-031 + ADR-032 compliance (no app-local
CBS HTTP client; all transformations declarative). No source code
changes outside
`openspec/changes/add-shillinq-cbs-bestanden-extended/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for Iv3-detail invariant vs. base IV3
total, Kerngegevens ratio computation, OZB split correctness,
EMU-bestand equality with EMU-reporting (pre-declared on Tasks
7–10); Playwright MCP browser tests for the per-bestand sub-pages
(pre-declared on Task 14); `composer test` green at the implementing
PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors `docs/user-guide/bookkeeping/gov-cbs/extended-bestanden.md`
per ADR-030 journeydoc convention and commits screenshots per
sub-page to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `CBS-bestand`, `Iv3-detail`,
`Kerngegevens jaarstaten`, `Iv3-OZB`, `EMU-bestand`,
`Inwoner-aantal`, `Oppervlak`, `Heffingstijdvak`, `Eigenaars-deel`,
`Gebruikers-deel`, `Woning`, `Niet-woning`.
