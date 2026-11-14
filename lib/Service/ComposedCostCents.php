<?php

/**
 * Composed cost cents
 *
 * The integer-cent cost components of one composed IntegralCostPrice record:
 * the direct costs, the indirect overhead per bucket, the capital cost and
 * the profit markup, with the base before markup and the total they add up
 * to.
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
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Cost components of a composed IntegralCostPrice record, in cents.
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
 */
final class ComposedCostCents {
	/**
	 * Direct payroll cost.
	 *
	 * @var int
	 */
	public int $payrollCost = 0;

	/**
	 * Direct materials.
	 *
	 * @var int
	 */
	public int $materials = 0;

	/**
	 * Direct depreciations.
	 *
	 * @var int
	 */
	public int $depreciations = 0;

	/**
	 * Indirect overhead per bucket.
	 *
	 * @var array<string,int>
	 */
	public array $overheadBuckets = [];

	/**
	 * Capital cost (vermogenskosten).
	 *
	 * @var int
	 */
	public int $capitalCost = 0;

	/**
	 * Profit markup.
	 *
	 * @var int
	 */
	public int $profitMarkup = 0;

	/**
	 * Direct costs, overhead and capital cost before the profit markup.
	 *
	 * @return int The base in cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function baseBeforeMarkup(): int {
		$overheadTotalCents = 0;
		foreach ($this->overheadBuckets as $cents) {
			$overheadTotalCents += $cents;
		}

		return ($this->payrollCost + $this->materials + $this->depreciations + $overheadTotalCents + $this->capitalCost);
	}//end baseBeforeMarkup()

	/**
	 * The total cost: the base plus the profit markup.
	 *
	 * @return int The total in cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function total(): int {
		return ($this->baseBeforeMarkup() + $this->profitMarkup);
	}//end total()
}//end class
