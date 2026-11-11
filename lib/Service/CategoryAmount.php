<?php

/**
 * Category Amount
 *
 * An amount in euro cents for one deferred-tax category: a category's summed
 * deferred balance, or a rate-change adjustment on it.
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
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One deferred-tax category's amount.
 *
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 */
final class CategoryAmount {

	/**
	 * Constructor.
	 *
	 * @param string|int $category The category (an int when it was a numeric array key).
	 * @param int        $cents    The amount in euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function __construct(
		public readonly string|int $category,
		public readonly int $cents,
	) {

	}//end __construct()
}//end class
