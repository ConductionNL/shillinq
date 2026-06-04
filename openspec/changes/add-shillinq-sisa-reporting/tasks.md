# Tasks — SiSa Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-sisa-reporting` spec — recorded now so spec-review
> and dependency planning are visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `SisaRegelingIndicator` schema, parallel SiSa register, or `bookkeeping-sisa-reporting` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [ ] Task 2: Author `specs/bookkeeping-sisa-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-subsidie-verantwoording` header, `REQ-SISA-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section; SiSa-reviewer persona confirms the indicator shape + bijlage layout match the 2026 BZK controleprotocol
- [ ] Task 5: Declare the `SisaRegelingIndicator` schema in `lib/Settings/shillinq_register.json` with `subsidieId` FK (to subsidie subtype `specifieke-uitkering`), `regelingCode`, `indicatorCode`, `indicatorOmschrijving`, `indicatorWaarde`, `indicatorEenheid`, `peilDatum` per REQ-SISA-001
- [ ] Task 6: Ship `lib/Settings/seeds/sisa-controleprotocol-2026.json` declaring indicatoren per regeling for the 2026 SiSa controleprotocol; SPDX in docblock; `_meta` block (`source: 'BZK SiSa-controleprotocol'`, `year: 2026`); indicator definitions carry `verplicht: boolean` per REQ-SISA-002
- [ ] Task 7: Extend the repair step under `lib/Migration/` to import the controleprotocol seed idempotently when `featureFlags.gov-sisa` is enabled (operator edits persist across re-runs)
- [ ] Task 8: Declare the annual SiSa-bijlage aggregation grouping `SisaRegelingIndicator` records by `(regelingCode, controleprotocol)` for the closed fiscal year per REQ-SISA-003; missing `verplicht: true` indicatoren surface as warnings in audit preview
- [ ] Task 9: Register the SiSa-bijlage docudesk template matching the BZK-vastgestelde layout in `lib/Settings/docudesk-templates.json` per REQ-SISA-003
- [ ] Task 10: Register the BZK SiSa upload openconnector source row in `lib/Settings/openconnector-sources.json` per REQ-SISA-004 (auth and protocol mapping are openconnector-side); no app-local HTTP client per ADR-019
- [ ] Task 11: Wire every SiSa submission to write an immutable audit event of type `sisa.submitted` with operator id, regelingen list, controleprotocol version, document SHA-256, BZK response status, document URI per REQ-SISA-005; linked to the parent jaarrekening via the audit-trail hash chain
- [ ] Task 12: Add SiSa-rapportage navigation + pages to `src/manifest.json` (`featureFlags.gov-sisa`, `Bookkeeping > SiSa-rapportage`, `type: index` listing indicatoren per regeling per year + `type: detail` for the annual bijlage met submission status) per REQ-SISA-006; `node tests/validate-manifest.js` exits 0
- [ ] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `SisaRegelingIndicator` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. SiSa-
reviewer persona confirms the indicator shape + bijlage rendering +
submission audit trail match the 2026 BZK controleprotocol.
Architecture reviewer confirms ADR-019 + ADR-022 + ADR-024 +
ADR-031 + ADR-032 compliance (no app-local BZK HTTP client; no
parallel SiSa register; manifest carries the navigation). No
source code changes outside
`openspec/changes/add-shillinq-sisa-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for indicator FK resolution, bijlage
aggregation completeness vs. seeded controleprotocol, missing
verplichte indicator warning, audit event recording on submission,
seed idempotent re-run (pre-declared on Tasks 5–11); Playwright MCP
browser tests for the SiSa-rapportage pages (pre-declared on Task
12); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors `docs/user-guide/bookkeeping/gov-sisa/sisa-rapportage.md`
per ADR-030 journeydoc convention and commits screenshots to
`docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Single information single audit`,
`SiSa-bijlage`, `Specifieke uitkering`, `Regeling`, `Indicator`,
`Indicatorcode`, `Indicatorwaarde`, `Peildatum`, `Verplicht`,
`Controleprotocol`.
