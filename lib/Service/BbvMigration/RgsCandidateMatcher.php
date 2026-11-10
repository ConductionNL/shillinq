<?php

/**
 * RGS Candidate Matcher
 *
 * Scores RGS-decentraal candidates against one Account and picks the best
 * one at or above the confidence threshold: exact referentienummer (100),
 * exact rgsDecentraalCode (95), exact rgsCode (80), else a fuzzy name match
 * (similarity × 0.70, from 50% similarity).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\BbvMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\BbvMigration;

/**
 * Best-candidate selection for the RGS-decentraal mapping backfill.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 */
final class RgsCandidateMatcher {

	/**
	 * Exact-match rules in descending weight: account key, candidate field,
	 * confidence, reason.
	 *
	 * @var list<array{0: string, 1: string, 2: int, 3: string}>
	 */
	private const EXACT_MATCHES = [
		['reference', 'referentienummer', 100, 'exact-referentienummer'],
		['code', 'rgsDecentraalCode', 95, 'exact-rgsDecentraalCode'],
		['code', 'rgsCode', 80, 'exact-rgsCode'],
	];

	/**
	 * Hold the candidates and the threshold.
	 *
	 * @param array<int, array<string, mixed>> $rgsRows   RGS-decentraal candidates.
	 * @param int                              $threshold Minimum confidence to suggest.
	 */
	public function __construct(
		private readonly array $rgsRows,
		private readonly int $threshold,
	) {
	}//end __construct()

	/**
	 * The highest-scoring candidate for the Account, when it reaches the
	 * threshold. The first candidate wins a tie; a 100% match stops the search.
	 *
	 * @param array<string, mixed> $accountRow Account associative array.
	 *
	 * @return array{row: array<string,mixed>, confidence:int, reason:string}|null The candidate, or null.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function best(array $accountRow): array|null {
		$best = $this->bestCandidate(keys: $this->matchKeys(accountRow: $accountRow));
		if ($best === null || $best['confidence'] < $this->threshold) {
			return null;
		}

		return $best;
	}//end best()

	/**
	 * The Account values candidates are matched on.
	 *
	 * @param array<string, mixed> $accountRow Account associative array.
	 *
	 * @return array{reference: string, code: string, name: string} The match keys.
	 */
	private function matchKeys(array $accountRow): array {
		return [
			'reference' => (string)($accountRow['referentienummer'] ?? ''),
			'code' => (string)($accountRow['code'] ?? $accountRow['accountNumber'] ?? ''),
			'name' => mb_strtolower((string)($accountRow['name'] ?? '')),
		];
	}//end matchKeys()

	/**
	 * Pick the highest-scoring candidate.
	 *
	 * @param array{reference: string, code: string, name: string} $keys The match keys.
	 *
	 * @return array{row: array<string,mixed>, confidence:int, reason:string}|null The candidate, or null.
	 */
	private function bestCandidate(array $keys): array|null {
		$best = null;
		foreach ($this->rgsRows as $rgsRow) {
			$score = $this->score(keys: $keys, rgsRow: $rgsRow);
			if ($this->beats(score: $score, best: $best) === true) {
				$best = ['row' => $rgsRow] + $score;
				if ($best['confidence'] >= 100) {
					break;
				}
			}
		}

		return $best;
	}//end bestCandidate()

	/**
	 * Whether a score is a candidate and higher than the best so far.
	 *
	 * @param array{confidence:int, reason:string}|null $score The candidate's score.
	 * @param array{confidence:int}|null                $best  The best so far.
	 *
	 * @return bool True when the score replaces the best.
	 */
	private function beats(array|null $score, array|null $best): bool {
		return $score !== null && ($best === null || $score['confidence'] > $best['confidence']);
	}//end beats()

	/**
	 * Score one candidate: the first exact rule that matches, else the fuzzy
	 * name score.
	 *
	 * @param array{reference: string, code: string, name: string} $keys   The match keys.
	 * @param array<string, mixed>                                 $rgsRow The candidate.
	 *
	 * @return array{confidence:int, reason:string}|null The score, or null when it does not match.
	 */
	private function score(array $keys, array $rgsRow): array|null {
		foreach (self::EXACT_MATCHES as [$key, $field, $confidence, $reason]) {
			if ($keys[$key] !== '' && (string)($rgsRow[$field] ?? '') === $keys[$key]) {
				return ['confidence' => $confidence, 'reason' => $reason];
			}
		}

		return $this->fuzzyScore(accountName: $keys['name'], rgsRow: $rgsRow);
	}//end score()

	/**
	 * Fuzzy name score: 70% of the name similarity, from 50% similarity.
	 *
	 * @param string               $accountName The lower-cased Account name.
	 * @param array<string, mixed> $rgsRow      The candidate.
	 *
	 * @return array{confidence:int, reason:string}|null The score, or null.
	 */
	private function fuzzyScore(string $accountName, array $rgsRow): array|null {
		$candidateName = mb_strtolower((string)($rgsRow['omschrijvingKort'] ?? $rgsRow['name'] ?? ''));
		if ($accountName === '' || $candidateName === '') {
			return null;
		}

		$similarity = 0.0;
		similar_text($accountName, $candidateName, $similarity);
		if ($similarity < 50) {
			return null;
		}

		return ['confidence' => (int)round($similarity * 0.70), 'reason' => 'fuzzy-name-match-' . ((int)$similarity) . '%'];
	}//end fuzzyScore()
}//end class
