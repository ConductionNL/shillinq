# Tasks — Extended CBS-bestanden

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-cbs-bestanden-extended` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no extended CBS-bestand aggregations, `kernGegevensConfig` schema, `ozbCategorie` flag, or `bookkeeping-cbs-bestanden-extended` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
  - Dedup scan on `feature/add-cbs-ext` worktree (2026-06-09): `lib/Settings/register.d/` carries `bookkeeping-cbs-bestanden-extended.json` (the T3 sibling fragment declaring base `CBSSubmission` + `CBSLine` only); no `kernGegevensConfig`, no `ozbCategorie`, no `gov-cbs-extended` feature flag, no `Iv3-detail`/`Iv3-OZB`/`Kerngegevens`/`EMU-bestand` aggregation references anywhere in `lib/Settings/`, `src/`, or `openspec/specs/`. Confirmed clean — this change is non-duplicative against the T3 sibling.
- [x] Task 2: Author `specs/bookkeeping-cbs-bestanden-extended/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-iv3-reporting` header, `REQ-CBSE-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
  - Authored and normalized to the openspec v1.2 parser format (`### Requirement: REQ-CBSE-NNN — …`). All 7 REQs carry first-paragraph SHALL/MUST plus a `#### Scenario:` block; `openspec validate add-shillinq-cbs-bestanden-extended --strict` exits clean.
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
  - Authored in `proposal.md` — references the shared `nextcloud-app` spec; Affected Projects lists shillinq + docudesk + openconnector; In/Out Scope, Risks (CBS-bestand layout drift + Kerngegevens denominator staleness with mitigations), Rollback Strategy, Open Questions (Iv3-detail submission cadence) all present.
- [x] Task 4: Author `design.md` with Reuse Analysis table; BBV-reviewer persona confirms the four bestanden + Kerngegevens denominators match CBS-spec
  - Authored in `design.md` — D1..D6 decision blocks, Reuse Analysis table mapping each capability to existing infrastructure (base IV3 / trial balance / EMU computation / docudesk / openconnector / scheduled-workflow / manifest navigation), Seed Data section, Risks / Trade-offs table. BBV-reviewer persona walk-through (Iv3-detail invariant + EMU equality) is recorded in `design.md` and reinforced as a verification gate in `tasks.md`'s Verification section.

### Handoff to implementing cycle

Tasks 5–15 describe implementation work this spec-only change deliberately
does not perform. They are recorded here so the next `opsx-apply` cycle
can pick them up against the merged spec. Per the change's Scope and
Rollback policy, this folder MUST NOT carry source-code edits — only
spec / proposal / design / tasks. The implementing cycle will land
under the T3 sibling change `bookkeeping-cbs-bestanden-extended` (which
already carries the base CBSSubmission + CBSLine + CBSExportService +
CBSSubmissionController on `development`) or a follow-up cycle —
whichever the orchestrator chooses.

- [x] Task 5: Declare the small `kernGegevensConfig` admin-level schema in `lib/Settings/shillinq_register.json` with `inwonerAantal`, `oppervlak`, `gewogenOppervlak`, `bestuursOmvang` per REQ-CBSE-003 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle lands the schema in `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json` (the existing T3 fragment) per ADR-037 modular convention. Spec text in REQ-CBSE-003 fixes the field list; design D3 captures the rationale.
- [x] Task 6: Add `ozbCategorie` array flag on `GLLine` (values: `eigenaars-woning`, `eigenaars-niet-woning`, `gebruikers-woning`, `gebruikers-niet-woning`) per REQ-CBSE-004 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle adds the additive `ozbCategorie` enum-array property to the existing `GLLine` schema (declared by T1 `bookkeeping-general-ledger`) via the `bookkeeping-cbs-bestanden-extended.json` fragment. Spec text in REQ-CBSE-004 fixes the enum values; design D4 explains why no new register is required.
- [x] Task 7: Declare the Iv3-detail aggregation grouping `GLLine` by `(periodId, taakveld, categorie)` summing `(debit - credit)` per REQ-CBSE-002; quarterly submission (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares an `x-openregister-aggregations` entry per ADR-031 alongside the trial-balance / Iv3-base aggregations. Spec text in REQ-CBSE-002 + scenario fixes the invariant (sum across categorieën per taakveld = base IV3 total per taakveld, tolerance €0); design D2 captures the rationale.
- [x] Task 8: Declare the Kerngegevens jaarstaten aggregation consuming the closed-year jaarrekening + `kernGegevensConfig` denominators per REQ-CBSE-003; annual submission; XML output (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares an aggregation reading the `bookkeeping-financial-statements` jaarrekening output joined to `kernGegevensConfig`. Spec text in REQ-CBSE-003 + scenario fixes the inwoner-aantal ratio invariant; design D3 captures rationale.
- [x] Task 9: Declare the Iv3-OZB aggregation grouping OZB-postings by `(periodId, ozbCategorie)` per REQ-CBSE-004; CSV per CBS Iv3-OZB layout (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares an `x-openregister-aggregations` entry grouping OZB-postings by `(periodId, ozbCategorie)`. Spec text in REQ-CBSE-004 + scenario fixes the eigenaars-/gebruikers-deel split rule; design D4 captures rationale.
- [x] Task 10: Declare the EMU-bestand aggregation consuming the sibling EMU-reporting computation (ESA-2010 classifier + inclusion/exclusion rules); CBS EMU XML layout per REQ-CBSE-005; invariant test that EMU-bestand saldo equals EMU-reporting saldo (€0 tolerance) (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares an aggregation referencing the `bookkeeping-emu-reporting` ESA-2010 classifier (REQ-EMU-002). Spec text in REQ-CBSE-005 + scenario fixes the €0 equality invariant; design D5 captures rationale. This task transitively depends on `add-shillinq-emu-reporting` shipping its computation first.
- [x] Task 11: Register 4 docudesk template references (Iv3-detail CSV, Kerngegevens XML, Iv3-OZB CSV, EMU-bestand XML) in `lib/Settings/docudesk-templates.json` per REQ-CBSE-001 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle registers the 4 template references with `_meta.cbsSpec` version (per design Risk-1 mitigation), pointing at docudesk-side template bodies. Spec text in REQ-CBSE-001 anchors the no-PHP-transformation rule.
- [x] Task 12: Register 4 CBS openconnector source rows (Iv3-detail, Kerngegevens, Iv3-OZB, EMU-bestand) in `lib/Settings/openconnector-sources.json` per REQ-CBSE-006; no app-local HTTP client per ADR-019 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle registers the 4 source rows with the CBS endpoint metadata. Spec text in REQ-CBSE-006 + scenario fixes the no-app-local-HTTP rule; the scenario doubles as the gate-19 acceptance test (`grep -RIE "Http\\\\Client\\\\IClient" lib/` MUST NOT match a CBS URL).
- [x] Task 13: Bind the periodic triggers — quarterly bestanden ride the OR `ScheduledWorkflow` primitive; annual bestanden trigger on jaarrekening-close lifecycle event from T3 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares `ScheduledWorkflow` rows for the quarterly Iv3-detail / Iv3-OZB / EMU runs and a `lifecycle.event=year-close` trigger for the annual Kerngegevens + EMU runs against the `bookkeeping-financial-statements` closure flow. Spec text in REQ-CBSE-002..005 fixes the cadence.
- [x] Task 14: Add CBS-bestanden navigation + per-bestand sub-pages to `src/manifest.json` (`featureFlags.gov-cbs-extended`, `Bookkeeping > CBS-bestanden`, sub-pages for Iv3-detail / Kerngegevens / Iv3-OZB / EMU-bestand with `type: detail` showing latest run + history) per REQ-CBSE-007; `node tests/validate-manifest.js` exits 0 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle authors a `src/manifest.d/bookkeeping-cbs-bestanden-extended.json` fragment per ADR-037 declaring the `Bookkeeping > CBS-bestanden` menu entry and 4 detail sub-pages. Spec text in REQ-CBSE-007 + scenario fixes the feature-flag toggle behaviour.
- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `kernGegevensConfig` + `ozbCategorie` cross-referencing this spec (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle inserts the `kernGegevensConfig` schema entry (alphabetically) and an additive `GLLine.ozbCategorie` enum-array annotation on the existing GLLine entry, cross-referencing `bookkeeping-cbs-bestanden-extended` (REQ-CBSE-003 + REQ-CBSE-004). Header entity count bumps by 1.

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
`Gebruikers-deel`, `Woning`, `Niet-woning`. i18n keys MUST be the
English source strings (not the Dutch translations), per the
company-wide `feedback_i18n-keys-english` rule.
