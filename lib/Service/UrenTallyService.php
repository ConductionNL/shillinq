<?php

/**
 * Uren Tally Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-of-day idempotent tally service per REQ-URC-001. Sums UrenDagregistratie
 * entries for a given (enterpriseId, date) pair, applies the reistijd-cap
 * (REISTIJD_ZAKELIJK ≤ 4 uur/dag) via UrenDagregistratieGuard::pasReistijdCapToe,
 * filters categories that have telTMee=false on the UrenCategorie definition,
 * and produces a per-day total that the year-tally batch adds to
 * UrencriteriumYear.currentHours.
 *
 * Idempotent by construction: the service is a pure aggregator over the supplied
 * collection. Calling it twice on the same (entries, date) yields the same total.
 * The scheduler's idempotency check (REQ-URC-001) is the caller's responsibility:
 * once a tally for (enterpriseId, date) is written, the scheduler must skip the
 * day. This service does not write OR records — it returns the canonical patch
 * shape the caller persists.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\Contracts\UrenDagregistratieGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates UrenDagregistratie into a per-day total with the reistijd-cap applied.
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
 */
final class UrenTallyService {

	/**
	 * Categories that do NOT count toward the urencriterium total.
	 *
	 * The UrenCategorie seed has telTMee=true for every standard category, so this
	 * list is empty by default. The constant is kept as the integration point for
	 * future categories (e.g. a future "NIET_TELLEND" debug category).
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore -- intentional: integration point for future non-counting categories
	private const NON_COUNTING_CATEGORIES = []; // @phpstan-ignore-line

	/**
	 * Construct the service.
	 *
	 * @param UrenDagregistratieGuardInterface $guard The reistijd-cap policy owner.
	 * @param LoggerInterface $logger Diagnostics logger.
	 */
	public function __construct(
		private readonly UrenDagregistratieGuardInterface $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Aggregate UrenDagregistratie entries for one day.
	 *
	 * Returns:
	 *  - totalHours: float — sum of counted hours after cap and category filter.
	 *  - perCategory: array<string, float> — counted hours per category.
	 *  - overages: array<int, array{category: string, ingevoerd: float, geteld: float, notitie: string}>
	 *    — entries whose hours exceeded a category cap.
	 *
	 * @param array<int, array<string, mixed>> $entries Day entries.
	 *
	 * @return array{totalHours: float, perCategory: array<string, float>, overages: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
	 */
	public function tallyDag(array $entries): array {
		$total = 0.0;
		$perCategory = [];
		$overages = [];

		foreach ($entries as $entry) {
			$counted = $this->countedEntry(entry: $entry);
			if ($counted === null) {
				continue;
			}

			$category = $counted['category'];
			$total += $counted['geteld'];
			$perCategory[$category] = (($perCategory[$category] ?? 0.0) + $counted['geteld']);
			if ($counted['notitie'] !== null) {
				$overages[] = $counted;
			}
		}

		return [
			'totalHours' => $total,
			'perCategory' => $perCategory,
			'overages' => $overages,
		];

	}//end tallyDag()

	/**
	 * One entry's category, entered hours, counted hours (travel time capped)
	 * and cap note; null for an entry without a category.
	 *
	 * @param mixed $entry A day entry.
	 *
	 * @return array{category:string,ingevoerd:float,geteld:float,notitie:string|null}|null
	 */
	private function countedEntry(mixed $entry): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$category = (string)($entry['category'] ?? '');
		if ($category === '') {
			return null;
		}

		$hours = (float)($entry['hours'] ?? 0);
		$capInfo = $this->guard->pasReistijdCapToe(category: $category, hours: $hours);

		return [
			'category' => $category,
			'ingevoerd' => $hours,
			'geteld' => $capInfo['countedHours'],
			'notitie' => $capInfo['capNote'],
		];

	}//end countedEntry()

	/**
	 * Aggregate YTD UrenDagregistratie entries into a UrencriteriumYear patch.
	 *
	 * Sums every counted hour across the supplied YTD entries and returns the
	 * canonical {currentHours, calculatedOn} patch shape. The caller persists this
	 * onto the UrencriteriumYear record.
	 *
	 * @param array<int, array<string, mixed>> $entries YTD entries.
	 * @param string $now ISO-8601 calculatedOn timestamp.
	 *
	 * @return array{currentHours: float, calculatedOn: string}
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
	 */
	public function tallyYearToDate(array $entries, string $now): array {
		$total = 0.0;
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$category = (string)($entry['category'] ?? '');
			if ($category === '') {
				continue;
			}

			$hours = (float)($entry['hours'] ?? 0);
			$cap = $this->guard->pasReistijdCapToe(category: $category, hours: $hours);
			$total += $cap['countedHours'];
		}

		$this->logger->info(
			'UrenTallyService: YTD tally complete',
			['totalHours' => $total, 'calculatedOn' => $now]
		);

		return [
			'currentHours' => $total,
			'calculatedOn' => $now,
		];

	}//end tallyYearToDate()
}//end class
