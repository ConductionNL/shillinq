<?php

/**
 * One journal entry of depreciation schedule lines.
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
 * The lines (with their assets) posted as one journal entry, a date in its
 * period and its description. Built and used only by
 * DepreciationScheduleService.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
final class DepreciationPosting {
	/**
	 * Constructor.
	 *
	 * @param string                                                               $administrationId The administration.
	 * @param string                                                               $period           A date in the period (the entry date is its end).
	 * @param list<array{figures: array<string,mixed>, line: array<string,mixed>}> $entries          The lines with their assets.
	 * @param string                                                               $description      The journal entry's description.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $period,
		public readonly array $entries,
		public readonly string $description,
	) {

	}//end __construct()
}//end class
