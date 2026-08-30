# Tasks: bookkeeping-rule-audit

## 1. GL enforcement (REQ-RE-006)
- [x] Add `GLTransaction.post.requires = RuleComplianceGuard::validateTransaction` to the guard register fragment (deep-merge preserves `actions`, verified).
- [x] Fix GLLine loading to match `transactionId` against id OR `transactionNumber` (guard + audit).

## 2. Audit (REQ-RE-005)
- [x] `lib/Service/RuleAuditService.php` — read-only audit over supported object types; coverage + per-type + by-rule aggregation.
- [x] `lib/Command/RulesAuditCommand.php` + `info.xml <commands>` — `occ shillinq:rules:audit`.
- [x] `RuleEngine::supportedTypes()` / `checkedRuleIds()` for coverage.

## 3. Verify
- [x] Audit run against live data: 152 objects, 131 compliant; guard ALLOW/BLOCK verified end-to-end via DI.
