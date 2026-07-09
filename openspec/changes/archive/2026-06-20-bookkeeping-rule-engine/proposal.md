---
kind: code
depends_on:
  - bookkeeping-rule-corpus
---

# Change: bookkeeping-rule-engine

## Why

The rule corpus (`bookkeeping-rules`, ~1,300 rules) is reference data — it does
not yet *do* anything. To make the machine-checkable rules enforce, the
bookkeeping flow needs an executable layer that evaluates an object against the
rules that apply to it and blocks non-compliant transitions, the same way
`BalanceGuard` already gates `GLTransaction.post` on the double-entry invariant.

## What Changes

- **NEW `RuleEngine`** (`lib/Standards/`) — evaluates an object of a given type
  against the machine-checkable rules registered for it, scoped by jurisdiction
  (a rule applies to its own country, plus EU-wide rules for EU members and
  `global` rules everywhere), and returns `Violation`s carrying each rule's id,
  `severity` and `source` straight from the `RuleCatalogue`.
- **NEW `RuleComplianceGuard`** (`lib/Lifecycle/`, ADR-031) — loads the object,
  runs the engine and blocks the transition on any `mandatory` violation
  (logging the rest); fail-closed. `validateInvoice` is wired to
  **`ARInvoice.issue`** (a transition with no prior guard — clean addition),
  enforcing EN 16931 BR-02/03/05/13/14, BR-CO-15 (total-with-VAT calculation) and
  sequential numbering. `validateTransaction` (GL completeness + sequential
  numbering, reusing `BalanceGuard` for balance) is built and unit-tested.
- **Wiring** via `add-shillinq-rule-compliance-guard.json` (register fragment).

## Out of scope / deferred

- **GLTransaction.post wiring** — `post` already carries
  `requires: BalanceGuard::isBalanced` plus allocation `actions`, and `requires`
  is single-valued; overriding it needs the fragment deep-merge confirmed to
  preserve those `actions` first. The guard method is ready.
- A check per rule for the rest of the corpus — added per wave as fields/data
  become available; today the engine enforces the subset that maps to shillinq's
  actual GL/invoice fields.
