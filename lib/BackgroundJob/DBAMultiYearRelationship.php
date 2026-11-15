<?php

/**
 * Shillinq DBA multi-year relationship.
 *
 * Whether a customer relationship is long-running and carries a large revenue share.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Enums\DBAConstants;

/**
 * Describes a customer relationship that lasted at least the multi-year
 * threshold and carries at least the revenue-share threshold.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DBAMultiYearRelationship {
	/**
	 * Construct for one portfolio.
	 *
	 * @param DateTimeImmutable $now The moment of measurement.
	 * @param int $total The portfolio's total revenue (positive).
	 */
	public function __construct(
		private readonly DateTimeImmutable $now,
		private readonly int $total,
	) {
	}//end __construct()

	/**
	 * The relationship, or null when it is too short or too small.
	 *
	 * @param string $customerId The customer.
	 * @param array{start: DateTimeImmutable, revenue: int} $row Its oldest start and revenue.
	 *
	 * @return array{customerId: string, startDate: string, durationYears: float, revenueShare: float}|null
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function describe(string $customerId, array $row): ?array {
		$durationYears = (float)($row['start']->diff($this->now)->days / 365.0);
		$share = $this->share(revenue: $row['revenue']);
		if ($durationYears < DBAConstants::LANGJARIG_DREMPEL_JAREN || $share < DBAConstants::LANGJARIG_DREMPEL_OMZET) {
			return null;
		}

		return [
			'customerId' => $customerId,
			'startDate' => $row['start']->format('Y-m-d'),
			'durationYears' => round($durationYears, 2),
			'revenueShare' => round($share, 4),
		];

	}//end describe()

	/**
	 * The customer's share of the total revenue; 0 without revenue.
	 *
	 * @param int $revenue The customer's revenue.
	 *
	 * @return float
	 */
	private function share(int $revenue): float {
		if ($revenue > 0) {
			return ($revenue / $this->total);
		}

		return 0.0;

	}//end share()
}//end class
