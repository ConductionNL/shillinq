# Tasks — KOR (Kleine Ondernemersregeling)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-kor-kleine-ondernemersregeling`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `KorRegime`/`KorThreshold` schema and no `bookkeeping-kor-kleine-ondernemersregeling` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-vat-btw-filing (T3)` header, `REQ-KOR-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document the ADR-031 exception path (D2) for cross-period YTD aggregation
- [x] Task 5: Declare the `KorRegime` schema in `lib/Settings/shillinq_register.json` with all REQ-KOR-002 fields (administrationId, fiscalYear, state, ytdRevenue, optedInOn, optedOutOn, exceededOn)
- [x] Task 6: Add `x-openregister-lifecycle` to `KorRegime` declaring `outside → opted-in → threshold-warning → threshold-exceeded → opted-out` transitions per REQ-KOR-005, with auto-transitions triggered by `ytdRevenue` calculation crossing seeded thresholds
- [x] Task 7: Declare the `KorThreshold` schema (thresholdAmount, warningPercentage, fiscalYear, citation) per REQ-KOR-003; loaded from `kor-thresholds-2026.json` seed
- [x] Task 8: Declare `KorRegime.ytdRevenue` as `x-openregister-calculations` aggregating revenue from T1 `GLLine` or T2 `Invoice` within current fiscal year per REQ-KOR-004; document ADR-031 exception path (single-method `KorThresholdGuard::currentYtdRevenue` ~30 LOC if engine cannot express)
- [x] Task 9: Ship `lib/Settings/seeds/kor-thresholds-2026.json` (thresholdAmount: 20000, warningPercentage: 80) with SPDX header + `_meta.source: "Wet OB 1968 art. 25 lid 1"` per REQ-KOR-003
- [x] Task 10: Declare the `threshold-exceeded → opted-out` post-transition action: create a `JournalEntry` in `state: pending` (NEVER auto-posted) per REQ-KOR-006 safety constraint
- [x] Task 11: Declare `x-openregister-notifications` firing at 80% and 100% threshold transitions; declare KOR-status widget on `CnDashboardPage` via `x-openregister-widgets` per REQ-KOR-007/008
- [x] Task 12: Extend the repair step under `lib/Repair/` to import the KOR threshold seed idempotently
- [x] Task 13: Add `Belastingen > KOR-status` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate for `mkb`/`zzp` admin types per REQ-KOR-009; `node tests/validate-manifest.js` exits 0
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with the 2 new entities and their `Primary spec:` references

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-priya` for ZZP, `/test-persona-janwillem` for SMB) confirms the KOR lifecycle, threshold tracking, and opt-out journal-entry safety constraint match Belastingdienst handreiking guidance. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative lifecycle; auto-switch via calculation-crossing not cron; widget on `CnDashboardPage` not bespoke Vue; opt-out journal entry `pending` not `posted`). No source code changes outside `openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering lifecycle transitions, 80% and 100% notifications firing, opt-out journal is `pending` not `posted`, YTD calculation correctness over seeded GL/invoice fixture; if exception path is taken, PHPUnit covers the thin guard including credit-note/cancelled-invoice edge cases; Playwright MCP browser tests for the KOR-status index/detail pages + dashboard widget; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/kor.md` per ADR-030 journeydoc convention and commits a KOR-status widget screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `KOR`, `Kleine Ondernemersregeling`, `Omzetdrempel`, `Vrijstelling`, `Opt-in`, `Opt-out`, `Drempelwaarschuwing`, `Drempel overschreden`, `Regimewijziging`.
