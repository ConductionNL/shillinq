# Design — Member 11: testing

## Scope

This `kind: code` member authors the test matrix for the built BBV
capability. It changes no production behaviour.

## Decisions carried from the giant (Phase 6)

- Unit tests cover `ComplianceService` maths-orchestration.
- Integration test reuses member 01's scaffold (real programmes,
  mappings, GL fixtures) — no shared-mock edits (per the
  no-mock-fixes-real-functionality preference).
- Browser tests cover the full CRUD + dashboard + scoping + validation
  flows (Playwright, ADR-008 — UI-only; API assertions via the
  integration test, not Playwright-to-pass).

## Test surfaces

| Layer | Target |
|---|---|
| Unit | `ComplianceServiceTest` — spend levels, multi-account, rounding, FY scope |
| Integration | `ComplianceAggregationTest` — dashboard data == aggregation; updates on GL write |
| Browser | dashboard widgets/badges; mapping index search/add/click; detail create/edit/delete; FY scoping; validation/errors |
| Smoke | all routes 200; schema fields populated; seed loaded |

## Security (ADR-005)

Tests include the negative paths that prove the security posture: over-
allocation rejection (member 03), cross-administration scope isolation
(member 09), and that validation cannot be bypassed from the detail
page. Assert the component's own bound behaviour, never a stub.
