<?php

/**
 * Golden tests for DBAScoreCalculator: subtotals, boosters, clamping and the debug line.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\DBAScoreCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every score the calculator produces for a spread of intakes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DBAScoreCalculatorGoldenTest extends TestCase {

	/**
	 * Recorded debug lines.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $logs = [];

	/**
	 * Build the calculator.
	 *
	 * @return DBAScoreCalculator
	 */
	private function calculator(): DBAScoreCalculator {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->logs[] = [$message, $context];
			}
		);

		return new DBAScoreCalculator($logger);
	}//end calculator()

	/**
	 * An empty intake scores only the missing-flag Deliveroo points.
	 *
	 * @return void
	 */
	public function testEmptyIntake(): void {
		self::assertSame(expected: 20, actual: $this->calculator()->computeTotal([]));
		self::assertSame(
			expected: [[
				'DBAScoreCalculator: computed total',
				['gezag' => 0, 'arbeid' => 0, 'financieel' => 0, 'deliveroo' => 20, 'booster' => 0, 'total' => 20],
			]],
			actual: $this->logs
		);
	}//end testEmptyIntake()

	/**
	 * Both boosters, object-shaped blocks, and the clamp at 100.
	 *
	 * @return void
	 */
	public function testBoostersAndUpperClamp(): void {
		$deliveroo = new \stdClass();
		$deliveroo->excluding = true;
		$deliveroo->durationRelationship = 'MEER_DAN_2_YEAR';
		$deliveroo->actualExecutionFollowsContract = false;
		$intake = [
			'authorityRelationship' => ['kwaInstructiesScore' => 10, 'kwaResultFreeScore' => '9', 'participatesInTeamMeetingScore' => 5],
			'personalLabour' => ['replaceableScore' => 4, 'replacementActualScore' => 10],
			'financialRisk' => ['invoiceFrequencyScore' => 7, 'paymentRiskScore' => 7, 'investmentOwnResourcesScore' => 7],
			'deliverooCriteria' => $deliveroo,
		];
		$calculator = $this->calculator();
		self::assertSame(expected: 20, actual: $calculator->subtotalGezag($intake));
		self::assertSame(expected: 14, actual: $calculator->subtotalArbeid($intake));
		self::assertSame(expected: 20, actual: $calculator->subtotalFinancieel($intake));
		self::assertSame(expected: 40, actual: $calculator->subtotalDeliveroo($intake));
		self::assertSame(expected: 100, actual: $calculator->computeTotal($intake));
		$actual = $this->logs[0][1];
		$expected = ['gezag' => 20, 'arbeid' => 14, 'financieel' => 20, 'deliveroo' => 40, 'booster' => 10, 'total' => 100];
		self::assertSame(expected: $expected, actual: $actual);
	}//end testBoostersAndUpperClamp()

	/**
	 * Booster boundaries, each Deliveroo flag, and negative pillar scores clamped to 0.
	 *
	 * @return void
	 */
	public function testBoundariesAndLowerClamp(): void {
		$calculator = $this->calculator();
		$cases = [
			[
				[
					'deliverooCriteria' => [
						'excluding' => true,
						'durationRelationship' => '6_TO_12_MONTHS',
						'natureActivitiesSpecialist' => true,
						'ownCustomers' => true,
						'ownReclame' => true,
						'modelAgreementPresent' => true,
					],
				],
				14,
				0,
			],
			[
				[
					'deliverooCriteria' => [
						'excluding' => 1,
						'durationRelationship' => '1_TO_2_YEAR',
						'natureActivitiesSpecialist' => 1,
						'ownCustomers' => 1,
						'ownReclame' => 1,
						'modelAgreementPresent' => 1,
					],
				],
				21,
				5,
			],
			[['personalLabour' => ['replaceableScore' => 5, 'replacementActualScore' => 10]], 35, 0],
			[['personalLabour' => ['replaceableScore' => -20, 'replacementActualScore' => 9]], 20, 0],
			[['authorityRelationship' => ['kwaInstructiesScore' => -50], 'deliverooCriteria' => ['durationRelationship' => 'UNKNOWN']], 20, 0],
			[['deliverooCriteria' => 'not-an-array', 'personalLabour' => 7], 20, 0],
		];
		foreach ($cases as $i => [$intake, $total, $booster]) {
			$this->logs = [];
			self::assertSame(expected: $total, actual: $calculator->computeTotal($intake), message: 'case ' . $i);
			self::assertSame(expected: $booster, actual: $this->logs[0][1]['booster'], message: 'case ' . $i);
		}

		self::assertSame(expected: 0, actual: $calculator->subtotalArbeid(['personalLabour' => ['replaceableScore' => -3]]));
		$flagsOnly = [
			'deliverooCriteria' => [
				'excluding' => false,
				'natureActivitiesSpecialist' => true,
				'ownCustomers' => true,
				'ownReclame' => true,
				'modelAgreementPresent' => true,
				'durationRelationship' => 'MINDER_DAN_3_MONTHS',
			],
		];
		self::assertSame(expected: 2, actual: $calculator->subtotalDeliveroo($flagsOnly));
	}//end testBoundariesAndLowerClamp()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
