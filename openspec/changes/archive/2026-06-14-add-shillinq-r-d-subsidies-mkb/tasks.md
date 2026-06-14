# Tasks — R&D Subsidies MKB

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-r-d-subsidies-mkb` spec — recorded now so spec-
> review and dependency planning are visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `subsidieRegeling` enum, parallel R&D subsidie register, or `bookkeeping-r-d-subsidies-mkb` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-r-d-subsidies-mkb/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB / innovation)` / `Depends on: bookkeeping-subsidie-verantwoording` header, `REQ-RDS-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table; R&D subsidie reviewer persona confirms per-regeling kostencategorieën + audit-pack shapes match RvO / EU praktijk
- [x] Task 5: Add `subsidieRegeling: mit | sbir | eu-horizon | efro | react-eu | other` enum on `Subsidie` in `lib/Settings/shillinq_register.json` per REQ-RDS-001; `schema:Grant` annotation (with `schema:ResearchProject` materialisation)
- [x] Task 6: Declare per-regeling kostencategorieën constraints via JSON Schema `oneOf`/`if-then` — MIT (personnel/materials/external-services/equipment-depreciation/other-direct), SBIR (subset), EU Horizon (personnel/subcontracting/other-direct/indirect-25-percent), EFRO (personnel/external-services/materials/equipment/other/indirect-flat-rate), REACT-EU (EFRO + green-recovery) per REQ-RDS-002
- [x] Task 7: Declare per-regeling voortgangsrapportage aggregations grouping `kostenpost` by `(kostencategorie, periodId)` filtered on the parent subsidie per REQ-RDS-003
- [x] Task 8: Register per-regeling voortgangsrapportage docudesk templates in `lib/Settings/docudesk-templates.json` — Horizon Periodic Report layout, MIT voortgangsrapport, EFRO progress dossier, etc. per REQ-RDS-003
- [x] Task 9: Register per-regeling audit-pack docudesk templates per REQ-RDS-004 — Horizon Audit Certificate (with personnel/timesheet URI refs to S&O-uren-staten from sibling WBSO spec), MIT declaration template referencing WBSO/S&O administration, EFRO procurement dossier template
- [x] Task 10: Declare per-regeling budget monitoring `x-openregister-calculations` block surfacing ≥90% warning when a kostencategorie sub-max approaches (e.g. Horizon indirect-25% bound to 25% of direct costs) per REQ-RDS-005
- [x] Task 11: Add R&D Subsidies navigation + pages to `src/manifest.json` (`featureFlags.mkb-r-d-subsidies`, `Bookkeeping > R&D Subsidies`, `type: index` per regeling + `type: detail` per subsidie showing budget / kostendossier / voortgangsrapportage / audit-pack) per REQ-RDS-006; `node tests/validate-manifest.js` exits 0
- [x] Task 12: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `subsidieRegeling` overlay cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. R&D
subsidie reviewer persona walks through a Horizon worked example
(€100k direct + €22.5k indirect-25%, projected to exceed €25k cap
→ warning), verifies invalid kostencategorie is refused at save,
and confirms the Horizon audit-pack references S&O-uren-staten via
timesheet URIs. Architecture reviewer confirms ADR-022 + ADR-024 +
ADR-031 + ADR-032 compliance (no parallel R&D register; declarative
constraints + templates; no PHP services). No source code changes
outside `openspec/changes/add-shillinq-r-d-subsidies-mkb/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for per-regeling kostencategorie
validation refusal, voortgangsrapportage rollup correctness,
indirect-25% warning trigger, audit-pack URI-reference assembly
(pre-declared on Tasks 5–10); Playwright MCP browser tests for the
R&D Subsidies pages (pre-declared on Task 11); `composer test`
green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/mkb/r-d-subsidies/r-d-subsidies.md`
per ADR-030 journeydoc convention and commits per-regeling
screenshots to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `R&D subsidie`, `MIT`, `SBIR`,
`EU Horizon`, `EFRO`, `REACT-EU`, `Kostencategorie`, `Personeel`,
`Subcontracting`, `Indirect-25-percent`, `Voortgangsrapportage`,
`Audit-pack`, `Periodic report`.
