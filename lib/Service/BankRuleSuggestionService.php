<?php

/**
 * Bank Rule Suggestion Service
 *
 * Bank-rule-automation-ux — the learning path (REQ-BR-012). It watches how
 * an operator repeatedly categorises the same counterparty and, once the
 * same counterparty has been booked to the same GL account K or more times,
 * PROPOSES a MatchingRule so the operator can stop doing it by hand. The
 * suggestion is deterministic and history-based — no AI is required. If a
 * Nextcloud TaskProcessing / Assistant provider is present it MAY re-rank the
 * proposals, but the service degrades gracefully to deterministic ordering
 * when no provider is available or the provider errors.
 *
 * Crucially it only PROPOSES — it writes nothing. A proposal becomes a real
 * MatchingRule only when the operator explicitly accepts it (the accept
 * endpoint does the single OpenRegister write). This keeps the human in the
 * loop: a learned rule silently re-maps reconciliation, so it must never
 * auto-apply.
 *
 * ADR-031: this is a domain heuristic ("NLP / domain-specific text
 * processing", "domain heuristics belong in code") — an explicitly-permitted
 * PHP seam. It re-implements no lifecycle, aggregation or notification.
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

use Psr\Log\LoggerInterface;

/**
 * Derives PROPOSED MatchingRules from confirmed reconciliation history
 * (REQ-BR-012). Persists nothing.
 *
 * @psalm-api
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-012)
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class BankRuleSuggestionService {
	/**
	 * Default repeat threshold when the caller does not supply one.
	 *
	 * @var int
	 */
	public const DEFAULT_THRESHOLD = 3;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger (AI-degradation diagnostics).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function suggestRulesFromHistory(array $history, int $k = self::DEFAULT_THRESHOLD, ?object $aiRanker = null): array {
		if ($k < 1) {
			$k = 1;
		}

		// Aggregate occurrences per (counterparty, GL account).
		$groups = [];
		foreach ($history as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = trim((string)($entry['counterpartyName'] ?? ''));
			$iban = trim((string)($entry['counterpartyIban'] ?? ''));
			$gl = trim((string)($entry['targetGlAccount'] ?? ''));

			// A categorisation needs a counterparty signal AND a GL target.
			$counterpartyKey = $iban;
			if ($name !== '') {
				$counterpartyKey = $name;
			}

			if ($counterpartyKey === '' || $gl === '') {
				continue;
			}

			$groupKey = $counterpartyKey . '|' . $gl;
			if (isset($groups[$groupKey]) === false) {
				$groups[$groupKey] = [
					'counterpartyName' => $name,
					'counterpartyIban' => $iban,
					'targetType' => (string)($entry['targetType'] ?? 'gl-transaction'),
					'targetGlAccount' => $gl,
					'occurrences' => 0,
				];
			}

			$groups[$groupKey]['occurrences']++;

			// Preserve an IBAN if a later occurrence carries one.
			if ($iban !== '' && $groups[$groupKey]['counterpartyIban'] === '') {
				$groups[$groupKey]['counterpartyIban'] = $iban;
			}
		}//end foreach

		// Emit a proposal for every group at or above the threshold.
		$proposals = [];
		foreach ($groups as $group) {
			if ($group['occurrences'] < $k) {
				continue;
			}

			$proposals[] = $this->toProposal(group: $group);
		}

		// Deterministic order: occurrences desc, then counterparty asc.
		usort(
			$proposals,
			static function (array $left, array $right): int {
				if ($left['occurrences'] !== $right['occurrences']) {
					return ($right['occurrences'] <=> $left['occurrences']);
				}

				return strcmp((string)$left['ruleName'], (string)$right['ruleName']);
			}
		);

		if ($aiRanker === null) {
			return $proposals;
		}

		return $this->applyRankerOrFallback(aiRanker: $aiRanker, deterministic: $proposals);
	}//end suggestRulesFromHistory()

	/**
	 * Build a proposal (an unsaved MatchingRule shape) from an aggregated group.
	 *
	 * @param array<string,mixed> $group The aggregated counterparty/GL group.
	 *
	 * @return array<string,mixed>
	 */
	private function toProposal(array $group): array {
		$name = (string)$group['counterpartyName'];
		$iban = (string)$group['counterpartyIban'];
		$gl = (string)$group['targetGlAccount'];

		// Prefer an exact IBAN predicate when we have one (higher precision);
		// otherwise a fuzzy name predicate.
		if ($iban !== '') {
			$predicates = [
				[
					'op' => 'counterparty-iban',
					'iban' => $iban,
				],
			];
			$label = $iban;
			if ($name !== '') {
				$label = $name;
			}
		} else {
			$predicates = [
				[
					'op' => 'counterparty-fuzzy',
					'name' => $name,
					'threshold' => 0.9,
				],
			];
			$label = $name;
		}//end if

		$occurrences = (int)$group['occurrences'];

		// Confidence grows with evidence, capped just under 1.0 (never certain
		// from history alone; the human confirms).
		$confidence = min(0.95, (0.6 + (0.05 * $occurrences)));

		return [
			'ruleName' => $label . ' → ' . $gl,
			'predicates' => $predicates,
			'targetType' => (string)$group['targetType'],
			'targetGlAccount' => $gl,
			'occurrences' => $occurrences,
			'confidence' => round($confidence, 2),
			'source' => 'history',
		];

	}//end toProposal()

	/**
	 * Ask the optional AI ranker to re-order the proposals, falling back to the
	 * deterministic order on any absence / failure / malformed response.
	 *
	 * @param object $aiRanker Ranker with rank(array): array.
	 * @param list<array<string,mixed>> $deterministic The deterministic proposals.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function applyRankerOrFallback(object $aiRanker, array $deterministic): array {
		if (method_exists($aiRanker, 'rank') === false) {
			return $deterministic;
		}

		try {
			$ranked = $aiRanker->rank($deterministic);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BankRuleSuggestionService: AI ranker failed; using deterministic order',
				['exception' => $e->getMessage()]
			);
			return $deterministic;
		}

		// A ranker MUST return the same set (just re-ordered). Any shape drift
		// (non-array, wrong count) is treated as unusable — fail safe.
		if (is_array($ranked) === false || count($ranked) !== count($deterministic)) {
			$this->logger->debug('BankRuleSuggestionService: AI ranker returned a malformed result; using deterministic order');
			return $deterministic;
		}

		$clean = [];
		foreach ($ranked as $item) {
			if (is_array($item) === false || isset($item['targetGlAccount']) === false) {
				return $deterministic;
			}

			$clean[] = $item;
		}

		return $clean;
	}//end applyRankerOrFallback()
}//end class
