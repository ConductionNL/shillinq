# Design: bank-rule-automation-ux

## Verify-first gap analysis (against HEAD `7f747bb2`)

The routing audit's premise — "strong matching backend, but no rule-authoring/test
UX and no learning" — was verified file-by-file:

| Claim | Verified state at HEAD | Verdict |
|---|---|---|
| `MatchingRule` register exists with predicates | `shillinq_register.json` §16283 — full schema: `ruleName`, `priority`, `targetType`, `predicates[]` (5 ops), `autoConfirm`, `lifecycleState`, `confidenceScore` | ✅ present |
| Production matching is declarative | `ReconciliationMatch.x-openregister-aggregations.candidateMatches` consumes active `MatchingRule` predicates; no PHP evaluates them | ✅ present |
| Rule authoring UX exists | `src/manifest.json` `MatchingRules` (index) + `MatchingRuleDetail` (detail) — generic OR CRUD | ✅ present, **but blind — no test/preview** |
| A PHP predicate evaluator exists | `grep -rniE 'exact-amount\|counterparty-fuzzy\|date-window' lib/` → **zero** hits in service code. `BankfeedMatcher` scores amount/reference/date but **never reads a rule's `predicates`** | ❌ **GAP** — nothing can answer "what would this rule match?" |
| Dry-run / preview of a rule | `grep -rniE 'dry.?run\|preview\|testRule' lib/` → nothing bank-domain | ❌ **GAP** |
| Learning from repeated corrections | `grep -rniE 'suggest\|learning\|corrections' lib/` → only `gl-account-suggestion-consume` (docudesk receipt extraction — different domain, does not learn counterparty→GL) | ❌ **GAP** |

**Conclusion:** the delta is real and matches the audit. This is **NOT** an
openconnector `rule-pipeline` duplicate — the preview evaluator is bound to the
five REQ-BR-005 bank predicate ops and `BankStatementLine`, and the learning path
learns bank-counterparty → GL-account patterns. Neither is a generic rule engine.

## Declarative-vs-imperative decision (ADR-031)

ADR-031 keeps production business logic declarative (`x-openregister-*`). This
change adds **two PHP services**. Both are justified under ADR-031's stated
exceptions — documented here so the reviewer sees them:

1. **`BankRulePreviewService` — ADR-031 exception (1): OR's extension is
   insufficient.** OR's `candidateMatches` aggregation evaluates *saved, active*
   rules server-side and emits `ReconciliationMatch`es. It has **no primitive to
   dry-run an unsaved draft rule against a candidate window and return "would
   match" without persisting anything.** The operator-facing test UX requires
   exactly that: evaluate a not-yet-saved rule, produce no side effects. The
   service is single-purpose, read-only (zero OR writes), and hard-bound to the
   five predicate ops + `counterparty-iban` — it is a preview harness, not a
   production match path. The production path (the aggregation) is untouched.
   *Track OR gap:* a declarative "rule dry-run" endpoint on OR would retire this.

2. **`BankRuleSuggestionService` — ADR-031 §"What apps SHOULD still write in
   PHP": domain heuristics / NLP.** ADR-031 explicitly keeps "NLP /
   domain-specific text processing" and "domain heuristics" in PHP. Deriving a
   suggested rule from confirmed-match history (group by counterparty, count,
   propose when ≥K) is a domain heuristic. Crucially it **proposes** — it writes
   nothing; the human `accept` endpoint does the single OR write via
   ObjectService (ADR-022). No state machine, no aggregation, no notification is
   re-implemented.

Neither service names a `*Match*`/`*Reconcil*`/`*RuleEngine*` class token, so the
REQ-BR-004 "no rule-engine service" scan is respected; and neither performs a
write-shaped side effect without a real controller caller (gate-52).

## Component shapes

### `BankRulePreviewService` (read-only)
- `previewRule(array $rule, array $candidateLines, ?string $anchorDate): array`
  → `{matchedLineIds, matchedCount, totalEvaluated, sample[], predicateBreakdown}`.
  A line matches iff **all** determinable predicates pass (AND, per REQ-BR-005).
- `suggestForLine(array $line, array $activeRules): ?array` → the highest-priority
  (lowest `priority`) active rule whose predicates all match the line, projected
  to `{matchingRuleId, ruleName, targetType, targetGlAccount, confidence}`; null
  when nothing matches.
- `evaluatePredicates(array $predicates, array $line, ?string $anchorDate)` —
  private; per-op evaluation:
  - `exact-amount` → `abs(line.amount)` equals `amount` to the cent.
  - `amount-range` → `min ≤ abs(line.amount) ≤ max`.
  - `reference-match` → `preg_match(pattern, reference || narrative)` (invalid
    regex fails closed → no match, never a PHP warning).
  - `counterparty-fuzzy` → normalised Levenshtein similarity ≥ `threshold` on
    `counterpartyName` (mirrors `BankfeedMatcher::similarity`).
  - `counterparty-iban` → case-insensitive exact equality on `counterpartyIban`.
  - `date-window` → `line.valueDate` within `days` of `anchorDate` when an anchor
    is supplied; **indeterminate → excluded from the AND with a breakdown flag**
    (never a false positive) when no anchor is available.

### `BankRuleSuggestionService` (proposes, never persists)
- `suggestRulesFromHistory(array $history, int $k, ?object $aiRanker = null): array`
  where `$history` is a normalised list of prior categorisations
  `{counterpartyName, counterpartyIban, targetType, targetGlAccount}`. Groups by
  `(counterpartyName|counterpartyIban, targetGlAccount)`, counts, and for each
  group with `count ≥ k` emits a proposal:
  `{ruleName, predicates:[{op:'counterparty-fuzzy', name, threshold:0.9}],
    targetType, targetGlAccount, occurrences, confidence, source:'history'}`.
  Deterministic order: `occurrences` desc, then counterparty asc.
- **Graceful AI degradation.** When `$aiRanker` is non-null it is asked to
  re-rank; **any** throwable / empty / malformed response falls back to the
  deterministic order (wrapped in try/catch, logged at debug). With `$aiRanker =
  null` (no provider) the deterministic order is returned directly. The service
  never fails because AI is absent.

### `BankRuleController` (`#[NoAdminRequired]`, administration-scoped)
- `POST /api/v1/bank-rules/preview` → reads a bounded window (`limit` capped,
  ADR-058) of `matchState=unmatched` lines for the resolved administration, calls
  `previewRule`, returns the result.
- `POST /api/v1/bank-rules/suggest-account` → body `{lineId}`; reads the line +
  active rules, calls `suggestForLine`.
- `GET  /api/v1/bank-rules/suggestions` → assembles history from recent
  `confirmed` `ReconciliationMatch`es (join bank lines → counterparty, targets →
  GL account), calls `suggestRulesFromHistory(k)`, returns proposals.
- `POST /api/v1/bank-rules/suggestions/accept` → body is an accepted proposal;
  **the only write** — persists a `MatchingRule` via ObjectService, stamped with
  the resolved `administrationId` and `lifecycleState:'active'`.

Administration scope is resolved server-side via `AdministrationContextService`
(IDOR-safe per ADR-005), mirroring `BankStatementImportController`.

## Seed Data

No new seed schemas. Optional demo aid (not shipped as a migration): a
`MatchingRule` seed with `predicates:[{op:'counterparty-iban',
iban:'NL91ABNA0417164300'},{op:'amount-range',min:100,max:2000}]`,
`targetType:'gl-transaction'`, `targetGlAccount:'4000'`,
`ruleName:'Acme B.V. → 4000 Kosten'` demonstrates the preview + suggestion
surfaces against the existing `add-shillinq-bank-reconciliation` demo statement.

## UX surface

The existing `MatchingRuleDetail` authoring page is the reach point. The two new
API surfaces (preview / suggestions) are consumed there as operator actions
("Test rule against recent transactions", "Suggested rules"). Per ADR-024 the
rendering stays generic; no bespoke Vue is added. The whole capability is
backend/data (statement lines + rule predicates + confirmed-match history), so
its scenarios carry `@e2e exclude` consistent with the parent spec's stance —
they are asserted by PHPUnit, not the browser.

## Risks

- **False positives in preview** would erode trust. Mitigated by AND semantics,
  fail-closed regex, and the date-window indeterminate-exclusion rule — the tests
  assert a preview matches the right lines and **none** of the wrong ones.
- **Auto-apply of a learned rule** would be dangerous (silent re-mapping of
  reconciliation). Mitigated structurally: the suggestion service performs no
  write; only the explicit human `accept` endpoint persists a rule.
