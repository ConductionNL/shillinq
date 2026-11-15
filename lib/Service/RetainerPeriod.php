<?php

/**
 * The month a retainer is billed for and the hours logged in it.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-billing-model-logic-tm-fixed-fee-milestone-retainer-mixed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Retainer month (ISO YYYY-MM) and the total hours logged within it.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-billing-model-logic-tm-fixed-fee-milestone-retainer-mixed
 */
final class RetainerPeriod {

	/**
	 * Construct the period.
	 *
	 * @param string $month ISO YYYY-MM.
	 * @param float $hoursLogged Total hours within the retainer period.
	 */
	public function __construct(
		public readonly string $month,
		public readonly float $hoursLogged,
	) {

	}//end __construct()
}//end class
