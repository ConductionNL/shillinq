# Tasks — Rekenkamer Audit Pack

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-rekenkamer-audit-pack` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `RekenkamerExport`, `NivraRecord`, or parallel audit register exists, and no `bookkeeping-rekenkamer-audit-pack` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-rekenkamer-audit-pack/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-audit-trail, bookkeeping-financial-statements` header, `REQ-REK-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include the "no parallel audit register" non-goal per ADR-022 and Risk / Rollback / Open Questions sections
- [x] Task 4: Author `design.md` with Reuse Analysis table; accountant-reviewer persona confirms NIVRA + steekproef + raadsleden shapes match real auditor expectations
- [x] Task 5: Declare the NIVRA-bestand aggregation in the OR aggregation registry — projecting `(GLTransaction + GLLine + audit-trail events + trial-balance + chart-of-accounts)` for a period, with `_meta.standardVersion` referencing the controleprotocol, output via a docudesk XML template per REQ-REK-002
- [x] Task 6: Declare the steekproef aggregation taking `(periodId, sampleSize, seed)` and returning a deterministic sample of `GLTransaction` records per REQ-REK-003; if engine cannot guarantee determinism, document the ADR-031 exception path for a ~20-LOC PHP sampler
- [x] Task 7: Declare the ledenraadpleging-export aggregation with `redactFor: ['raadsleden']` metadata on `description`-level free-text fields + AP/AR sub-ledger refs, replacing redacted fields with stable hash or `[REDACTED]` placeholder per REQ-REK-004
- [x] Task 8: Register the three docudesk template references (NIVRA-bestand XML, steekproef werkpapier, raadsleden-export) in `lib/Settings/docudesk-templates.json`; field bindings match the aggregations' output shapes per REQ-REK-001
- [x] Task 9: Register the audit-portal openconnector source row in `lib/Settings/openconnector-sources.json` (per accountant per administration; protocol mapping is openconnector-side per ADR-019)
- [x] Task 10: Wire every export to write an immutable audit event of type `audit-pack.{nivra,steekproef,ledenraadpleging}.exported` with operator id, period id, document URI, SHA-256 of the produced document per REQ-REK-005; enforcement via OR's audit engine, not app-local logging
- [x] Task 11: Add Audit pack navigation + 3 sub-pages to `src/manifest.json` (`featureFlags.gov-rekenkamer`, `Bookkeeping > Audit pack`, three sub-pages for NIVRA export, steekproef, ledenraadpleging-export) per REQ-REK-006; `node tests/validate-manifest.js` exits 0
- [x] Task 12: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation explicitly noting that the audit-pack does NOT introduce a parallel audit register and projects from audit-trail-immutable per ADR-022

## Verification

`openspec validate` must exit clean on the change folder.
Accountant-persona reviewer confirms the NIVRA-bestand XML parses
against the standard XSD for a sample closed period, steekproef
yields the same sample on a re-run with the same seed, and the
raadsleden-export redacts the expected fields. Architecture
reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-032 compliance
(NO parallel audit register; aggregation + docudesk + openconnector
pattern). No source code changes outside
`openspec/changes/add-shillinq-rekenkamer-audit-pack/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for NIVRA aggregation completeness vs.
period totals, steekproef determinism with seed, redaction rule
application, audit event recording on export (pre-declared on
Tasks 5–10); Playwright MCP browser tests for the three sub-pages
(pre-declared on Task 11); `composer test` green at the
implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors `docs/user-guide/bookkeeping/gov-rekenkamer/audit-pack.md`
per ADR-030 journeydoc convention and commits screenshots of all
three sub-pages to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Rekenkamer`, `Audit pack`, `NIVRA-bestand`,
`Steekproef`, `Sample size`, `Seed`, `Ledenraadpleging`,
`Geredacteerd`, `Werkpapier`.
