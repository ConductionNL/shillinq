# Tasks — IV3 Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-iv3-reporting`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `Iv3Export` schema and no `bookkeeping-iv3-reporting` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; confirm `cbs-iv3` source not yet registered in openconnector)
- [x] Task 2: Author `specs/bookkeeping-iv3-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-bbv-compliance (T3), bookkeeping-period-close (T2)` header, `REQ-IV3-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis and Declarative-vs-imperative decision tables; document D3 ADR-031 exception path for the conditional XML renderer
- [x] Task 5: Declare the `Iv3Export` schema in `lib/Settings/shillinq_register.json` with all REQ-IV3-002 fields (periodId, buckets, totalAmount, state, generatedOn, submittedOn, attachmentUri, administrationId)
- [x] Task 6: Add `x-openregister-lifecycle` to `Iv3Export` declaring `generated → validated → submitted → accepted` (and `rejected`, `corrected`) transitions per REQ-IV3-005
- [x] Task 7: Declare `Iv3Export.buckets` as a derived field via `x-openregister-aggregations` (sum-by-iv3Bucket projection over T1 `GLLine` filtered by quarterly `periodId`, joining `BbvAccountMapping.iv3Bucket`) per REQ-IV3-003
- [x] Task 8: Declare the IV3 XML generation as an OR Mapping transformation, with ADR-031 exception annotation for the conditional thin renderer path (`Iv3XmlRenderer::render` single-method ~30 LOC if Mapping engine cannot express mixed-content) per REQ-IV3-004
- [x] Task 9: Declare the quarterly CBS submission as an OR `ScheduledWorkflow` (cron `0 0 1 */3 *` default, operator-configurable) consuming `cbs-iv3` per REQ-IV3-006
- [x] Task 10: Extend the repair step under `lib/Repair/InitializeSettings.php` to register the IV3 Mapping + `ScheduledWorkflow`; idempotent on re-run
- [x] Task 11: Add `Overheid > IV3-rapportages` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate scoped to municipal admin types per REQ-IV3-007; `node tests/validate-manifest.js` exits 0
- [x] Task 12: Update `openspec/architecture/adr-000-data-model.md` with the new `Iv3Export` entity and its `Primary spec:` reference

## Verification

`openspec validate` must exit clean on the change folder. Municipal-controller-persona peer review confirms the IV3 buckets aggregation matches CBS handreiking guidance and the XML shape validates against the CBS schema. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-019 compliance (no app-local audit/state-machine/HTTP client; CBS via OpenConnector; XML via Mapping with documented exception path). No source code changes outside `openspec/changes/add-shillinq-iv3-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering lifecycle transitions, buckets aggregation correctness over seeded GL+BBV-mapping fixture, XML output validates against CBS schema; integration test against an OpenConnector mock for `cbs-iv3`; Playwright MCP browser tests for the IV3 index/detail pages; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/iv3-reporting.md` per ADR-030 journeydoc convention and commits an IV3 export detail screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `IV3-rapportage`, `IV3-bucket`, `Kwartaal`, `Gegenereerd`, `Ingediend`, `Geaccepteerd`, `Afgewezen`, `Indienen bij CBS`.
