# Test Plan: <change-name>

<!-- Map every spec scenario to a concrete test case. Read ~/.github/docs/claude/writing-specs.md for scenario format.
     Test types and commands:
     - Functional (browser): /test-functional — user-facing behaviour via browser
     - API: /test-api — REST endpoints, Newman/Postman collections
     - Persona: /test-persona-<name> — role-based flows
     - Accessibility: /test-accessibility — WCAG compliance
     - Performance: /test-performance — load time, API response time
     - Regression: /test-regression — existing functionality not broken
     - Security: /test-security — AVG/GDPR compliance, authorisation checks
     If the change touches multiple affected projects, group test cases by project. -->

## REQ-001: <requirement name>

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-001 | <spec scenario name> | Functional | `/test-functional` | <persona perspective if applicable> |

## Coverage Summary

<!-- Which requirements are covered and any deliberately untested areas. -->

| Requirement | Covered by | Gaps |
|-------------|-----------|------|
| REQ-001 | TC-001 | <none / reason> |

<!-- After implementation: promote TCs with ongoing regression value (key user flows, cross-app
     integrations, compliance checks) to reusable test scenarios via /test-scenario-create. -->
