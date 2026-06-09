# Tasks — Trial Balance

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-trial-balance`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Dedup scan executed against shillinq dev tree (2026-06-09). FINDINGS: (a) Sibling capability spec `openspec/specs/bookkeeping-trial-balance/spec.md` is already on `development` (shipped via the T3 sibling change `openspec/changes/bookkeeping-trial-balance/`, REQ-TB-001..006 identical to this T2 envelope's draft); (b) `lib/Service/TrialBalanceService.php` and `lib/Service/TrialBalanceCalculator.php` ARE present — the sibling deliberately deviated from the ADR-031 anti-pattern enumeration to keep the existing single-row `TrialBalance` snapshot schema (ADR-022, REQ-FS-005) while still satisfying the declarative aggregation requirement via `trialBalanceTotals` + `trialBalanceByAccount` aggregations on `lib/Settings/shillinq_register.json` (lines 1793, 1822). The deviation is documented inline in the sibling's `specs.md` "Implementation note (hydra apply)" preface. This umbrella honours the precedent and does not propose to delete the service.
- [x] Task 2: `specs/bookkeeping-trial-balance/spec.md` authored with the required header (`Status: proposed` / `Scope: shillinq` / `Tier: T2` / `Depends on: bookkeeping-general-ledger`), six `### Requirement: REQ-TB-001..006` blocks using RFC 2119 keywords, and twelve `#### Scenario:` blocks with GIVEN/WHEN/THEN. ADR-022 and ADR-031 are both cited inline (ADR-022 in the new framing paragraph at the head of `## ADDED Requirements`; ADR-031 in REQ-TB-001 anti-pattern enumeration, REQ-TB-003 invariant declaration, and REQ-TB-003 imperative-fallback exception clause). Heading format normalised to the canonical `### Requirement: REQ-TB-NNN — <title>` shape so `openspec validate add-shillinq-trial-balance` exits clean.
- [x] Task 3: `proposal.md` authored. References the shared `nextcloud-app` spec at the head of `## Summary` ("This change conforms to the shared `nextcloud-app` spec for app structure and `ConfigurationService::importFromApp()` repair-step seeding"). Sections present: `## Summary`, `## Motivation`, `## Affected Projects`, `## Scope` (In/Out), `## Approach`, `## New Dependencies`, `## Impact`, `## Cross-Project Dependencies`, `## Risks` (three risks with severity + mitigation), `## Rollback Strategy`, `## Open Questions`.
- [x] Task 4: `design.md` authored. Includes `## Reuse Analysis` (8-row capability/exists/strategy table), `### D1 — Trial balance is an aggregation, not a report builder`, `### D2 — Drill-through is a manifest-side affordance`, `### D3 — Balance invariant is declarative, not a PHP service check`, `### D4 — Reversed transactions excluded from movement totals`, plus a `## Declarative-vs-imperative decision` table per ADR-031.
- [~] Task 5: HANDOFF to sibling change `bookkeeping-trial-balance` (already merged to `development`). The sibling shipped two `x-openregister-aggregations` blocks on the `TrialBalance` schema in `lib/Settings/shillinq_register.json` (`trialBalanceTotals` at line 1794, `trialBalanceByAccount` at line 1822) per REQ-TB-002. Sibling reuses the existing `TrialBalance` snapshot schema rather than declaring the aggregation directly on `GLLine` — explicit ADR-022 alignment ("no parallel report storage") documented in the sibling's `specs.md` implementation note.
- [ ] Task 6: Declare the `state: reversed` exclusion filter on the aggregation per REQ-TB-002 (reversed parents excluded from movement totals; lines remain queryable)
- [ ] Task 7: Declare the debit-credit-balance schema invariant on the aggregation output (sum of period debits = sum of period credits) per REQ-TB-003
- [ ] Task 8: Add Bookkeeping > Trial Balance navigation + page to `src/manifest.json` (`type: report` preferred; `type: index` fallback if `CnReportPage` not yet shipped) per REQ-TB-005; period query parameter defaults to active `FiscalPeriod`; `node tests/validate-manifest.js` exits 0
- [ ] Task 9: Declare the drill-through URL template on the trial-balance row (`/general-ledger?period=…&account=…`) per REQ-TB-005 using OR-canonical filter query-param shape
- [ ] Task 10: Resolve the one-aggregation-vs-three-composed question in `design.md` discovery against OR's aggregation extension capability; annotate the spec with the chosen path under "Declarative-vs-imperative decision"
- [ ] Task 11: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note declaring the trial-balance aggregation, citing ADR-031 (declarative aggregation over service) and ADR-022 (no parallel report storage)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the trial-balance shape matches a real RGS-conformant period-end view. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local report storage; no PHP report builder; declarative invariant; manifest carries the navigation). No source code changes outside `openspec/changes/add-shillinq-trial-balance/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for aggregation correctness vs hand-computed trial balance, reversed-transaction exclusion (tampered-state fixture), invariant trip on imbalanced ledger (pre-declared on Tasks 5–7); Playwright MCP browser tests for the trial-balance report rendering and drill-through (pre-declared on Tasks 8–9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/trial-balance.md` per ADR-030 journeydoc convention and commits a trial-balance screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Trial Balance`, `Opening Balance`, `Period Movement`, `Closing Balance`, `Period`, `Account`, `Drill through`.
