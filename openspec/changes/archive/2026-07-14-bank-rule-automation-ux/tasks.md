# Tasks: bank-rule-automation-ux

## Schema (additive, non-breaking)
- [ ] Task 1: Add `counterparty-iban` to the `MatchingRule.predicates[].op` enum
      and an `iban` field to the predicate item shape in
      `lib/Settings/shillinq_register.json`.
- [ ] Task 2: Add an optional `targetGlAccount` string field to `MatchingRule`
      (title + description per gate-28); document it categorises matched lines to
      a GL account. No `required` change (non-breaking).

## Backend — preview evaluator (read-only)
- [ ] Task 3: Author `lib/Service/BankRulePreviewService.php` with SPDX header —
      `previewRule()`, `suggestForLine()`, private `evaluatePredicates()` +
      `similarity()`. Read-only; no OR writes.
- [ ] Task 4: Implement the six predicate ops (exact-amount, amount-range,
      reference-match [fail-closed regex], counterparty-fuzzy, counterparty-iban,
      date-window [indeterminate-exclusion when no anchor]) with AND semantics.
- [ ] Task 5: Implement `suggestForLine()` — highest-priority active rule whose
      predicates all match → projected `{targetGlAccount, ...}` or null.

## Backend — learning suggestion (proposes only)
- [ ] Task 6: Author `lib/Service/BankRuleSuggestionService.php` with SPDX header —
      `suggestRulesFromHistory(history, k, aiRanker=null)`; deterministic grouping
      + threshold; emits proposals, persists nothing.
- [ ] Task 7: Implement graceful AI degradation — optional ranker re-orders;
      any failure / null falls back to deterministic order (try/catch + debug log).

## Controller + routes
- [ ] Task 8: Author `lib/Controller/BankRuleController.php` (`#[NoAdminRequired]`,
      authenticated-session + administration-scope guards per ADR-005) with
      `preview`, `suggestAccount`, `suggestions`, `acceptSuggestion`.
- [ ] Task 9: `acceptSuggestion` is the ONLY write — persist a `MatchingRule` via
      ObjectService (ADR-022), stamped with resolved administrationId +
      `lifecycleState:'active'`. Bounded OR reads (ADR-058, capped `limit`).
- [ ] Task 10: Register the 4 routes in `appinfo/routes.php` (static segments,
      before the SPA catch-all per ADR-016); declare auth posture per gate-5.

## i18n
- [ ] Task 11: Add English strings to `l10n/en.json` and Dutch to `l10n/nl.json`
      (keys in English per ADR-025).

## Tests (php:8.3-cli container)
- [ ] Task 12: `BankRulePreviewServiceTest` — a rule's dry-run preview matches the
      RIGHT lines and NONE of the wrong ones (exact-amount + reference-match +
      counterparty-fuzzy + counterparty-iban + amount-range); date-window with an
      anchor; fail-closed invalid regex.
- [ ] Task 13: `BankRulePreviewServiceTest::suggestForLine` — a saved rule
      auto-suggests its GL account on a matching transaction; priority ordering
      picks the lowest-priority rule; null on no match.
- [ ] Task 14: `BankRuleSuggestionServiceTest` — the learning path SUGGESTS (does
      not auto-apply / persist) a rule after K repeats; below K → no suggestion.
- [ ] Task 15: `BankRuleSuggestionServiceTest` — graceful degradation: null AI
      provider returns deterministic proposals; a throwing ranker falls back.
- [ ] Task 16: `BankRuleControllerTest` — `acceptSuggestion` creates exactly one
      `MatchingRule` via the fake ObjectService; `preview` wires lines → service;
      validation 400s; auth guard.

## Verify
- [ ] Task 17: Run hydra gates (spdx, route-auth, spec-coverage, orphaned-write,
      register-handler-resolution, schema-property-titles) on the diff.
- [ ] Task 18: Full PHPUnit in `php:8.3-cli` (ext-zip + bcmath/soap/xsl/intl/gd,
      fresh `composer install`); confirm new tests green + no regression vs
      ~3696 baseline (4 pre-existing Symfony\HeaderUtils env errors excluded).
