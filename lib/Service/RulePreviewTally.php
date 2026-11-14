<?php

/**
 * Rule Preview Tally
 *
 * Accumulates the outcome of a bank-rule dry run line by line: the matched
 * line ids, a capped sample of matched lines and the per-predicate pass
 * counts (REQ-BR-011).
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
 * Progress of one BankRulePreviewService::previewRule() run.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
 */
final class RulePreviewTally {
	/**
	 * Number of matched sample lines returned in a preview response.
	 *
	 * @var int
	 */
	private const SAMPLE_LIMIT = 20;

	/**
	 * Ids of the matched lines, in candidate order.
	 *
	 * @var list<string>
	 */
	private array $matchedIds = [];

	/**
	 * The first matched lines, up to SAMPLE_LIMIT.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $sample = [];

	/**
	 * Pass count per predicate index.
	 *
	 * @var array<int|string,int>
	 */
	private array $breakdown = [];

	/**
	 * Record one evaluated line.
	 *
	 * @param array<string,mixed>                              $line   The bank line.
	 * @param array{matches:bool,breakdown:array<int,bool>} $result The predicate evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
	 */
	public function record(array $line, array $result): void {
		// Tally per-op pass counts so the operator sees which predicate narrows the window.
		foreach ($result['breakdown'] as $op => $passed) {
			$this->countPredicate(op: $op, passed: $passed);
		}

		if ($result['matches'] === true) {
			$this->matchedIds[] = (string)($line['id'] ?? $line['uuid'] ?? '');
			if (count($this->sample) < self::SAMPLE_LIMIT) {
				$this->sample[] = $line;
			}
		}
	}//end record()

	/**
	 * Build the preview response.
	 *
	 * @param int $totalEvaluated Number of candidate lines offered.
	 *
	 * @return array{matchedLineIds:list<string>,matchedCount:int,totalEvaluated:int,sample:list<array<string,mixed>>,predicateBreakdown:array<int|string,int>}
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
	 */
	public function result(int $totalEvaluated): array {
		return [
			'matchedLineIds' => $this->matchedIds,
			'matchedCount' => count($this->matchedIds),
			'totalEvaluated' => $totalEvaluated,
			'sample' => $this->sample,
			'predicateBreakdown' => $this->breakdown,
		];
	}//end result()

	/**
	 * Count one predicate outcome; a seen predicate always has an entry.
	 *
	 * @param int|string $op     The predicate index.
	 * @param bool       $passed Whether it passed on this line.
	 *
	 * @return void
	 */
	private function countPredicate(int|string $op, bool $passed): void {
		if (isset($this->breakdown[$op]) === false) {
			$this->breakdown[$op] = 0;
		}

		if ($passed === true) {
			$this->breakdown[$op]++;
		}
	}//end countPredicate()
}//end class
