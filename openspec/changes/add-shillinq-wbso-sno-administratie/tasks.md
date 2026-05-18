# Tasks — WBSO / S&O Administratie

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-wbso-sno-administratie` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `SoProject`, `SoUrenStaat` schemas or `bookkeeping-wbso-sno-administratie` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [ ] Task 2: Author `specs/bookkeeping-wbso-sno-administratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB / innovation)` / `Depends on: bookkeeping-cost-centers-dimensions` header, `REQ-WBSO-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table; WBSO-consultant reviewer persona confirms the project/uren/mededeling/jaarrapport flow matches RvO praktijk
- [ ] Task 5: Declare the `SoProject` schema in `lib/Settings/shillinq_register.json` with `schema:Project` annotation, `projectNaam`, `rvoProjectNummer`, `sEnOCertificaatNummer`, `looptijdStart`, `looptijdEind`, `costCenterId` FK, `status` enum (`aangevraagd | toegekend | afgerond`) per REQ-WBSO-001
- [ ] Task 6: Declare the `SoUrenStaat` schema with `schema:Action` annotation, `soProjectId` FK, `medewerkerId` (NC user or Detachering FK), `weekISO` (ISO-8601 week format), `aantalUren` (≥ 0, decimals down to 0.25 hour), `taakOmschrijving`, `state` enum (`draft | goedgekeurd | afgesloten`) per REQ-WBSO-002
- [ ] Task 7: Declare the `SoUrenStaat` lifecycle `draft → goedgekeurd → afgesloten` with approval-workflow `requires` on the `goedgekeurd` transition per ADR-022; declare RBAC restricting read to `bookkeeper`, `payroll-officer`, `auditor`
- [ ] Task 8: Declare the per-quarter per-project mededeling aggregation summing `SoUrenStaat.aantalUren` (state ≠ draft) per REQ-WBSO-003; register the mededeling docudesk template
- [ ] Task 9: Register the kwartaalrapportage + jaarrapport docudesk templates per REQ-WBSO-004 in `lib/Settings/docudesk-templates.json`; jaarrapport sums the four kwartaalmededelingen
- [ ] Task 10: Register the RvO openconnector source row(s) per REQ-WBSO-005 in `lib/Settings/openconnector-sources.json` for mededeling / kwartaalrapportage / jaarrapport submissions; no `lib/Service/RvoSubmissieClient.php`
- [ ] Task 11: Declare the afdrachtvermindering `x-openregister-calculations` block computing `SoUrenStaat.aantalUren × medewerker.sEnOUurloon × actueelAfdrachtPercentage` (32% standard / 40% starters per RvO 2026 seed) per REQ-WBSO-006; surface projected + authoritative side-by-side with reconciliation warning
- [ ] Task 12: Add WBSO navigation + 4 sub-pages to `src/manifest.json` (`featureFlags.mkb-wbso`, `Bookkeeping > WBSO`, sub-pages for Projecten, Uren-staten, Mededelingen + rapportages, Afdrachtvermindering) per REQ-WBSO-007; `node tests/validate-manifest.js` exits 0
- [ ] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `SoProject` + `SoUrenStaat` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. WBSO-
consultant reviewer persona walks through a worked example —
quarterly mededeling summed correctly across goedgekeurde uren,
jaarrapport sum equals the four kwartaalmededelingen, projected
afdracht vs RvO mededeling delta surfaces a reconciliation
warning. Architecture reviewer confirms ADR-019 + ADR-022 + ADR-024
+ ADR-031 + ADR-032 compliance (no app-local RvO HTTP client;
lifecycle approval-workflow via OR; RBAC + audit trail on personnel
data). No source code changes outside
`openspec/changes/add-shillinq-wbso-sno-administratie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for lifecycle transition refusal,
mededeling sum correctness, afdracht calculation, jaarrapport sum
invariant, RBAC enforcement (pre-declared on Tasks 5–11);
Playwright MCP browser tests for the four WBSO sub-pages with the
feature flag toggled (pre-declared on Task 12); `composer test`
green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/mkb/wbso/wbso-administratie.md` per
ADR-030 journeydoc convention and commits screenshots of all four
sub-pages to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `WBSO`, `S&O-uren`, `S&O-certificaat`,
`Project`, `Uren-staat`, `Mededeling`, `Kwartaalrapportage`,
`Jaarrapport`, `Afdrachtvermindering loonheffing`,
`Afdrachtpercentage`, `Goedgekeurd`, `Afgesloten`.
