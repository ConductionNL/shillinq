# Tasks — Provincies BBV Variant

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-provincies-bbv-variant` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `ProvincialeFondsPosting` schema or `bookkeeping-provincies-bbv-variant` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-provincies-bbv-variant/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-bbv-compliance` header, `REQ-PRB-NNN` requirements using RFC 2119, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section; BBV-reviewer persona confirms the kerntaken shape + provinciale-fonds posting shape match handleiding
- [x] Task 5: Extend the `bbvVariant` enum (declared by sibling `add-shillinq-waterschappen-bbv-variant`) to accept `'provincie'` per REQ-PRB-001 in `lib/Settings/shillinq_register.json`
- [x] Task 6: Extend the `programmaStructure` discriminator (same sibling) to accept `'kerntaak'` per REQ-PRB-002; aggregations honour the kerntaak rollup
- [x] Task 7: Declare the `ProvincialeFondsPosting` schema with `fondsType` enum (provinciefonds / algemene-uitkering / decentralisatie-uitkering / integratie-uitkering), `uitkeringJaar`, `uitkeringBedrag`, `uitkeringBeschikking`, `journalEntryId` FK per REQ-PRB-004
- [x] Task 8: Add `opcentenTarief: number ≥ 0` optional field on `GLLine` per REQ-PRB-005, with an aggregation rolling up opcenten-inkomsten per provincie per period
- [x] Task 9: Ship `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json` declaring the seven canonical kerntaken (ruimte, mobiliteit, water, milieu, cultuur, economie, bestuur) with RGS-aligned account sub-trees; SPDX in docblock; `_meta` (`source: 'Provinciale handleiding BBV'`, `year: 2026`) per REQ-PRB-003
- [x] Task 10: Extend the repair step under `lib/Migration/` to import the kerntaken seed idempotently when `featureFlags.gov-provincie` is enabled
- [x] Task 11: Wire `ProvincialeFondsPosting` to materialise a balanced 2-line `GLTransaction` per T1 REQ-GL-001 when state transitions to `posted`, with `sourceReference` back to the fonds-posting per REQ-PRB-004
- [x] Task 12: Add Provinciale fondsen navigation + pages to `src/manifest.json` (`featureFlags.gov-provincie`, `Bookkeeping > Provinciale fondsen`, `type: index` binding to `ProvincialeFondsPosting`, `type: detail`) per REQ-PRB-006; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `ProvincialeFondsPosting` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. BBV-
reviewer persona confirms the kerntaken seed + provinciale-fonds
posting shape + opcenten-MRB modelling match the 2026 Provinciale
handleiding. Architecture reviewer confirms ADR-022 + ADR-024 +
ADR-031 + ADR-032 compliance. No source code changes outside
`openspec/changes/add-shillinq-provincies-bbv-variant/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit variant overlay round-trip, kerntaak rollup,
opcenten-aggregation, balanced GLTransaction materialisation, seed
idempotent re-run (pre-declared on Tasks 5–11); Playwright MCP
browser tests for the Provinciale fondsen pages with the feature
flag toggled (pre-declared on Task 12); `composer test` green at
the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The
implementing cycle authors
`docs/user-guide/bookkeeping/gov-provincie/provinciale-fondsen.md`
per ADR-030 journeydoc convention and commits a screenshot to
`docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Provincie`, `Kerntaak`, `Provinciefonds`,
`Algemene uitkering`, `Decentralisatie-uitkering`,
`Integratie-uitkering`, `Opcenten MRB`, `Tariefopslag`.
