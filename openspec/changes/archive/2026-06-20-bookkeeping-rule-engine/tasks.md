# Tasks: bookkeeping-rule-engine

## 1. Engine (REQ-RE-001 / REQ-RE-002)
- [x] `lib/Standards/Violation.php` — immutable {ruleId, severity, source, statement}.
- [x] `lib/Standards/RuleEngine.php` — `evaluate(type, object, context)`, jurisdiction applicability, `hasMandatory()`, `violationFor()`; checks keyed by real catalogue ids.
- [x] `tests/Unit/Standards/RuleEngineTest.php` — compliant/non-compliant invoice, GL completeness, US-vs-NL applicability, `violationFor` hydration.

## 2. Lifecycle enforcement (REQ-RE-003 / REQ-RE-004)
- [x] `lib/Lifecycle/RuleComplianceGuard.php` — `validateInvoice` (wired) + `validateTransaction` (built, reuses BalanceGuard); fail-closed.
- [x] `add-shillinq-rule-compliance-guard.json` — attach `validateInvoice` to `ARInvoice.issue.requires` (clean addition).

## Deferred (follow-up)
- [ ] Wire `validateTransaction` to `GLTransaction.post.requires` once the fragment deep-merge is confirmed to preserve the existing `actions` (allocation rules).
- [ ] Add an executable check per additional rule as fields/data become available (per wave).
