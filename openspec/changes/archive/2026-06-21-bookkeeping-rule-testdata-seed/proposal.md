---
kind: code
depends_on:
  - bookkeeping-rule-audit
---

# Change: bookkeeping-rule-testdata-seed

## Why

The local audit reached 100% only after a one-off live data edit; that is not
reproducible (a clean-env reset restores the non-compliant seed). And only the
rules whose data was satisfiable were enforced — more can be enforced now.

## What Changes

- **Reproducible compliant test data (REQ-RE-007)** — `RuleTestDataSeeder` +
  `occ shillinq:rules:seed-testdata`: idempotently backfill every GLTransaction
  with a `sourceReference` and at least two balanced GLLines, so a fresh
  environment audits at 100%. Test/dev utility only (writes RBAC-bypassed, as an
  admin user, to reach seeded folders); no runtime path uses it.
- **Expanded enforced checks (REQ-RE-008)** — invoice currency must be a valid
  ISO 4217 code (BR-CL-03) and totals must not exceed two decimals
  (BR-DEC-12/13/14). Enforced-rule coverage rises from 14 to 18; the live audit
  stays at 152/152 compliant.

## Out of scope

- A check per rule for the rest of the corpus (added per wave as fields exist).
