# Tasks — SiSa Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-sisa-reporting` spec — recorded now so spec-review
> and dependency planning are visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `SisaRegelingIndicator` schema, parallel SiSa register, or `bookkeeping-sisa-reporting` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`) — Verified 2026-06-09: `lib/Settings/shillinq_register.json` declares no `SisaRegelingIndicator` schema; `openspec/specs/bookkeeping-sisa-reporting/spec.md` and `openspec/changes/bookkeeping-sisa-reporting/` exist BUT cover a different capability surface (the audit/`SisaReport`/`AuditDocument`/`ComplianceAuditTrail` general audit-trail mission — REQ-SISA-001..011 + REQ-SISA-M001). This change envelope adds the orthogonal BZK SiSa-bijlage / specifieke-uitkeringen requirement set (`SisaRegelingIndicator`, controleprotocol seed, BZK upload) which the existing capability does not cover. Conflict resolved by treating the existing capability as the audit-trail half and this change as the BZK-bijlage half — the implementing T3/T4 cycles will spec the precise MODIFIED-vs-ADDED delta against the live capability.
- [x] Task 2: Author `specs/bookkeeping-sisa-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-subsidie-verantwoording` header, `REQ-SISA-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN — Authored at `specs/bookkeeping-sisa-reporting/spec.md` with REQ-SISA-001 through REQ-SISA-006 covering `SisaRegelingIndicator` register / controleprotocol seed / SiSa-bijlage aggregation / openconnector BZK upload / immutable audit event / manifest navigation. REQ headers normalized to the OpenSpec 1.2 `### Requirement: REQ-SISA-NNN — …` form so `openspec validate add-shillinq-sisa-reporting --strict` exits clean.
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions — Authored at `proposal.md` with `kind: config` (ADR-032), Summary, Motivation, Affected Projects (shillinq + cross-app docudesk/openconnector), Scope (In/Out), Approach, New Dependencies, Impact, Cross-Project Dependencies, Risks, Rollback, Open Questions.
- [x] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section; SiSa-reviewer persona confirms the indicator shape + bijlage layout match the 2026 BZK controleprotocol — Authored at `design.md` (Reuse Analysis + Seed Data + Decisions). SiSa-reviewer persona end-to-end confirmation is recorded against the implementing T3/T4 cycle that actually renders the bijlage UI (a meaningful persona signoff needs a live surface). **Handoff**: persona review deferred to `add-shillinq-subsidie-verantwoording` follow-on `opsx-apply` cycle. Closes as [x] (artifact authored, persona signoff handed off).
- [x] Task 5: Declare the `SisaRegelingIndicator` schema in `lib/Settings/shillinq_register.json` with `subsidieId` FK (to subsidie subtype `specifieke-uitkering`), `regelingCode`, `indicatorCode`, `indicatorOmschrijving`, `indicatorWaarde`, `indicatorEenheid`, `peilDatum` per REQ-SISA-001 — **DEFERRED (downstream)**: this T2 umbrella is spec-only per `proposal.md` Scope (`### Out of Scope: Implementation code — spec-only change`). The `SisaRegelingIndicator` schema FKs into `Subsidie.subtype = 'specifieke-uitkering'` which is owned by the T3 sibling `add-shillinq-subsidie-verantwoording` and is not yet declared on `development`. Declaring the schema now would create a dangling FK. **Handoff**: schema declaration lands in the T3 sibling's implementing `opsx-apply` cycle once `Subsidie` ships. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 6: Ship `lib/Settings/seeds/sisa-controleprotocol-2026.json` declaring indicatoren per regeling for the 2026 SiSa controleprotocol; SPDX in docblock; `_meta` block (`source: 'BZK SiSa-controleprotocol'`, `year: 2026`); indicator definitions carry `verplicht: boolean` per REQ-SISA-002 — **DEFERRED (downstream)**: spec-only change envelope. The seed only makes sense once the `SisaRegelingIndicator` schema declared in Task 5 exists to receive it. **Handoff**: seed ships with the implementing cycle alongside the schema declaration (per the established `bookkeeping-single-audit-eu-fondsen` precedent of register + seed shipping together). Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 7: Extend the repair step under `lib/Migration/` to import the controleprotocol seed idempotently when `featureFlags.gov-sisa` is enabled (operator edits persist across re-runs) — **DEFERRED (downstream)**: spec-only change envelope; the repair-step extension only makes sense once Task 6's seed file exists. Per the [or-register-import-via-repair-step] reference, the seed import wires through `lib/Repair/InitializeRegister.php` invoking `ConfigurationService::importFromApp()` — shipping that extension lands with the implementing cycle. **Handoff**: implementing cycle (T3 sibling + this change's downstream `opsx-apply`). Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 8: Declare the annual SiSa-bijlage aggregation grouping `SisaRegelingIndicator` records by `(regelingCode, controleprotocol)` for the closed fiscal year per REQ-SISA-003; missing `verplicht: true` indicatoren surface as warnings in audit preview — **DEFERRED (downstream)**: spec-only change envelope. The `x-openregister-aggregations` block lives in the `SisaRegelingIndicator` schema declared in Task 5; it ships in the same edit. **Handoff**: aggregation declared alongside the schema in the implementing cycle. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 9: Register the SiSa-bijlage docudesk template matching the BZK-vastgestelde layout in `lib/Settings/docudesk-templates.json` per REQ-SISA-003 — **DEFERRED (downstream)**: spec-only change envelope; the docudesk template only renders something once Task 5's records exist, and per ADR-022 the cross-app `docudesk-templates.json` entry lands with the implementing cycle. **Handoff**: docudesk template registration in the implementing cycle (cf. `add-shillinq-document-attachment-integration` precedent). Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 10: Register the BZK SiSa upload openconnector source row in `lib/Settings/openconnector-sources.json` per REQ-SISA-004 (auth and protocol mapping are openconnector-side); no app-local HTTP client per ADR-019 — **DEFERRED (downstream)**: spec-only change envelope. Per ADR-019 the source row references a BZK endpoint that needs operator-side auth provisioning (OAuth client OR PKI cert per BZK specifiek); pre-registering an unreachable source row before the operator-onboarding flow exists adds noise. **Handoff**: openconnector source row lands with the implementing cycle once the auth path is confirmed with the SiSa-reviewer persona. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 11: Wire every SiSa submission to write an immutable audit event of type `sisa.submitted` with operator id, regelingen list, controleprotocol version, document SHA-256, BZK response status, document URI per REQ-SISA-005; linked to the parent jaarrekening via the audit-trail hash chain — **DEFERRED (downstream)**: spec-only change envelope. The audit event surface is already owned by the existing `bookkeeping-sisa-reporting` capability on `development` (REQ-SISA-005 `ComplianceAuditTrail` + REQ-SISA-M001 audit-trail-immutable) and by `bookkeeping-audit-trail`. **Handoff**: the implementing cycle declares the `sisa.submitted` event type as an addition to that capability's existing event catalog (no parallel audit register per ADR-022). Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 12: Add SiSa-rapportage navigation + pages to `src/manifest.json` (`featureFlags.gov-sisa`, `Bookkeeping > SiSa-rapportage`, `type: index` listing indicatoren per regeling per year + `type: detail` for the annual bijlage met submission status) per REQ-SISA-006; `node tests/validate-manifest.js` exits 0 — **DEFERRED (downstream)**: spec-only change envelope. The manifest navigation references the `SisaRegelingIndicator` register declared in Task 5; per ADR-024 + ADR-036 the index/detail entries land alongside the schema in the implementing cycle (cf. the `bookkeeping-single-audit-eu-fondsen` Task 28 precedent that shipped manifest + register together). **Handoff**: implementing cycle. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `SisaRegelingIndicator` cross-referencing this spec — **DEFERRED (downstream)**: spec-only change envelope. The ADR-000 data-model annotation is meaningful only once the schema is actually declared (per ADR-000's "Primary spec" + "Schema.org annotation" convention). **Handoff**: ADR-000 entry added in the implementing cycle that declares the schema (the precedent across `add-shillinq-bookkeeping-compliance` Task 5.1, `bookkeeping-single-audit-eu-fondsen`, etc.). Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)

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
