<?php

/**
 * Bank Rule Suggestion Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\Contracts\BankRuleSuggestionServiceInterface;
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
class BankRuleSuggestionService implements BankRuleSuggestionServiceInterface {

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
		$proposals = $this->proposalsAtThreshold(groups: $this->groupHistory(history: $history), threshold: max(1, $k));

		// Deterministic order: occurrences desc, then counterparty asc.
		usort($proposals, self::compareProposals(...));

		if ($aiRanker === null) {
			return $proposals;
		}

		return $this->applyRankerOrFallback(aiRanker: $aiRanker, deterministic: $proposals);
	}//end suggestRulesFromHistory()

	/**
	 * Aggregate occurrences per (counterparty, GL account), in first-seen order.
	 *
	 * @param list<mixed> $history Prior categorisations.
	 *
	 * @return array<string, array<string, mixed>> Group key => aggregated group.
	 */
	private function groupHistory(array $history): array {
		$groups = [];
		foreach ($history as $entry) {
			$categorisation = $this->categorisation(entry: $entry);
			if ($categorisation === null) {
				continue;
			}

			$key = $categorisation['key'];
			$groups[$key] = $this->countOccurrence(group: ($groups[$key] ?? null), categorisation: $categorisation);
		}

		return $groups;
	}//end groupHistory()

	/**
	 * Read one history entry. A categorisation needs a counterparty signal
	 * (the name, else the IBAN) AND a GL target; anything else is ignored.
	 *
	 * @param mixed $entry One history item.
	 *
	 * @return array<string, string>|null The categorisation (key, counterparty, target), or null.
	 */
	private function categorisation(mixed $entry): array|null {
		if (is_array($entry) === false) {
			return null;
		}

		$name = trim((string)($entry['counterpartyName'] ?? ''));
		$iban = trim((string)($entry['counterpartyIban'] ?? ''));
		$gl = trim((string)($entry['targetGlAccount'] ?? ''));
		$counterpartyKey = $this->counterpartyLabel(name: $name, iban: $iban);
		if ($counterpartyKey === '' || $gl === '') {
			return null;
		}

		return [
			'key' => $counterpartyKey . '|' . $gl,
			'counterpartyName' => $name,
			'counterpartyIban' => $iban,
			'targetType' => (string)($entry['targetType'] ?? 'gl-transaction'),
			'targetGlAccount' => $gl,
		];
	}//end categorisation()

	/**
	 * Count one more occurrence of a group, starting it from the first
	 * categorisation and keeping an IBAN a later occurrence carries.
	 *
	 * @param array<string, mixed>|null $group          The group so far, or null.
	 * @param array<string, string>     $categorisation The categorisation.
	 *
	 * @return array<string, mixed> The updated group.
	 */
	private function countOccurrence(array|null $group, array $categorisation): array {
		if ($group === null) {
			$group = [
				'counterpartyName' => $categorisation['counterpartyName'],
				'counterpartyIban' => $categorisation['counterpartyIban'],
				'targetType' => $categorisation['targetType'],
				'targetGlAccount' => $categorisation['targetGlAccount'],
				'occurrences' => 0,
			];
		}

		$group['occurrences']++;

		// Preserve an IBAN if a later occurrence carries one.
		if ($categorisation['counterpartyIban'] !== '' && $group['counterpartyIban'] === '') {
			$group['counterpartyIban'] = $categorisation['counterpartyIban'];
		}

		return $group;
	}//end countOccurrence()

	/**
	 * A proposal for every group at or above the threshold.
	 *
	 * @param array<string, array<string, mixed>> $groups    The aggregated groups.
	 * @param int                                 $threshold Repeat threshold (>= 1).
	 *
	 * @return list<array<string,mixed>> The proposals, in group order.
	 */
	private function proposalsAtThreshold(array $groups, int $threshold): array {
		$proposals = [];
		foreach ($groups as $group) {
			if ($group['occurrences'] >= $threshold) {
				$proposals[] = $this->toProposal(group: $group);
			}
		}

		return $proposals;
	}//end proposalsAtThreshold()

	/**
	 * Order proposals by occurrences (desc), then rule name (asc).
	 *
	 * @param array<string,mixed> $left  One proposal.
	 * @param array<string,mixed> $right The other proposal.
	 *
	 * @return int The comparison.
	 */
	private static function compareProposals(array $left, array $right): int {
		if ($left['occurrences'] !== $right['occurrences']) {
			return ($right['occurrences'] <=> $left['occurrences']);
		}

		return strcmp((string)$left['ruleName'], (string)$right['ruleName']);
	}//end compareProposals()

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
		$label = $this->counterpartyLabel(name: $name, iban: $iban);
		$occurrences = (int)$group['occurrences'];

		// Confidence grows with evidence, capped just under 1.0 (never certain
		// from history alone; the human confirms).
		$confidence = min(0.95, (0.6 + (0.05 * $occurrences)));

		return [
			'ruleName' => $label . ' → ' . $gl,
			'predicates' => $this->counterpartyPredicates(name: $name, iban: $iban),
			'targetType' => (string)$group['targetType'],
			'targetGlAccount' => $gl,
			'occurrences' => $occurrences,
			'confidence' => round($confidence, 2),
			'source' => 'history',
		];

	}//end toProposal()

	/**
	 * The counterparty as a group key or rule label: the name, else the IBAN.
	 *
	 * @param string $name The counterparty name.
	 * @param string $iban The counterparty IBAN.
	 *
	 * @return string The label.
	 */
	private function counterpartyLabel(string $name, string $iban): string {
		if ($name !== '') {
			return $name;
		}

		return $iban;
	}//end counterpartyLabel()

	/**
	 * Prefer an exact IBAN predicate when we have one (higher precision);
	 * otherwise a fuzzy name predicate.
	 *
	 * @param string $name The counterparty name.
	 * @param string $iban The counterparty IBAN.
	 *
	 * @return list<array<string,mixed>> The predicates.
	 */
	private function counterpartyPredicates(string $name, string $iban): array {
		if ($iban !== '') {
			return [['op' => 'counterparty-iban', 'iban' => $iban]];
		}

		return [['op' => 'counterparty-fuzzy', 'name' => $name, 'threshold' => 0.9]];
	}//end counterpartyPredicates()

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

		return $this->validRanking(ranked: $ranked, deterministic: $deterministic);
	}//end applyRankerOrFallback()

	/**
	 * The ranker's order when it is usable, else the deterministic order. A
	 * ranker MUST return the same set (just re-ordered). Any shape drift
	 * (non-array, wrong count, an item without a GL target) is treated as
	 * unusable — fail safe.
	 *
	 * @param mixed                     $ranked        What the ranker returned.
	 * @param list<array<string,mixed>> $deterministic The deterministic proposals.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function validRanking(mixed $ranked, array $deterministic): array {
		if (is_array($ranked) === false || count($ranked) !== count($deterministic)) {
			$this->logger->debug('BankRuleSuggestionService: AI ranker returned a malformed result; using deterministic order');
			return $deterministic;
		}

		foreach ($ranked as $item) {
			if ($this->isRankedProposal(item: $item) === false) {
				return $deterministic;
			}
		}

		return array_values($ranked);
	}//end validRanking()

	/**
	 * Whether a ranked item is a proposal with a GL target.
	 *
	 * @param mixed $item One ranked item.
	 *
	 * @return bool True when usable.
	 */
	private function isRankedProposal(mixed $item): bool {
		return is_array($item) === true && isset($item['targetGlAccount']) === true;
	}//end isRankedProposal()
}//end class
