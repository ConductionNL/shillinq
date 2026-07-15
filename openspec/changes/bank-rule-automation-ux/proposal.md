---
kind: feature
depends_on: []
---

# Change: bank-rule-automation-ux

## Why

Bank reconciliation is the single most time-consuming recurring task a ZZP'er or
MKB bookkeeper does in shillinq. The T2 `bookkeeping-bank-reconciliation` change
delivered a strong **matching backend** — `MatchingRule` predicates
(REQ-BR-004/005), the `candidateMatches` aggregation, the confirm/reject
lifecycle, and suspense routing — plus a manifest authoring page
(`MatchingRules` index + `MatchingRuleDetail`). But two productivity gaps remain,
confirmed against HEAD:

1. **No rule test / dry-run.** An operator can author a `MatchingRule` on the
   generic OR detail page, but there is no way to *test* it before saving — no
   "this rule would match these N unmatched transactions (and none of the
   wrong ones)" preview. Authoring is blind: you save, wait for the next
   aggregation pass, and discover only then whether the predicate window was too
   wide or too narrow. There is **no PHP that evaluates `MatchingRule`
   predicates against `BankStatementLine`s** at HEAD (`BankfeedMatcher` does its
   own fuzzy amount/reference/date scoring and never reads a rule's predicates;
   the production match path is the declarative aggregation).

2. **No learning from corrections.** When an operator manually categorises the
   same counterparty the same way over and over — Acme B.V. → GL 4000 every
   month — the system never notices and never offers to turn that repetition
   into a rule. Every month is hand-work. There is no suggestion surface at HEAD
   (`gl-account-suggestion-consume` is receipt-extraction via docudesk, a
   different domain — it does not learn bank-counterparty → GL patterns).

This change closes both gaps **without building a generic rule engine** (that is
openconnector's `rule-pipeline`). It is firmly bank-reconciliation-domain: it
evaluates exactly the five REQ-BR-005 predicate ops (plus a new
`counterparty-iban` op) and it learns bank-counterparty → GL-account patterns
from confirmed reconciliation history.

## What Changes

- **Rule dry-run preview (`BankRulePreviewService`).** Evaluate an *unsaved*
  draft `MatchingRule`'s predicates against a bounded window of recent
  `unmatched` `BankStatementLine`s and return `{matchedLineIds, matchedCount,
  totalEvaluated, sample, predicateBreakdown}`. Read-only — creates **no**
  `ReconciliationMatch` records. The operator sees exactly which lines a rule
  would hit before committing it.
- **Saved-rule GL-account suggestion (`BankRulePreviewService::suggestForLine`).**
  Given one `BankStatementLine` and the administration's active `MatchingRule`s,
  return the highest-priority matching rule's target GL account — the
  "this transaction looks like GL 4000" hint on the reconciliation surface.
- **Learning suggestion (`BankRuleSuggestionService`).** Deterministically scan
  confirmed reconciliation history; where the same counterparty has been
  categorised to the same GL account **K or more** times, emit a *proposed*
  `MatchingRule` (never auto-applied — the human accepts it). If a NC
  TaskProcessing/Assistant provider is present it MAY re-rank suggestions; it
  MUST degrade gracefully to deterministic ordering when absent or failing.
- **Human-confirm materialisation.** `POST /api/v1/bank-rules/suggestions/accept`
  persists an accepted proposal as a real `MatchingRule` via OpenRegister's
  ObjectService (ADR-022). Nothing is ever written without this explicit accept.
- **Schema (additive, non-breaking).** Add the `counterparty-iban` predicate op
  and an optional `targetGlAccount` field to `MatchingRule` so a rule can
  express "categorise matched lines to GL account X" — the target the learning
  path suggests.

## Impact

- Affected specs: `bookkeeping-bank-reconciliation` (delta — modifies REQ-BR-005
  to add `counterparty-iban` + `targetGlAccount`; adds REQ-BR-011 rule preview /
  test and REQ-BR-012 learning suggestion).
- Affected code: `lib/Service/BankRulePreviewService.php` (new),
  `lib/Service/BankRuleSuggestionService.php` (new),
  `lib/Controller/BankRuleController.php` (new), `appinfo/routes.php` (4 routes),
  `lib/Settings/shillinq_register.json` (MatchingRule schema additive fields),
  `l10n/en.json` + `l10n/nl.json` (strings).
- No new DB tables (ADR-001/022). No change to the production match path — the
  declarative `candidateMatches` aggregation is untouched.
