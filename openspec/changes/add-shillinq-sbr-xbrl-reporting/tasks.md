# Tasks — SBR/XBRL Reporting

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-sbr-xbrl-reporting` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [ ] Task 1: Confirm no `XbrlInstance` schema or `bookkeeping-sbr-xbrl-reporting` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [ ] Task 2: Author `specs/bookkeeping-sbr-xbrl-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-financial-statements (T3), bookkeeping-vat-btw-filing (T3)` header, `REQ-SBR-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Decisions (XBRL-as-transformation, openconnector Digipoort consumption, declarative state machine, per-administration overrides), Reuse Analysis table, and Seed Data section per hydra `rules.design`
- [ ] Task 5: Declare the `XbrlInstance` schema in `lib/Settings/shillinq_register.json` with all REQ-SBR-002 fields (entryPoint, taxonomyVersion, financialStatementId, contextRefs, instanceDocumentUri, submissionReceiptUri, submittedAt, acceptedAt, rejectionReason, lifecycleState, administrationId)
- [ ] Task 6: Add `x-openregister-lifecycle` block to `XbrlInstance` declaring `draft → validated → submitted → accepted` and `submitted → rejected` transitions per REQ-SBR-003; submission action routes through openconnector source slug per REQ-SBR-004
- [ ] Task 7: Ship NL-taxonomie mapping seed templates under `lib/Settings/seeds/sbr-mappings/` (kvk-jaarrekening-nt17/nt18, belastingdienst-vpb-nt17/nt18, belastingdienst-ib-nt17, sbr-banken-kredietrapportage-nt17, sbr-wonen-nt17) per REQ-SBR-005 + REQ-SBR-006, each with SPDX header + `_meta` block per `feedback_spdx-in-docblock.md`
- [ ] Task 8: Extend the repair step under `lib/Migration/` to import NL-taxonomie mapping seeds idempotently (operator edits persist across re-runs) per REQ-SBR-006
- [ ] Task 9: Add SBR/XBRL Filings navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > SBR/XBRL Filings`, `type: index` page binding to `XbrlInstance`, `type: detail` page) per REQ-SBR-007; `node tests/validate-manifest.js` exits 0
- [ ] Task 10: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note introducing `XbrlInstance` and its relationship to the T3 `FinancialStatement` (transformation, not re-aggregation)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms the schema shape matches a real SBR-conformant filing flow. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (Digipoort via openconnector; lifecycle on schema; manifest carries navigation; no embedded SOAP/WS-Security client; no PHP `XbrlReportService`). No source code changes outside `openspec/changes/add-shillinq-sbr-xbrl-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering schema load + lifecycle transitions + mapping seed import + idempotent repair re-run (pre-declared on Tasks 5–8); integration test using a mocked openconnector source returning a Digipoort receipt (Task 6); Playwright MCP browser tests for the SBR/XBRL Filings index + detail pages (Task 9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/sbr-xbrl-filings.md` per ADR-030 journeydoc convention and commits an SBR/XBRL filings index screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `SBR Filing`, `XBRL Instance`, `Jaarrekening`, `Aangifte`, `Digipoort`, `NL-taxonomie`, `Draft`, `Validated`, `Submitted`, `Accepted`, `Rejected`.
