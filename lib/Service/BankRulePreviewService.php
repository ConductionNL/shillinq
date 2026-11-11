<?php

/**
 * Bank Rule Preview Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bank-rule-automation-ux — the read-only evaluator behind the
 * MatchingRule authoring "test / dry-run" UX (REQ-BR-011). Given a
 * (possibly unsaved) MatchingRule and a window of unmatched
 * BankStatementLine rows, it reports exactly which lines the rule WOULD
 * match — without ever creating, updating or deleting anything. It also
 * powers the "suggest a GL account for this transaction" hint by picking
 * the highest-priority active rule that matches a line.
 *
 * ADR-031 EXCEPTION (1): OpenRegister's `candidateMatches` aggregation
 * evaluates SAVED, ACTIVE rules server-side and emits ReconciliationMatch
 * records. It has no primitive to dry-run an UNSAVED draft against a
 * candidate window with no side effects — which is exactly what the
 * operator-facing test UX requires. This service is that preview harness:
 * single-purpose, read-only (zero OR writes), and hard-bound to the six
 * REQ-BR-005 bank-matching predicate ops. The production match path (the
 * declarative aggregation) is untouched. This is NOT a generic rule engine
 * (that is openconnector's rule-pipeline) — it evaluates only the bank
 * predicate vocabulary against BankStatementLine shapes.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Read-only evaluator of MatchingRule predicates against BankStatementLine
 * rows (REQ-BR-011). No state, no OR round-trips, no side effects.
 *
 * @psalm-api
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * Pre-existing debt (issue #506): the `@` suppresses a PHP warning on an externally-supplied, possibly-malformed
 * regex pattern, and the return value is explicitly checked (fail-closed).
 */
class BankRulePreviewService {
	/**
	 * Dry-run a draft rule against a window of candidate bank lines.
	 *
	 * A line is matched iff ALL of the rule's determinable predicates pass
	 * (logical AND, REQ-BR-005). A `date-window` predicate with no anchor is
	 * indeterminate and never by itself causes a match.
	 *
	 * @param array<string,mixed> $rule The (possibly unsaved) MatchingRule.
	 *                                  Expected key: predicates (list of predicate maps).
	 * @param list<array<string,mixed>> $candidateLines Unmatched BankStatementLine rows.
	 * @param string|null $anchorDate Optional Y-m-d anchor for date-window predicates.
	 *
	 * `predicateBreakdown` is keyed by the predicate's INDEX, not by a name, and
	 * that index is an int: `evaluatePredicates()` returns `breakdown` as
	 * `array<int,bool>`, and PHP coerces a numeric string array key back to an
	 * integer, so the `(string)` cast this used to carry could not have made the
	 * keys strings.
	 *
	 * @return array{matchedLineIds:list<string>,matchedCount:int,totalEvaluated:int,sample:list<array<string,mixed>>,predicateBreakdown:array<int,int>}
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
	 */
	public function previewRule(array $rule, array $candidateLines, ?string $anchorDate = null): array {
		$predicates = $this->rulePredicates(rule: $rule);
		$tally = new RulePreviewTally();

		foreach ($candidateLines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$facts = new BankLineFacts(line: $line, anchorDate: $anchorDate);
			$tally->record(line: $line, result: $this->evaluatePredicates(predicates: $predicates, facts: $facts));
		}

		return $tally->result(totalEvaluated: count($candidateLines));

	}//end previewRule()

	/**
	 * Suggest a target GL account for a single bank line from the active rules.
	 *
	 * Picks the highest-priority (lowest `priority` value) active rule whose
	 * predicates ALL match the line and projects it to a suggestion. Returns
	 * null when nothing matches (REQ-BR-011 / REQ-BR-004 precedence).
	 *
	 * @param array<string,mixed> $line One BankStatementLine row.
	 * @param list<array<string,mixed>> $activeRules Active MatchingRule rows.
	 *
	 * @return array{matchingRuleId:?string,ruleName:string,targetType:string,targetGlAccount:?string,confidence:float}|null
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
	 */
	public function suggestForLine(array $line, array $activeRules): ?array {
		$best = $this->highestPriorityMatch(
			rules: $activeRules,
			facts: new BankLineFacts(line: $line, anchorDate: null)
		);
		if ($best === null) {
			return null;
		}

		return $this->projectSuggestion(rule: $best);

	}//end suggestForLine()

	/**
	 * Pick the matching rule with the lowest `priority` value (default 100);
	 * on a tie the earlier rule stays.
	 *
	 * @param list<mixed>   $rules The active MatchingRule rows.
	 * @param BankLineFacts $facts The bank line.
	 *
	 * @return array<string,mixed>|null The best matching rule, or null.
	 */
	private function highestPriorityMatch(array $rules, BankLineFacts $facts): array|null {
		$best = null;
		$bestPriority = null;

		foreach ($rules as $rule) {
			if ($this->ruleMatches(rule: $rule, facts: $facts) === false) {
				continue;
			}

			$priority = (int)($rule['priority'] ?? 100);
			if ($bestPriority === null || $priority < $bestPriority) {
				$bestPriority = $priority;
				$best = $rule;
			}
		}

		return $best;

	}//end highestPriorityMatch()

	/**
	 * True when the rule is a row with at least one predicate and all its
	 * determinable predicates pass. An empty rule matches nothing.
	 *
	 * @param mixed         $rule  One MatchingRule row.
	 * @param BankLineFacts $facts The bank line.
	 *
	 * @return bool True when the rule matches the line.
	 */
	private function ruleMatches(mixed $rule, BankLineFacts $facts): bool {
		if (is_array($rule) === false) {
			return false;
		}

		$predicates = $this->rulePredicates(rule: $rule);

		return $predicates !== []
			&& $this->evaluatePredicates(predicates: $predicates, facts: $facts)['matches'] === true;

	}//end ruleMatches()

	/**
	 * The rule's predicate list, or an empty list when it carries none.
	 *
	 * @param array<string,mixed> $rule One MatchingRule row.
	 *
	 * @return array<int|string,mixed> The predicates.
	 */
	private function rulePredicates(array $rule): array {
		if (isset($rule['predicates']) === true && is_array($rule['predicates']) === true) {
			return $rule['predicates'];
		}

		return [];

	}//end rulePredicates()

	/**
	 * Project the picked rule to a suggestion.
	 *
	 * @param array<string,mixed> $rule The picked MatchingRule row.
	 *
	 * @return array{matchingRuleId:?string,ruleName:string,targetType:string,targetGlAccount:?string,confidence:float}
	 */
	private function projectSuggestion(array $rule): array {
		$matchingRuleId = null;
		if (isset($rule['id']) === true) {
			$matchingRuleId = (string)$rule['id'];
		}

		return [
			'matchingRuleId' => $matchingRuleId,
			'ruleName' => (string)($rule['ruleName'] ?? ''),
			'targetType' => (string)($rule['targetType'] ?? 'gl-transaction'),
			'targetGlAccount' => $this->targetGlAccount(rule: $rule),
			'confidence' => (float)($rule['confidenceScore'] ?? 1.0),
		];

	}//end projectSuggestion()

	/**
	 * The rule's target GL account, or null when it names none.
	 *
	 * @param array<string,mixed> $rule The picked MatchingRule row.
	 *
	 * @return string|null The GL account.
	 */
	private function targetGlAccount(array $rule): string|null {
		if (isset($rule['targetGlAccount']) === true && (string)$rule['targetGlAccount'] !== '') {
			return (string)$rule['targetGlAccount'];
		}

		return null;

	}//end targetGlAccount()

	/**
	 * Evaluate a predicate list against one bank line (AND semantics).
	 *
	 * A rule with no predicates, or whose predicates are all indeterminate
	 * (e.g. a lone anchorless date-window), matches nothing — never a false
	 * positive.
	 *
	 * @param array<int|string,mixed> $predicates The rule predicates.
	 * @param BankLineFacts           $facts      The bank line.
	 *
	 * @return array{matches:bool,breakdown:array<int,bool>}
	 */
	private function evaluatePredicates(array $predicates, BankLineFacts $facts): array {
		$breakdown = [];
		$determinate = [];

		foreach ($predicates as $index => $predicate) {
			$outcome = $this->predicateOutcome(predicate: $predicate, facts: $facts);
			$breakdown[$index] = ($outcome === true);
			if ($outcome !== null) {
				$determinate[] = $outcome;
			}
		}

		return [
			'matches' => ($determinate !== [] && in_array(false, $determinate, true) === false),
			'breakdown' => $breakdown,
		];

	}//end evaluatePredicates()

	/**
	 * Evaluate one predicate: true or false, or null when it is indeterminate
	 * and so excluded from the AND. A malformed predicate or an unknown op
	 * fails closed.
	 *
	 * @param mixed         $predicate One predicate map.
	 * @param BankLineFacts $facts     The bank line.
	 *
	 * @return bool|null The outcome, or null when indeterminate.
	 */
	private function predicateOutcome(mixed $predicate, BankLineFacts $facts): bool|null {
		if (is_array($predicate) === false) {
			return false;
		}

		return match ((string)($predicate['op'] ?? '')) {
			'exact-amount' => $this->exactAmountPasses(predicate: $predicate, facts: $facts),
			'amount-range' => $this->amountRangePasses(predicate: $predicate, facts: $facts),
			'reference-match' => $this->referenceMatches(pattern: (string)($predicate['pattern'] ?? ''), facts: $facts),
			'counterparty-fuzzy' => $this->counterpartyNamePasses(predicate: $predicate, facts: $facts),
			'counterparty-iban' => $this->counterpartyIbanPasses(predicate: $predicate, facts: $facts),
			'date-window' => $this->dateWindowOutcome(predicate: $predicate, facts: $facts),
			default => false,
		};

	}//end predicateOutcome()

	/**
	 * `exact-amount`: the absolute amounts agree to within half a cent.
	 *
	 * @param array<string,mixed> $predicate The predicate map.
	 * @param BankLineFacts       $facts     The bank line.
	 *
	 * @return bool True when the amounts agree.
	 */
	private function exactAmountPasses(array $predicate, BankLineFacts $facts): bool {
		$target = (float)($predicate['amount'] ?? 0.0);

		return (abs($facts->absAmount - abs($target)) < 0.005);

	}//end exactAmountPasses()

	/**
	 * `amount-range`: the absolute amount lies within [min, max].
	 *
	 * @param array<string,mixed> $predicate The predicate map.
	 * @param BankLineFacts       $facts     The bank line.
	 *
	 * @return bool True when the amount is in range.
	 */
	private function amountRangePasses(array $predicate, BankLineFacts $facts): bool {
		$min = (float)($predicate['min'] ?? 0.0);
		$max = (float)($predicate['max'] ?? 0.0);

		return ($facts->absAmount >= $min && $facts->absAmount <= $max);

	}//end amountRangePasses()

	/**
	 * `counterparty-fuzzy`: both names are present and similar enough
	 * (default threshold 0.85).
	 *
	 * @param array<string,mixed> $predicate The predicate map.
	 * @param BankLineFacts       $facts     The bank line.
	 *
	 * @return bool True when the names are similar enough.
	 */
	private function counterpartyNamePasses(array $predicate, BankLineFacts $facts): bool {
		$threshold = (float)($predicate['threshold'] ?? 0.85);
		$name = (string)($predicate['name'] ?? '');

		return ($facts->counterpartyName !== '' && $name !== ''
			&& $this->similarity(a: $facts->counterpartyName, b: $name) >= $threshold);

	}//end counterpartyNamePasses()

	/**
	 * `counterparty-iban`: both IBANs are present and equal once normalised.
	 *
	 * @param array<string,mixed> $predicate The predicate map.
	 * @param BankLineFacts       $facts     The bank line.
	 *
	 * @return bool True when the IBANs are equal.
	 */
	private function counterpartyIbanPasses(array $predicate, BankLineFacts $facts): bool {
		$iban = (string)($predicate['iban'] ?? '');

		return ($iban !== '' && $facts->counterpartyIban !== ''
			&& $this->normaliseIban(iban: $facts->counterpartyIban) === $this->normaliseIban(iban: $iban));

	}//end counterpartyIbanPasses()

	/**
	 * `date-window`: the value date lies within N days of the anchor.
	 *
	 * Indeterminate (null) without an anchor date or a value date — excluded
	 * from the AND, so it MUST NOT by itself cause a match. An unparsable date
	 * is a determinate failure.
	 *
	 * @param array<string,mixed> $predicate The predicate map.
	 * @param BankLineFacts       $facts     The bank line.
	 *
	 * @return bool|null The outcome, or null when indeterminate.
	 */
	private function dateWindowOutcome(array $predicate, BankLineFacts $facts): bool|null {
		if ($facts->hasDateWindowDates() === false) {
			return null;
		}

		$days = (int)($predicate['days'] ?? 0);
		$lineTs = strtotime($facts->valueDate);
		$anchorTs = strtotime((string)$facts->anchorDate);
		if ($lineTs === false || $anchorTs === false) {
			return false;
		}

		$deltaDays = (int)(abs($lineTs - $anchorTs) / 86400);

		return ($deltaDays <= $days);

	}//end dateWindowOutcome()

	/**
	 * Match a reference/narrative against a regex pattern, failing closed on
	 * an invalid pattern (no PHP warning, no false match).
	 *
	 * @param string        $pattern The regex body (without delimiters).
	 * @param BankLineFacts $facts   The bank line (reference, narrative fallback).
	 *
	 * @return bool
	 */
	private function referenceMatches(string $pattern, BankLineFacts $facts): bool {
		if ($pattern === '') {
			return false;
		}

		$haystack = $facts->referenceOrNarrative();
		if ($haystack === '') {
			return false;
		}

		$delimited = '/' . str_replace('/', '\/', $pattern) . '/';
		$result = @preg_match($delimited, $haystack);
		if ($result === false) {
			// Invalid regex — fail closed.
			return false;
		}

		return ($result === 1);
	}//end referenceMatches()

	/**
	 * Normalise an IBAN for comparison (strip spaces, upper-case).
	 *
	 * @param string $iban The raw IBAN.
	 *
	 * @return string
	 */
	private function normaliseIban(string $iban): string {
		return strtoupper(str_replace(' ', '', $iban));
	}//end normaliseIban()

	/**
	 * Normalised Levenshtein similarity in [0,1] (the same measure the retired feed matcher used).
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 *
	 * @return float
	 */
	private function similarity(string $a, string $b): float {
		$al = $this->comparableName(name: $a);
		$bl = $this->comparableName(name: $b);

		if ($al === '' || $bl === '') {
			return 0.0;
		}

		if (str_contains($al, $bl) === true || str_contains($bl, $al) === true) {
			return 1.0;
		}

		return max(0.0, (1.0 - (levenshtein($al, $bl) / max(strlen($al), strlen($bl)))));
	}//end similarity()

	/**
	 * Normalise a name for comparison: lower-case, only letters and digits, so
	 * "Acme B.V." and "Acme BV" compare as equal (bank names carry
	 * inconsistent punctuation).
	 *
	 * @param string $name The raw name.
	 *
	 * @return string The comparable form ('' when nothing remains).
	 */
	private function comparableName(string $name): string {
		return (preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '');
	}//end comparableName()
}//end class
