<?php

/**
 * Contract of BankRulePreviewService
 *
 * The public surface of BankRulePreviewService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BankRulePreviewService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BankRulePreviewServiceInterface {

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
	public function previewRule(array $rule, array $candidateLines, ?string $anchorDate = null): array;

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
	public function suggestForLine(array $line, array $activeRules): ?array;
}//end interface
