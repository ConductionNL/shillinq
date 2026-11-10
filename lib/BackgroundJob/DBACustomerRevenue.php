<?php

/**
 * Shillinq DBA customer revenue.
 *
 * Realised revenue per customer over a set of assignments.
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

/**
 * Realised revenue per customer and in total, counting only assignments with
 * a customer and a positive revenue.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DBACustomerRevenue {
	/**
	 * Revenue per customer id.
	 *
	 * @var array<string|int,int>
	 */
	public readonly array $perCustomer;

	/**
	 * Total revenue.
	 *
	 * @var integer
	 */
	public readonly int $total;

	/**
	 * Sum the assignments' revenue per customer.
	 *
	 * @param array<int,array<string,mixed>> $assignments The assignments.
	 */
	public function __construct(array $assignments) {
		$perCustomer = [];
		$total = 0;
		foreach ($assignments as $assignment) {
			$customerId = (string)($assignment['customerId'] ?? '');
			$amount = (int)($assignment['realisedRevenue'] ?? 0);
			if ($customerId === '' || $amount <= 0) {
				continue;
			}

			$perCustomer[$customerId] = ($perCustomer[$customerId] ?? 0) + $amount;
			$total += $amount;
		}

		$this->perCustomer = $perCustomer;
		$this->total = $total;

	}//end __construct()

	/**
	 * Whether there is no revenue to divide.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function isEmpty(): bool {
		return $this->total <= 0 || count($this->perCustomer) === 0;

	}//end isEmpty()

	/**
	 * The customer with the most revenue (the first one on a tie) and that revenue.
	 *
	 * @return array{0: string|null, 1: int}
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function largest(): array {
		$largestCustomer = null;
		$largestAmount = 0;
		foreach ($this->perCustomer as $customerId => $amount) {
			if ($amount > $largestAmount) {
				$largestAmount = $amount;
				$largestCustomer = (string)$customerId;
			}
		}

		return [$largestCustomer, $largestAmount];

	}//end largest()

	/**
	 * How many customers carry at least 99% of the revenue.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function exclusiveCount(): int {
		$count = 0;
		foreach ($this->perCustomer as $amount) {
			if (((float)$amount / (float)$this->total) >= 0.99) {
				$count++;
			}
		}

		return $count;

	}//end exclusiveCount()
}//end class
