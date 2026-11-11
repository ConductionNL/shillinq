<?php

/**
 * Golden tests for the Wet Markt en Overheid compliance verdict and the transaction split.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WmoComplianceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The verdict and split return the same shapes and amounts in every edge case.
 */
final class WmoComplianceCalculatorGoldenTest extends TestCase {

	/**
	 * Every verdict branch.
	 *
	 * @return void
	 */
	public function testComplianceVerdictGolden(): void {
		$calc = new WmoComplianceCalculator();

		$this->assertSame(['compliant' => false, 'marge' => 0.0, 'margePercentage' => 0.0], $calc->complianceVerdict(appliedRate: null, totalCost: 10.0, costPricePerUnit: 1.0));
		$this->assertSame(['compliant' => true, 'marge' => 2.5, 'margePercentage' => 25.0], $calc->complianceVerdict(appliedRate: 12.5, totalCost: 10.0));
		$this->assertSame(['compliant' => false, 'marge' => -0.34, 'margePercentage' => -34.0], $calc->complianceVerdict(appliedRate: 0.66, totalCost: 10.0, costPricePerUnit: 1.0));
		$this->assertSame(['compliant' => true, 'marge' => 0.0, 'margePercentage' => 0.0], $calc->complianceVerdict(appliedRate: 1.0, totalCost: 99.0, costPricePerUnit: 1.0));
		$this->assertSame(['compliant' => true, 'marge' => 5.0, 'margePercentage' => 0.0], $calc->complianceVerdict(appliedRate: 5.0, totalCost: 0.0));
		$this->assertSame(['compliant' => true, 'marge' => 6.0, 'margePercentage' => 0.0], $calc->complianceVerdict(appliedRate: 1.0, totalCost: -5.0));
		$this->assertSame(['compliant' => false, 'marge' => -0.01, 'margePercentage' => -33.33], $calc->complianceVerdict(appliedRate: 0.02, totalCost: 0.03));

	}//end testComplianceVerdictGolden()

	/**
	 * Splits with a remainder, ties, a negative amount, missing fields and no targets.
	 *
	 * @return void
	 */
	public function testSplitTransactionGolden(): void {
		$calc = new WmoComplianceCalculator();
		$line = static fn (string $object, float $ratio, float $amount, mixed $gl = null, string $dimension = ''): array => [
			'costObject'    => $object,
			'ratio'         => $ratio,
			'amount'        => $amount,
			'generalLedger' => $gl,
			'dimension'     => $dimension,
		];

		$this->assertSame(
			[$line('a', 1 / 3, 33.34, '4000', 'markt'), $line('b', 1 / 3, 33.33), $line('c', 1 / 3, 33.33)],
			$calc->splitTransaction(
				originalAmount: 100.0,
				ruleTargets: [
					5 => ['costObject' => 'a', 'ratio' => 1 / 3, 'generalLedger' => '4000', 'dimension' => 'markt'],
					9 => ['costObject' => 'b', 'ratio' => 1 / 3],
					2 => ['costObject' => 'c', 'ratio' => '0.333333333333333333'],
				]
			)
		);
		$this->assertSame(
			[$line('a', 0.5, -50.0), $line('b', 0.5, -50.01)],
			$calc->splitTransaction(originalAmount: -100.01, ruleTargets: [['costObject' => 'a', 'ratio' => 0.5], ['costObject' => 'b', 'ratio' => 0.5]])
		);
		$this->assertSame(
			[$line('a', 0.1, 1.0), $line('b', 0.7, 9.01), $line('', 0.0, 0.0)],
			$calc->splitTransaction(originalAmount: 10.01, ruleTargets: [['costObject' => 'a', 'ratio' => 0.1], ['costObject' => 'b', 'ratio' => 0.7], []])
		);
		$this->assertSame(
			[$line('a', 0.3, -0.08), $line('b', 0.2, -0.02)],
			$calc->splitTransaction(originalAmount: -0.10, ruleTargets: [['costObject' => 'a', 'ratio' => 0.3], ['costObject' => 'b', 'ratio' => 0.2]])
		);
		$this->assertSame([], $calc->splitTransaction(originalAmount: 10.0, ruleTargets: []));

	}//end testSplitTransactionGolden()
}//end class
