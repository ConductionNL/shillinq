<?php

/**
 * The terms a run of depreciation schedule lines is written under.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

/**
 * The asset's figures, the method and total useful life, the reason and the
 * depreciation accumulated before the lines being written. Built and used
 * only by DepreciationScheduleService.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
final class ScheduleTerms {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $figures          The asset's figures.
	 * @param string              $method           The FixedAsset method.
	 * @param int                 $months           The total useful life in months.
	 * @param string              $reason           Why, or empty.
	 * @param int                 $accumulatedCents Depreciation before these lines, in cents.
	 */
	public function __construct(
		public readonly array $figures,
		public readonly string $method,
		public readonly int $months,
		public readonly string $reason,
		public readonly int $accumulatedCents,
	) {

	}//end __construct()
}//end class
