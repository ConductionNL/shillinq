<?php

/**
 * Bank Rule Preview Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * Pre-existing debt (issue #506): inherent branch complexity; the `@`
 * suppresses a PHP warning on an externally-supplied, possibly-malformed
 * regex pattern, and the return value is explicitly checked (fail-closed).
 */
class BankRulePreviewService {
	/**
	 * Number of matched sample lines returned in a preview response.
	 *
	 * @var int
	 */
	private const SAMPLE_LIMIT = 20;

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
		$predicates = [];
		if (isset($rule['predicates']) === true && is_array($rule['predicates']) === true) {
			$predicates = $rule['predicates'];
		}

		$matchedIds = [];
		$sample = [];
		$breakdown = [];

		foreach ($candidateLines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$result = $this->evaluatePredicates(predicates: $predicates, line: $line, anchorDate: $anchorDate);

			// Tally per-op pass counts so the operator sees which predicate narrows the window.
			foreach ($result['breakdown'] as $op => $passed) {
				if (isset($breakdown[$op]) === false) {
					$breakdown[$op] = 0;
				}

				if ($passed === true) {
					$breakdown[$op]++;
				}
			}

			if ($result['matches'] === true) {
				$lineId = (string)($line['id'] ?? $line['uuid'] ?? '');
				$matchedIds[] = $lineId;
				if (count($sample) < self::SAMPLE_LIMIT) {
					$sample[] = $line;
				}
			}
		}//end foreach

		return [
			'matchedLineIds' => $matchedIds,
			'matchedCount' => count($matchedIds),
			'totalEvaluated' => count($candidateLines),
			'sample' => $sample,
			'predicateBreakdown' => $breakdown,
		];

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
		$best = null;
		$bestPriority = null;

		foreach ($activeRules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$predicates = [];
			if (isset($rule['predicates']) === true && is_array($rule['predicates']) === true) {
				$predicates = $rule['predicates'];
			}

			// An empty rule matches nothing — never suggest on a rule with no predicates.
			if ($predicates === []) {
				continue;
			}

			$result = $this->evaluatePredicates(predicates: $predicates, line: $line, anchorDate: null);
			if ($result['matches'] === false) {
				continue;
			}

			$priority = (int)($rule['priority'] ?? 100);
			if ($bestPriority === null || $priority < $bestPriority) {
				$bestPriority = $priority;
				$best = $rule;
			}
		}//end foreach

		if ($best === null) {
			return null;
		}

		$glAccount = null;
		if (isset($best['targetGlAccount']) === true && (string)$best['targetGlAccount'] !== '') {
			$glAccount = (string)$best['targetGlAccount'];
		}

		$matchingRuleId = null;
		if (isset($best['id']) === true) {
			$matchingRuleId = (string)$best['id'];
		}

		return [
			'matchingRuleId' => $matchingRuleId,
			'ruleName' => (string)($best['ruleName'] ?? ''),
			'targetType' => (string)($best['targetType'] ?? 'gl-transaction'),
			'targetGlAccount' => $glAccount,
			'confidence' => (float)($best['confidenceScore'] ?? 1.0),
		];

	}//end suggestForLine()

	/**
	 * Evaluate a predicate list against one bank line (AND semantics).
	 *
	 * @param list<array<string,mixed>> $predicates The rule predicates.
	 * @param array<string,mixed> $line The bank line.
	 * @param string|null $anchorDate Optional Y-m-d anchor for date-window.
	 *
	 * @return array{matches:bool,breakdown:array<int,bool>}
	 */
	private function evaluatePredicates(array $predicates, array $line, ?string $anchorDate): array {
		// A rule with no predicates matches nothing (never everything).
		if ($predicates === []) {
			return [
				'matches' => false,
				'breakdown' => [],
			];
		}

		$amount = (float)($line['amount'] ?? 0.0);
		$absAmount = abs($amount);
		$reference = (string)($line['reference'] ?? '');
		$narrative = (string)($line['narrative'] ?? '');
		$counterpartyName = (string)($line['counterpartyName'] ?? '');
		$counterpartyIban = (string)($line['counterpartyIban'] ?? '');
		$valueDate = (string)($line['valueDate'] ?? '');

		$breakdown = [];
		$allPass = true;
		$determinateCount = 0;

		foreach ($predicates as $index => $predicate) {
			if (is_array($predicate) === false) {
				$breakdown[$index] = false;
				$allPass = false;
				$determinateCount++;
				continue;
			}

			$op = (string)($predicate['op'] ?? '');
			$pass = false;

			switch ($op) {
				case 'exact-amount':
					$target = (float)($predicate['amount'] ?? 0.0);
					$pass = (abs($absAmount - abs($target)) < 0.005);
					break;

				case 'amount-range':
					$min = (float)($predicate['min'] ?? 0.0);
					$max = (float)($predicate['max'] ?? 0.0);
					$pass = ($absAmount >= $min && $absAmount <= $max);
					break;

				case 'reference-match':
					$pass = $this->referenceMatches(
						pattern: (string)($predicate['pattern'] ?? ''),
						reference: $reference,
						narrative: $narrative,
					);
					break;

				case 'counterparty-fuzzy':
					$threshold = (float)($predicate['threshold'] ?? 0.85);
					$name = (string)($predicate['name'] ?? '');
					$pass = ($counterpartyName !== '' && $name !== ''
						&& $this->similarity(a: $counterpartyName, b: $name) >= $threshold);
					break;

				case 'counterparty-iban':
					$iban = (string)($predicate['iban'] ?? '');
					$pass = ($iban !== '' && $counterpartyIban !== ''
						&& $this->normaliseIban(iban: $counterpartyIban) === $this->normaliseIban(iban: $iban));
					break;

				case 'date-window':
					// Indeterminate without an anchor date — excluded from the AND
					// (not counted determinate); MUST NOT by itself cause a match.
					if ($anchorDate === null || $anchorDate === '' || $valueDate === '') {
						$breakdown[$index] = false;
						continue 2;
					}

					$days = (int)($predicate['days'] ?? 0);
					$lineTs = strtotime($valueDate);
					$anchorTs = strtotime($anchorDate);
					if ($lineTs === false || $anchorTs === false) {
						$breakdown[$index] = false;
						$allPass = false;
						$determinateCount++;
						continue 2;
					}

					$deltaDays = (int)(abs($lineTs - $anchorTs) / 86400);
					$pass = ($deltaDays <= $days);
					break;

				default:
					// Unknown op — fail closed.
					$pass = false;
					break;
			}//end switch

			$breakdown[$index] = $pass;
			$determinateCount++;
			if ($pass === false) {
				$allPass = false;
			}
		}//end foreach

		// A rule that is entirely indeterminate (e.g. a lone anchorless
		// date-window) matches nothing — never a false positive.
		return [
			'matches' => ($allPass === true && $determinateCount > 0),
			'breakdown' => $breakdown,
		];

	}//end evaluatePredicates()

	/**
	 * Match a reference/narrative against a regex pattern, failing closed on
	 * an invalid pattern (no PHP warning, no false match).
	 *
	 * @param string $pattern The regex body (without delimiters).
	 * @param string $reference The line reference.
	 * @param string $narrative The line narrative (fallback).
	 *
	 * @return bool
	 */
	private function referenceMatches(string $pattern, string $reference, string $narrative): bool {
		if ($pattern === '') {
			return false;
		}

		$haystack = $reference;
		if ($haystack === '') {
			$haystack = $narrative;
		}

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
	 * Normalised Levenshtein similarity in [0,1] (mirrors BankfeedMatcher).
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 *
	 * @return float
	 */
	private function similarity(string $a, string $b): float {
		if ($a === '' || $b === '') {
			return 0.0;
		}

		// Normalise away case + punctuation/whitespace so "Acme B.V." and
		// "Acme BV" compare as equal (bank names carry inconsistent punctuation).
		$al = preg_replace('/[^a-z0-9]/', '', strtolower($a));
		$bl = preg_replace('/[^a-z0-9]/', '', strtolower($b));
		$al = ($al ?? '');
		$bl = ($bl ?? '');

		if ($al === '' || $bl === '') {
			return 0.0;
		}

		if (str_contains($al, $bl) === true || str_contains($bl, $al) === true) {
			return 1.0;
		}

		$maxLen = max(strlen($al), strlen($bl));
		if ($maxLen === 0) {
			return 0.0;
		}

		$distance = levenshtein($al, $bl);

		return max(0.0, (1.0 - ($distance / $maxLen)));
	}//end similarity()
}//end class
