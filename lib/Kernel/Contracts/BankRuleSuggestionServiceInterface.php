<?php

/**
 * Contract of BankRuleSuggestionService
 *
 * The public surface of BankRuleSuggestionService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BankRuleSuggestionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BankRuleSuggestionServiceInterface {

	/**
	 * Default repeat threshold when the caller does not supply one.
	 *
	 * @var int
	 */
	public const DEFAULT_THRESHOLD = 3;

	/**
	 * Suggest MatchingRule proposals from a normalised categorisation history.
	 *
	 * Groups the history by (counterparty, targetGlAccount) and, for each group
	 * seen `k` or more times, emits ONE proposal. Nothing is persisted.
	 *
	 * @param list<array<string,mixed>> $history Prior categorisations. Each item:
	 *                                           {counterpartyName, counterpartyIban,
	 *                                           targetType, targetGlAccount}.
	 * @param int $k Repeat threshold (>= 1).
	 * @param object|null $aiRanker Optional ranker exposing
	 *                              rank(array $proposals): array. When
	 *                              null / erroring, deterministic order.
	 *
	 * @return list<array<string,mixed>> Proposed rules (never persisted).
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-012)
	 */
	public function suggestRulesFromHistory(array $history, int $k = self::DEFAULT_THRESHOLD, ?object $aiRanker = null): array;
}//end interface
