---
kind: code
depends_on:
  - bookkeeping-rule-engine
---

# Change: bookkeeping-rule-audit

## Why

The rule engine enforces invoices at issue, but two things were missing: GL
transactions were not yet gated on post (the wiring was deferred pending a
deep-merge safety check), and there was no way to answer "does shillinq actually
comply?" across its live data.

## What Changes

- **GLTransaction.post enforcement (REQ-RE-006)** — `GLTransaction.post.requires`
  now points at `RuleComplianceGuard::validateTransaction`. This was held back
  until proven safe: `SettingsService::deepMergeConfig` recurses into
  `transitions.post` and replaces only the `requires` scalar, so the existing
  allocation-rule `actions` survive (verified). The double-entry balance invariant
  is preserved by delegating to the existing `BalanceGuard`.
- **Correct line linking** — GLLines reference their parent via `transactionId`
  matching EITHER the OpenRegister id OR the human `transactionNumber`; the guard
  and the audit now query both and merge (fixing a latent mismatch shared with
  `BalanceGuard` that made balanced transactions look line-less).
- **NEW audit (REQ-RE-005)** — `RuleAuditService` + `occ shillinq:rules:audit`
  run the engine across every supported object in the register and report
  coverage (enforceable vs machine-checkable rules), objects checked/compliant,
  and violations by severity and by rule. Read-only.

## Out of scope

- A check per rule for the rest of the corpus (added per wave as fields exist).
