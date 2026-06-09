# Tasks — Trial Balance

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-trial-balance`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Dedup scan executed against shillinq dev tree (2026-06-09). FINDINGS: (a) Sibling capability spec `openspec/specs/bookkeeping-trial-balance/spec.md` is already on `development` (shipped via the T3 sibling change `openspec/changes/bookkeeping-trial-balance/`, REQ-TB-001..006 identical to this T2 envelope's draft); (b) `lib/Service/TrialBalanceService.php` and `lib/Service/TrialBalanceCalculator.php` ARE present — the sibling deliberately deviated from the ADR-031 anti-pattern enumeration to keep the existing single-row `TrialBalance` snapshot schema (ADR-022, REQ-FS-005) while still satisfying the declarative aggregation requirement via `trialBalanceTotals` + `trialBalanceByAccount` aggregations on `lib/Settings/shillinq_register.json` (lines 1793, 1822). The deviation is documented inline in the sibling's `specs.md` "Implementation note (hydra apply)" preface. This umbrella honours the precedent and does not propose to delete the service.
- [ ] Task 2: Author `specs/bookkeeping-trial-balance/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger` header, `REQ-TB-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table, D1 (aggregation not report builder), D2 (manifest-side drill-through), D3 (declarative balance invariant), D4 (reversed-transaction exclusion)
- [ ] Task 5: Declare the trial-balance `x-openregister-aggregations` block on `lib/Settings/shillinq_register.json` `GLLine` grouping by `(period_id, account_number, side)` with opening / movement / closing buckets per REQ-TB-002
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
