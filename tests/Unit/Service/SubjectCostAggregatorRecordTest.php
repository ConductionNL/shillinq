<?php

/**
 * Pins SubjectCostAggregator::aggregate()'s full result (every key, in
 * order) for a mixed set of hour rows, and the one log line a withheld cost
 * writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SubjectCostAggregator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * aggregate() before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\SubjectCostAggregator
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SubjectCostAggregatorRecordTest extends TestCase {

	private const ROWS = [
		['personId' => ' ann ', 'hours' => '1.125'],
		'not a row',
		['personId' => 'bob', 'hours' => 2],
		['personId' => 'ann', 'hours' => 0.5],
		['hours' => 3],
		['personId' => 'cid', 'hours' => 'lots'],
		['personId' => 'dee', 'hours' => 1.335],
	];

	/**
	 * A withheld cost: the full result and its log line.
	 *
	 * @return void
	 */
	public function testWithheldCostResultAndLog(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(
			'SubjectCostAggregator: cost withheld — some hours have no resolvable rate',
			['unpricedPersonIds' => ['bob', '(unattributed)', 'dee']]
		);

		$result = (new SubjectCostAggregator($logger))->aggregate(self::ROWS, ['ann' => 6000, 'bob' => -1, 'dee' => '9000']);

		self::assertSame(
			[
				'hours' => 7.96,
				'costCents' => null,
				'complete' => false,
				'currency' => 'EUR',
				'perPerson' => [
					['personId' => 'ann', 'hours' => 1.63, 'centsPerHour' => 6000, 'costCents' => 9750],
					['personId' => 'bob', 'hours' => 2.0, 'centsPerHour' => null, 'costCents' => null],
					['personId' => '(unattributed)', 'hours' => 3.0, 'centsPerHour' => null, 'costCents' => null],
					['personId' => 'dee', 'hours' => 1.34, 'centsPerHour' => null, 'costCents' => null],
				],
				'unpricedPersonIds' => ['bob', '(unattributed)', 'dee'],
			],
			$result
		);

	}//end testWithheldCostResultAndLog()

	/**
	 * A complete cost: the full result and no log line.
	 *
	 * @return void
	 */
	public function testCompleteCostResult(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('info');

		$result = (new SubjectCostAggregator($logger))->aggregate(
			[['personId' => 'ann', 'hours' => 1.005], ['personId' => 'bob', 'hours' => '2'], ['personId' => 'ann', 'hours' => 0]],
			['ann' => 3333, 'bob' => 0]
		);

		self::assertSame(
			[
				'hours' => 3.01,
				'costCents' => 3350,
				'complete' => true,
				'currency' => 'EUR',
				'perPerson' => [
					['personId' => 'ann', 'hours' => 1.01, 'centsPerHour' => 3333, 'costCents' => 3350],
					['personId' => 'bob', 'hours' => 2.0, 'centsPerHour' => 0, 'costCents' => 0],
				],
				'unpricedPersonIds' => [],
			],
			$result
		);

	}//end testCompleteCostResult()
}//end class
