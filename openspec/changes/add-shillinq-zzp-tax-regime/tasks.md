# Tasks — ZZP Tax Regime

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-zzp-tax-regime`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `UrenRegistratie`/`ZzpDeduction`/`IbAangifteExport` schema and no `bookkeeping-zzp-tax-regime` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-zzp-tax-regime/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-ZZP-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document the ADR-031 exception path (D2) for cross-period urencriterium aggregation
- [x] Task 5: Declare the `UrenRegistratie` schema in `lib/Settings/shillinq_register.json` with all REQ-ZZP-002 fields (personId, date, hours, category, excludedReason, projectRef, administrationId); `category` and `excludedReason` enums per Wet IB 2001
- [x] Task 6: Declare the `ZzpDeduction` schema with all REQ-ZZP-005 fields (administrationId, personId, fiscalYear, ytdQualifyingHours, criteriumMet, zelfstandigenaftrek, startersaftrek, startersClaimsThisRegime, mkbWinstvrijstelling, taxableProfit)
- [x] Task 7: Declare `ZzpDeduction.ytdQualifyingHours` as `x-openregister-calculations` summing `UrenRegistratie.hours` filtered by `excludedReason IS NULL` within current fiscal year per REQ-ZZP-003; document ADR-031 exception (single-method `UrencriteriumGuard::currentYtdHours` ~30 LOC if engine cannot express)
- [x] Task 8: Declare `ZzpDeduction.zelfstandigenaftrek` and `mkbWinstvrijstelling` derivations as `x-openregister-calculations` reading from seeded deduction-amounts + T1 GL-derived profit per REQ-ZZP-006; startersaftrek gated to `startersClaimsThisRegime ≤ 3`
- [x] Task 9: Declare the `IbAangifteExport` schema with lifecycle `draft → generated → exported` via `x-openregister-lifecycle` per REQ-ZZP-006; declare generation as OR Mapping transformation (with ADR-031 exception for thin renderer)
- [x] Task 10: Ship `lib/Settings/seeds/urencriterium-thresholds.json` (1225 full, 800 starters opvolgers) with SPDX header + `_meta.source: "Wet IB 2001 art. 3.6"` per REQ-ZZP-007
- [x] Task 11: Ship `lib/Settings/seeds/zzp-deduction-amounts-2026.json` (zelfstandigenaftrek + startersaftrek + mkb-winstvrijstelling percentage for current year) with SPDX header + `_meta.source: "Wet IB 2001 + Belastingplan 2026"` per REQ-ZZP-007
- [x] Task 12: Declare `x-openregister-notifications` firing when 1225-criterium is met; declare urencriterium widget on `CnDashboardPage` via `x-openregister-widgets` per REQ-ZZP-008
- [x] Task 13: Extend the repair step under `lib/Repair/` to import both ZZP seeds idempotently
- [x] Task 14: Add `Belastingen > Urenregistratie`, `> ZZP-aftrek`, `> IB-aangifte` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate for `zzp`/`mkb` admin types per REQ-ZZP-008; `node tests/validate-manifest.js` exits 0
- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with the 3 new entities and their `Primary spec:` references

## Verification

`openspec validate` must exit clean on the change folder. ZZP-administrateur-persona peer review (e.g. `/test-persona-priya`) confirms the urencriterium tracker, exclusion enum, deduction derivations, and IB-aangifte export match Belastingdienst guidance. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative calculations; widget on `CnDashboardPage` not bespoke Vue; ADR-031 exception path properly annotated). No source code changes outside `openspec/changes/add-shillinq-zzp-tax-regime/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering excluded-hours filtering, qualifying-hours sum correctness across fiscal-year boundaries, deduction-amount derivations including startersaftrek triple-claim cap; if exception path is taken, PHPUnit covers the thin guard; Playwright MCP browser tests for the 3 new index/detail pages + urencriterium widget; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/zzp.md` per ADR-030 journeydoc convention and commits an urencriterium-widget screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Urenregistratie`, `Urencriterium`, `Zelfstandigenaftrek`, `Startersaftrek`, `MKB-winstvrijstelling`, `IB-aangifte`, `Declarabele uren`, `Niet-declarabele uren`, `Ziekte`, `Verlof`, `Vakantie`.
