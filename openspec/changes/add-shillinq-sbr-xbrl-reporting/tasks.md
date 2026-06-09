# Tasks — SBR/XBRL Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-sbr-xbrl-reporting` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [x] Task 1: Confirmed no `XbrlInstance` schema declared anywhere under `lib/Settings/` (grep clean) and no `XbrlReportService` / `SbrService` PHP class exists (only sibling `InnovatieboxSbrExportService` ships, scoped to innovatiebox); the prior `openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md` (XBRLTaxonomy / SBRDocumentType / XBRLMapping shape) is the legacy three-register flavour this change supersedes via the new `specs/bookkeeping-sbr-xbrl-reporting/spec.md` REQ-SBR-001..007 inside this change folder.
- [x] Task 2: Spec authored at `openspec/changes/add-shillinq-sbr-xbrl-reporting/specs/bookkeeping-sbr-xbrl-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-financial-statements (T3), bookkeeping-vat-btw-filing (T3)` header and REQ-SBR-001..007 each with `#### Scenario:` blocks (GIVEN/WHEN/THEN).
- [x] Task 3: Proposal authored at `openspec/changes/add-shillinq-sbr-xbrl-reporting/proposal.md` referencing the shared `nextcloud-app` spec; Affected Projects / Scope / Risks / Rollback / Open Questions sections all present.
- [x] Task 4: Design authored at `openspec/changes/add-shillinq-sbr-xbrl-reporting/design.md` with Decisions D1–D4 (XBRL-as-transformation, openconnector Digipoort consumption, declarative state machine, per-administration mapping overrides), Reuse Analysis table, and Seed Data section listing seven NL-taxonomie mapping templates.
- [x] Task 5: `XbrlInstance` schema declared in `lib/Settings/register.d/add-shillinq-sbr-xbrl-reporting.json` (ADR-037 modular fragment — monolith `shillinq_register.json` untouched) with all REQ-SBR-002 fields (administrationId, instanceNumber, entryPoint, taxonomyVersion, reportingPeriodStart/End, sourceStatementId, mappingId, instanceXml, instanceHash, state, digipoortReceiptId, digipoortSourceSlug, submittedAt, acceptedAt, rejectionReason) plus `x-openregister-unique` on `(administrationId, entryPoint, reportingPeriodEnd, instanceNumber)`.
- [x] Task 6: `x-openregister-lifecycle` block authored on `XbrlInstance` declaring `draft → validated → submitted → accepted/rejected` plus a `rejected → draft` reopen transition (REQ-SBR-003); `submit` transition documents the openconnector source-slug route via `digipoortSourceSlug` (default `digipoort-prod`) per REQ-SBR-004; no PHP service class authors transitions (ADR-031).
- [x] Task 7: Seven NL-taxonomie mapping seed templates shipped under `lib/Settings/seeds/sbr-mappings/` (`kvk-jaarrekening-nt17.json`, `kvk-jaarrekening-nt18.json`, `belastingdienst-vpb-nt17.json`, `belastingdienst-vpb-nt18.json`, `belastingdienst-ib-nt17.json`, `sbr-banken-kredietrapportage-nt17.json`, `sbr-wonen-nt17.json`) per REQ-SBR-005 + REQ-SBR-006; each carries SPDX header + `_meta` block (source / variant / taxonomyVersion / entryPoint / imported) per `feedback_spdx-in-docblock.md`; seed records use the canonical NL-taxonomie namespaces (`bw2-i:`, `bd-i:`, `bb-i:`, `wo-i:`). Templates are starter sets — extension concepts per company are operator-added and persist across re-runs.
- [x] Task 8: `OCA\Shillinq\Repair\InitializeSettings::seedSbrMappings()` added (wired into `run()` after the WMO seed); iterates every `lib/Settings/seeds/sbr-mappings/*.json`, dedupes via `MappingMapper::findByRef($slug)` so operator edits persist, and seeds new mappings via `MappingMapper::createFromArray()` against the OR `Mapping` surface (consumed by `XbrlInstance.mappingId` per REQ-SBR-006). Defensive Throwable guard degrades to a warning when OR `MappingMapper` is not available.
- [x] Task 9: `Bookkeeping > SBR/XBRL Filings` menu entry + `SbrXbrlFilings` (type: index, route `/sbr-xbrl-filings`) and `SbrXbrlFilingDetail` (type: detail, route `/sbr-xbrl-filings/:id`) pages added to `src/manifest.json` per REQ-SBR-007; index columns are instanceNumber / entryPoint / reportingPeriodEnd / state / digipoortReceiptId with filter chips for state and entryPoint (matching the REQ-SBR-007 scenario); detail page exposes the full field set with an OR audit-trail sidebar tab; both pages render via generic `CnIndexPage` / `CnDetailPage` (no bespoke Vue files, ADR-024 Tier-4). `node tests/validate-manifest.js` exits 0; English + Dutch translation strings ship in `l10n/en.json` + `l10n/nl.json` per ADR-005; `appinfo/info.xml` version bumped to 0.7.5 for the NC immutable cache-bust.
- [x] Task 10: `openspec/architecture/adr-000-data-model.md` extended with a new `## SBR/XBRL Reporting (XbrlInstance)` section reconciling `XbrlInstance` with the T3 `FinancialStatement` (transformation, not re-aggregation, per design D1) and clarifying that sibling T3 changes (`bookkeeping-vpb-mkb`, `bookkeeping-bcf-vat-compensation`, `bookkeeping-emu-reporting`, `bookkeeping-icp-opgaaf`, `bookkeeping-ib-aangifte-zzp`) continue to carry their own per-domain SBR lifecycle additively — `XbrlInstance` is the umbrella payload store for the canonicalised XBRL XML + Digipoort receipt + tamper-evidence hash.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms the schema shape matches a real SBR-conformant filing flow. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (Digipoort via openconnector; lifecycle on schema; manifest carries navigation; no embedded SOAP/WS-Security client; no PHP `XbrlReportService`). No source code changes outside `openspec/changes/add-shillinq-sbr-xbrl-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering schema load + lifecycle transitions + mapping seed import + idempotent repair re-run (pre-declared on Tasks 5–8); integration test using a mocked openconnector source returning a Digipoort receipt (Task 6); Playwright MCP browser tests for the SBR/XBRL Filings index + detail pages (Task 9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/sbr-xbrl-filings.md` per ADR-030 journeydoc convention and commits an SBR/XBRL filings index screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `SBR Filing`, `XBRL Instance`, `Jaarrekening`, `Aangifte`, `Digipoort`, `NL-taxonomie`, `Draft`, `Validated`, `Submitted`, `Accepted`, `Rejected`.
