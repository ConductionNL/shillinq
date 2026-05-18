# Tasks — Schatkistbankieren

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-schatkistbankieren`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `SchatkistPosition` schema, no `isSchatkistAccount` field on T1 `Account`, and no `bookkeeping-schatkistbankieren` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [ ] Task 2: Author `specs/bookkeeping-schatkistbankieren/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-SBK-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document D2 (no parallel ledger) and D3 (`ScheduledWorkflow` not `*Job`) decisions
- [ ] Task 5: Extend T1 `Account` schema in `lib/Settings/shillinq_register.json` with `isSchatkistAccount: boolean` (default `false`) per REQ-SBK-002
- [ ] Task 6: Declare the `SchatkistPosition` schema in `lib/Settings/shillinq_register.json` with all REQ-SBK-003 fields (administrationId, date, position, totalDeposits, totalWithdrawals, thresholdAtTimeOfRecord)
- [ ] Task 7: Declare `SchatkistPosition.position` (and totalDeposits/totalWithdrawals) as derived fields via `x-openregister-aggregations` (sum-by-day projection over T1 `GLLine` filtered by `Account.isSchatkistAccount=true`) per REQ-SBK-004
- [ ] Task 8: Declare the daily aggregation as an OR `ScheduledWorkflow` (cron once-per-business-day, holiday-aware) per REQ-SBK-007; this MUST NOT be a `*Job` class
- [ ] Task 9: Ship `lib/Settings/seeds/schatkist-thresholds.json` (4 admin-type thresholds: small gemeente 0.75%, large gemeente 0.5%, provincie 0.5%, waterschap 0.5%) with SPDX header + `_meta.source: "Wet HOF art. 2"` per REQ-SBK-005
- [ ] Task 10: Declare `x-openregister-notifications` firing on threshold-crossing; declare schatkist-position widget on `CnDashboardPage` via `x-openregister-widgets` per REQ-SBK-008
- [ ] Task 11: Extend the repair step under `lib/Migration/` to import the threshold seed and register the daily `ScheduledWorkflow`; idempotent on re-run
- [ ] Task 12: Add `Overheid > Schatkist-positie` navigation + pages to `src/manifest.json` with `type: index` + `type: detail` + the dashboard widget, visibility predicate for municipal admin types per REQ-SBK-009; `node tests/validate-manifest.js` exits 0
- [ ] Task 13: Update `openspec/architecture/adr-000-data-model.md` with the new `SchatkistPosition` entity (and the `isSchatkistAccount` field extension note on `Account`) and its `Primary spec:` reference

## Verification

`openspec validate` must exit clean on the change folder. Treasury-officer-persona peer review confirms the position aggregation matches Wet HOF guidance and the daily cadence + threshold-crossing alarm work as expected. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no parallel ledger; `ScheduledWorkflow` not `*Job`; widget on `CnDashboardPage` not bespoke Vue; flag on existing `Account` not parallel register). No source code changes outside `openspec/changes/add-shillinq-schatkistbankieren/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering aggregation includes only flagged accounts, daily workflow generates exactly one record per administration per business day, threshold-crossing notification fires; integration test against the OR `ScheduledWorkflow` runner with a holiday fixture; Playwright MCP browser tests for the position index/detail page + dashboard widget; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/schatkistbankieren.md` per ADR-030 journeydoc convention and commits a schatkist-position widget screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Schatkist-positie`, `Drempelbedrag`, `Deposito`, `Opname`, `Treasury-positie`, `Drempel overschreden`, `Werkdag`.
