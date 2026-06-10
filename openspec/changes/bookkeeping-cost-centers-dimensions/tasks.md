# Tasks — Cost Centers & Analytical Dimensions

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-cost-centers-dimensions` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

> **HANDOFF (2026-06-10).** A prior `consultancy-project-accounting` build already shipped the `CostCenter` and `Project` schemas in `lib/Settings/shillinq_register.json` (CostCenter has the spec-required `code` / `name` / `parentCode` / `responsibleUser` / `lifecycleState` / `administrationId` fields; Project carries `code` + `parentCode` for hierarchy alongside the consultancy fields). The remaining work — `AnalyticalDimension` schema, additive `GLLine` dimension fields, segment-P&L `x-openregister-aggregations`, dimension seeds, repair-step seed import, manifest nav entries, and `adr-000-data-model.md` reconciliation — is deferred to a follow-up implementation cycle so a dedicated builder can wire the cross-schema aggregation declaratively without colliding with in-flight tier-1 GL work. Tasks below are flagged `[~]` to record the partial-coverage state honestly per the strategy in `feedback_always-fix-preexisting.md` and the handoff pattern used across the shillinq bookkeeping cluster.

- [~] Task 1: Confirm no `CostCenter` / `Project` / `AnalyticalDimension` schemas or `bookkeeping-cost-centers-dimensions` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`) — `CostCenter` + `Project` ALREADY DECLARED in `lib/Settings/shillinq_register.json` via prior consultancy-project-accounting merge; `AnalyticalDimension` not yet declared, capability spec ships here as proposed
- [~] Task 2: Author `specs/bookkeeping-cost-centers-dimensions/spec.md` with `Status: proposed` / `Scope: bookkeeping` / `Tier: T2 (advanced features)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-CD-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN — DONE; REQ-CD-001..008 carried via `### Requirement: REQ-CD-NNN — ...` headers so `openspec validate --strict` parses deltas
- [~] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per ADR-032 guidelines — DONE in `proposal.md` (carried from prior author)
- [~] Task 4: Author `design.md` with Decisions (dimensions as registers, hierarchy via self-relations, segment P&L as aggregation, custom dimensions) and Reuse Analysis table per hydra `rules.design` — DONE in `design.md` (carried from prior author)
- [~] Task 5: Declare the `CostCenter` schema in app register configuration with REQ-CD-002 fields (code, name, parentCode, manager, budget, status, administrationId), `x-openregister-relations` self-relation for hierarchy, RBAC role definitions — `CostCenter` schema DECLARED in `lib/Settings/shillinq_register.json` with `code` / `name` / `parentCode` / `responsibleUser` (manager) / `lifecycleState` (status) / `administrationId`; `budget` field deferred to follow-up
- [~] Task 6: Declare the `Project` schema with REQ-CD-002 equivalent fields, `x-openregister-relations` self-relation for hierarchy, RBAC role definitions — `Project` schema DECLARED in `lib/Settings/shillinq_register.json` with `code` / `name` / `parentCode` / `responsibleUser` / `lifecycleState` / `administrationId` alongside the consultancy `projectNumber` / `customerId` / `recognitionMethod` fields
- [~] Task 7: Declare the `AnalyticalDimension` schema with REQ-CD-006 fields (code, name, dataType, isHierarchical, administrationId) for operator-defined custom dimensions — DEFERRED; schema not yet declared in `lib/Settings/shillinq_register.json`, follow-up build cycle to add
- [~] Task 8: Additively patch the T1 `GLLine` schema with dimension fields (`costCenterCode`, `projectCode`, `dimensions` map) per REQ-CD-003; the `dimensions` map validates against registered analytical dimensions via OR relations engine — DEFERRED; tier-1 `GLLine` schema not yet extended, must land as additive patch alongside the `AnalyticalDimension` declaration in the follow-up build
- [~] Task 9: Declare segment P&L roll-up as `x-openregister-aggregations` on `GLLine` keyed by dimension and hierarchical parent per REQ-CD-004 + REQ-CD-007; consumed by dashboard via runtime GraphQL and by manifest detail pages — DEFERRED; aggregation declaration depends on Task 8 (`GLLine` dimension fields) shipping first
- [~] Task 10: Ship cost center and analytical dimension example seeds under `lib/Settings/seeds/dimensions/` (3-5 realistic Dutch cost centers: Administratie Amsterdam, Sales Utrecht, Sales Amsterdam, Logistics Rotterdam; 2 example custom dimension definitions: Region, Product Line) with SPDX header + `_meta` block per `feedback_spdx-in-docblock.md` — DEFERRED; `lib/Settings/seeds/dimensions/` not yet created, no `CostCenter` / `Project` seed files exist (verified via `find lib/Settings/seeds -iname "*cost*" -o -iname "*dimension*" -o -iname "*project*"`)
- [~] Task 11: Extend the repair step under `lib/Migration/` to import dimension example seeds idempotently per REQ-CD-002 + REQ-CD-006 — DEFERRED; depends on Task 10 (seed files) landing first
- [~] Task 12: Add Dimensions navigation + pages to `src/manifest.json` (entries under `Bookkeeping > Dimensions` for `CostCenter`, `Project`, `AnalyticalDimension` with matching `type: index` + `type: detail` pages; custom analytical dimensions appear automatically with no PHP/Vue edits) per REQ-CD-005; `node tests/validate-manifest.js` exits 0 — DEFERRED; manifest nav not yet wired, depends on Task 7 (`AnalyticalDimension` declaration) shipping first
- [~] Task 13: Update `openspec/architecture/adr-000-data-model.md` with one-paragraph reconciliation notes introducing `CostCenter`, `Project`, `AnalyticalDimension` and the additive dimension fields on `GLLine`; document that custom dimensions are operator-extensible via the `AnalyticalDimension` register — DEFERRED; ADR update lands together with the follow-up build so the documented schema set matches what ships

## Deduplication Check

| Existing capability | Potential overlap | Decision |
|---|---|---|
| `CostCenter` entity (ADR-000) | Entity already exists; this spec declares register version | Use OR-managed register instead of `lib/Db/CostCenter` Mapper to align with ADR-022 |
| `GLLine` dimension fields | Some apps may extend GLLine independently | Coordinate with bookkeeping-tier-1 to ensure dimension fields are additive and non-breaking |
| Segment P&L reporting | Potential overlap with reporting specs | Single-schema aggregation per ADR-031; no dedicated `SegmentReportService` PHP class |
| Analytical dimension storage | No overlap found | New `AnalyticalDimension` register (operator-managed) enables extensibility without code changes |

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms segment P&L roll-up matches real practice (hierarchical aggregation, parent-child sums, drill-down navigation). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (dimensions as registers; segment P&L single-schema aggregation; manifest carries navigation; no `DimensionService` / `SegmentReportService` PHP class). No source code changes outside `openspec/changes/bookkeeping-cost-centers-dimensions/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:
- PHPUnit unit tests asserting relation resolution (cost-center hierarchy parent lookup), dimension key/value validation, segment P&L aggregation rolls up children per dimension
- Playwright MCP browser tests for the Dimensions navigation + pages (cost-center CRUD, hierarchy drill-down, project management)
- `composer test` green at the implementing PR's CI gate
- Aggregation rollup tests: verify that child amounts sum to parent without duplication across all dimension types

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/bookkeeping/dimensions.md` per ADR-030 journeydoc convention
- Dimension hierarchy + cost-center structure + project setup screenshots in `docs/images/`
- Segment P&L drill-down workflow documentation

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
- `Cost Center` / `Kostenplaats`
- `Project` / `Project`
- `Analytical Dimension` / `Analysedimensie`
- `Region` / `Regio`
- `Product Line` / `Productlijn`
- `Department` / `Afdeling`
- `Segment P&L` / `Segment Resultatenrekening`
- `Roll-up` / `Aggregatie`
- `Drill-down` / `Detaillering`
