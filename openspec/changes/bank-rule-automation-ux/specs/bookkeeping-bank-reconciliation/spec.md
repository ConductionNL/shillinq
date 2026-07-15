# Spec: bookkeeping-bank-reconciliation (delta)

## MODIFIED Requirements

### Requirement: REQ-BR-005: Predicates SHALL include exact-amount, amount-range, reference-match, counterparty-fuzzy, date-window, and counterparty-iban; a rule MAY declare a target GL account

The supported predicate shapes (extensible) MUST include:

| Predicate | Shape | Semantics |
|---|---|---|
| `exact-amount` | `{op: "exact-amount", amount: <number>}` | `abs(line.amount)` equals the predicate amount to the cent |
| `amount-range` | `{op: "amount-range", min: <n>, max: <n>}` | `abs(line.amount)` within `[min, max]` |
| `reference-match` | `{op: "reference-match", pattern: "<regex>"}` | Line reference (or narrative) matches the regex; an invalid regex fails closed (no match) |
| `counterparty-fuzzy` | `{op: "counterparty-fuzzy", name: "<string>", threshold: <0-1>}` | Normalised Levenshtein similarity on `counterpartyName` ≥ threshold |
| `counterparty-iban` | `{op: "counterparty-iban", iban: "<IBAN>"}` | Case-insensitive exact equality on `counterpartyIban` |
| `date-window` | `{op: "date-window", days: <integer>}` | Line date within N days of the target/anchor date; when no anchor is available the predicate is INDETERMINATE and MUST NOT count as a match |

Predicates in the same rule MUST be combined with logical AND. A `MatchingRule`
MAY additionally declare an optional `targetGlAccount` string — the GL account a
matched line is categorised to (used with `targetType: gl-transaction`). Both
additions are additive and non-breaking; existing rules without `counterparty-iban`
or `targetGlAccount` behave exactly as before.

#### Scenario: Counterparty-IBAN + amount-range rule matches a bank line

- **GIVEN** a `MatchingRule` with predicates `[{op: "counterparty-iban", iban:
  "NL91ABNA0417164300"}, {op: "amount-range", min: 100, max: 2000}]` and
  `targetGlAccount: "4000"`
- **AND** an unmatched bank line of €450 with `counterpartyIban:
  "nl91abna0417164300"`
- **WHEN** the rule is evaluated against the line
- **THEN** the line MUST match; **AND** a bank line of €5 000 from the same IBAN
  MUST NOT match (amount out of range).

## ADDED Requirements

### Requirement: REQ-BR-011: An operator SHALL be able to dry-run a draft matching rule against recent unmatched transactions before saving

The system MUST provide a read-only preview that evaluates a supplied (possibly
unsaved) `MatchingRule`'s predicates against a bounded window of recent
`matchState = unmatched` `BankStatementLine`s for the resolved administration and
returns which lines the rule WOULD match. The preview MUST NOT create, update, or
delete any `ReconciliationMatch`, `BankStatementLine`, or `MatchingRule` — it has
no side effects. A line is reported as matched iff ALL of the rule's determinable
predicates pass (AND, per REQ-BR-005); a `date-window` predicate with no anchor is
indeterminate and MUST NOT by itself cause a match.

The preview is exposed at `POST /api/v1/bank-rules/preview` (`#[NoAdminRequired]`,
administration resolved server-side per ADR-005) and returns `{matchedLineIds,
matchedCount, totalEvaluated, sample, predicateBreakdown}`. A companion
`POST /api/v1/bank-rules/suggest-account` returns, for one bank line, the target
GL account of the highest-priority active rule that matches it (or null).

@e2e exclude backend/data: predicate evaluation over statement lines is service + data behaviour, asserted via PHPUnit, not the browser

#### Scenario: Dry-run preview matches the right transactions and none of the wrong ones

- **GIVEN** five unmatched bank lines: L1 €500 ref `INV-C-2026-0001` from
  `Acme B.V.`, L2 €500 ref `INV-C-2026-0002` from `Acme B.V.`, L3 €500 ref
  `INV-C-2026-0001` from `Globex`, L4 €99 ref `INV-C-2026-0001` from `Acme B.V.`,
  L5 €500 no reference from `Acme B.V.`
- **AND** a draft rule with predicates `[{op: "exact-amount", amount: 500},
  {op: "reference-match", pattern: "INV-C-2026-0001"}, {op: "counterparty-fuzzy",
  name: "Acme BV", threshold: 0.8}]`
- **WHEN** the rule is previewed against the five lines
- **THEN** the result MUST report exactly `[L1]` as matched — L2 fails the
  reference, L3 fails the counterparty, L4 fails the amount, L5 fails the
  reference — matchedCount 1, totalEvaluated 5.

#### Scenario: A saved rule suggests a GL account on a matching transaction

- **GIVEN** an active `MatchingRule` `priority: 10` with predicate
  `[{op: "counterparty-iban", iban: "NL91ABNA0417164300"}]` and
  `targetGlAccount: "4000"`, and a lower-precedence rule `priority: 50` also
  matching the same IBAN with `targetGlAccount: "4500"`
- **AND** an unmatched bank line from `NL91ABNA0417164300`
- **WHEN** `suggest-account` runs for the line
- **THEN** it MUST return the `priority: 10` rule's `targetGlAccount: "4000"` —
  the lowest-priority matching rule wins (REQ-BR-004 precedence); **AND** for a
  line from an unknown IBAN it MUST return null.

### Requirement: REQ-BR-012: The system SHALL suggest a matching rule from repeated manual categorisations, and MUST never auto-apply it

When the same counterparty has been manually categorised to the same GL account
K or more times across confirmed reconciliation history, the system MUST offer a
*proposed* `MatchingRule` (deterministic, history-based — no AI required). The
proposal MUST NOT be persisted or activated automatically; it becomes a real
`MatchingRule` only when the operator explicitly accepts it via
`POST /api/v1/bank-rules/suggestions/accept`. Below K repeats, no suggestion is
offered for that counterparty.

If a Nextcloud TaskProcessing / Assistant provider is present, it MAY re-rank the
suggestions; when no provider is available, or the provider errors or returns a
malformed result, the system MUST degrade gracefully to deterministic ordering
(occurrences desc) and MUST still return the suggestions. Suggestions are exposed
at `GET /api/v1/bank-rules/suggestions`.

@e2e exclude backend/data: history grouping + threshold + proposal materialisation are service + data behaviour, asserted via PHPUnit, not the browser

#### Scenario: Repeated categorisation suggests a rule after K repeats but does not apply it

- **GIVEN** confirmed history where `Acme B.V.` was categorised to GL `4000`
  three times and `Globex` to GL `4500` once, with K = 3
- **WHEN** suggestions are computed from the history
- **THEN** exactly one proposal MUST be returned — for `Acme B.V.` → `4000`,
  with a `counterparty-fuzzy` predicate on `Acme B.V.` and `occurrences: 3`;
  **AND** no `MatchingRule` MUST be persisted by computing the suggestion (the
  proposal is materialised only by the explicit accept endpoint); **AND** the
  single `Globex` categorisation MUST NOT produce a proposal (below K).

#### Scenario: Suggestions degrade gracefully without an AI provider

- **GIVEN** history yielding two proposals (`Acme B.V.` ×4, `Beta N.V.` ×3) and
  no TaskProcessing/Assistant provider available
- **WHEN** suggestions are computed
- **THEN** both proposals MUST be returned in deterministic order (`Acme B.V.`
  first, by occurrences desc); **AND** the same call with a ranker that throws
  MUST also return both proposals in the deterministic order — the absence or
  failure of AI MUST NOT drop or error the suggestions.
