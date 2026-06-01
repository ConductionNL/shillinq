# Tasks — VAT/BTW Filing

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-vat-btw-filing`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `VatReturn`/`IcpStatement`/`VatCorrection`/`VatTariff` schema and no `bookkeeping-vat-btw-filing` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; confirm `digipoort-sbr` source not yet registered in openconnector)
- [x] Task 2: Author `specs/bookkeeping-vat-btw-filing/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1), bookkeeping-period-close (T2)` header, `REQ-VBTW-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis table, Seed Data section, and Declarative-vs-imperative decision table per hydra `rules.design` + ADR-031 enforcement
- [x] Task 5: Declare the `VatReturn` schema in `lib/Settings/shillinq_register.json` with all REQ-VBTW-002 fields (periodId, periodType, rubrieken, totalAmount, state, submittedOn, attachmentUri, administrationId) typed per spec
- [x] Task 6: Add `x-openregister-lifecycle` block to `VatReturn` declaring `draft → submitted`, `submitted → accepted`, `accepted → corrected` transitions per REQ-VBTW-005, with `requires.approval-workflow` on the `draft → submitted` precondition per REQ-VBTW-006
- [x] Task 7: Declare the `IcpStatement`, `VatCorrection`, and `VatTariff` schemas in `lib/Settings/shillinq_register.json` per REQ-VBTW-007/008/009; `VatCorrection` lifecycle `draft → submitted → accepted`
- [x] Task 8: Add `x-openregister-aggregations` block declaring `VatReturn.rubrieken` as a sum-by-rate projection over T1 `GLLine` rows filtered by `periodId` + rate-tag per REQ-VBTW-004 (or document the ADR-031 exception path if engine cannot express)
- [x] Task 9: Ship `lib/Settings/seeds/btw-tariffs-2026.json` (21%, 9%, 0%, vrij, verlegd) with SPDX header + `_meta.source: "Wet OB 1968"` + version field per REQ-VBTW-003
- [x] Task 10: Declare the SBR/Digipoort submission as an OR `ScheduledWorkflow` consuming `digipoort-sbr` (cron aligned with `VatReturn.periodType`) per REQ-VBTW-010
- [x] Task 11: Extend the repair step under `lib/Repair/` to import the BTW tariff seed idempotently and register the SBR `ScheduledWorkflow`
- [x] Task 12: Add Belastingen menu + VAT/ICP/Correction pages to `src/manifest.json` (`Belastingen > BTW-aangiften`, `> ICP-opgaaf`, `> BTW-correcties`, each with `type: index` + `type: detail`) per REQ-VBTW-011; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with the 4 new entities (`VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`) and their `Primary spec:` references

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the rubrieken mapping, ICP shape, and suppletie lifecycle match Belastingdienst expectations. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-019 compliance (no app-local audit/approval/HTTP client; manifest carries navigation; SBR via OpenConnector). Security reviewer confirms no PKI material in shillinq's `secrets/`. No source code changes outside `openspec/changes/add-shillinq-vat-btw-filing/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering lifecycle transitions + approval-gate enforcement + rubrieken aggregation correctness over a seeded GL fixture; integration test against an OpenConnector mock for `digipoort-sbr`; Playwright MCP browser tests for the three new index/detail pages; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/vat-btw-filing.md` per ADR-030 journeydoc convention and commits a BTW-aangifte index screenshot + ICP-opgaaf detail screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Belastingen`, `BTW-aangifte`, `ICP-opgaaf`, `Suppletie`, `Verleggingsregeling`, `Indienen via Digipoort`, `Rubrieken`, `Tarief`, `Concept`, `Ingediend`, `Geaccepteerd`, `Gecorrigeerd`.
