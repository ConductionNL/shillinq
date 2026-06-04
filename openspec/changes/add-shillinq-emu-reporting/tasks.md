# Tasks — EMU Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-emu-reporting` spec — recorded now so spec-review
> and dependency planning are visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `esaClassifier` field on Account, no `EmuComputationResult` register, and no `bookkeeping-emu-reporting` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-emu-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-bbv-compliance, bookkeeping-iv3-reporting` header, `REQ-EMU-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions; explicitly cite the ADR-031 exception path for multi-sector filter expressivity
- [x] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section; BBV-reviewer persona confirms the EMU computation shape matches handleiding
- [x] Task 5: Add `esaClassifier` enum field on `Account` in `lib/Settings/shillinq_register.json` with values S.1311 / S.1312 / S.1313 / S.1314 / S.11 / S.12 / S.13 / S.14 / S.15 / S.2 per REQ-EMU-002
- [x] Task 6: Ship `lib/Settings/seeds/esa-2010-classifier.json` declaring the canonical ESA-2010 sector codes; SPDX in docblock; `_meta` block (`source: 'ESA 2010 Eurostat'`, `year: 2010`) per REQ-EMU-002
- [x] Task 7: Declare the quarterly EMU aggregation grouping `GLLine` by ESA sector, filtered + summed per the BBV handleiding rules, riding the T3 IV3 quarterly aggregation per REQ-EMU-003
- [x] Task 8: Declare the annual EMU aggregation from the closed jaarrekening per REQ-EMU-003; outputs EMU-saldo + EMU-schuld
- [x] Task 9: Honour the per-sector `emuInclusionRule` field (declared by sibling `add-shillinq-waterschappen-bbv-variant`) at aggregation time; defaults match the 2026 BBV handleiding; the rule surfaces in the audit-trail comment per REQ-EMU-004
- [x] Task 10: Implement reproducibility per REQ-EMU-005 — every EMU run records a stable aggregation hash + classifier state + applied exclusion rules; same input → identical output to the cent
- [x] Task 11: If the engine cannot express the multi-sector filter, author a single-method ~20-LOC `EmuCalculator` per ADR-031 §"PHP guards remain a legitimate seam" exception path; document the exception in `design.md`
- [x] Task 12: Add EMU-rapportage navigation + pages to `src/manifest.json` (`featureFlags.gov-emu`, `Bookkeeping > EMU-rapportage`, `type: index` listing historical runs + `type: detail` showing inputs / classifier state / exclusion metadata / eindcijfers) per REQ-EMU-006; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `esaClassifier` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. BBV-
reviewer persona reproduces the EMU-saldo computation by hand for
a sample period and confirms equality with the aggregation output.
Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-032
compliance (no PHP EMU-service unless ADR-031 exception applies;
manifest carries the navigation). No source code changes outside
`openspec/changes/add-shillinq-emu-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for EMU computation against worked
example, reproducibility on re-run, exclusion rule application,
seed idempotent re-run (pre-declared on Tasks 5–11); Playwright MCP
browser tests for the EMU-rapportage pages with the feature flag
toggled (pre-declared on Task 12); `composer test` green at the
implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors `docs/user-guide/bookkeeping/gov-emu/emu-rapportage.md`
per ADR-030 journeydoc convention and commits screenshots to
`docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `EMU-saldo`, `EMU-schuld`, `ESA-sector`,
`Centrale overheid`, `Lokale overheid`, `Wettelijke sociale verzekering`,
`Inclusieregel`, `Exclusieregel`, `Kwartaalrapportage`,
`Jaarrapportage`.
