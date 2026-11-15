<?php

/**
 * The months and loaded context one BudgetProjectionService request projects over.
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The requested months and the reader context shared by the steps of one
 * projection request. Built and used only by BudgetProjectionService.
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
 */
final class BudgetProjectionRun {
	/**
	 * Constructor.
	 *
	 * @param list<string>        $months  The `YYYY-MM` months, in chronological order.
	 * @param array<string,mixed> $context The {@see BudgetProjectionReader::loadContext()} bundle.
	 */
	public function __construct(
		public readonly array $months,
		public readonly array $context,
	) {

	}//end __construct()
}//end class
