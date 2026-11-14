<?php

/**
 * Characterisation tests for the IntegralCostPrice records produced by
 * IntegralCostPriceLockService::lock() and IntegralCostPriceCalculator::compose().
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
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\IntegralCostPriceCalculator;
use OCA\Shillinq\Service\IntegralCostPriceLockService;
use PHPUnit\Framework\TestCase;

/**
 * Pins every key, value and key order of the locked and composed records
 * (the timestamps only by format), the margin edge cases and the lock's
 * refusal messages.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class IntegralCostPriceRecordTest extends TestCase {

	/**
	 * Assert an ATOM timestamp and drop it from the record.
	 *
	 * @param array<string,mixed> $record The record.
	 * @param string              $key    The timestamp key.
	 *
	 * @return array<string,mixed> The record without the timestamp.
	 */
	private function withoutTimestamp(array $record, string $key): array {
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $record[$key]);
		unset($record[$key]);
		return $record;

	}//end withoutTimestamp()

	/**
	 * The full locked record: non-array records skipped, overhead buckets
	 * summed and rounded, a zero cost price falling back to base 1.
	 *
	 * @return void
	 */
	public function testLockedRecord(): void {
		$record = (new IntegralCostPriceLockService())->lock(
			[
				'commercialActivityId' => 'ca-1',
				'administrationId' => 'adm-1',
				'fiscalYear' => '2025',
				'signedBy' => 'acc-1',
				'soldUnits' => 4,
				'unitLabel' => 'uur',
				'appliedRate' => 2.5,
				'voorlopigRecords' => [
					'x',
					[
						'componenten' => [
							'directPayrollCost' => 1.004,
							'directMaterials' => 2,
							'indirecteOverhead' => ['staf' => 0.333, 'ict' => 1],
							'profitMarkup' => 0.1,
						],
						'totalCost' => 3.333,
					],
					[
						'componenten' => ['directDepreciations' => 4, 'capitalCost' => 0.5, 'indirecteOverhead' => ['staf' => 0.333]],
						'totalCost' => 6.667,
					],
				],
			]
		);

		self::assertSame($record['calculatedOn'], $record['finalSignedAt']);
		$record = $this->withoutTimestamp($this->withoutTimestamp($record, 'calculatedOn'), 'finalSignedAt');
		self::assertSame(
			[
				'commercialActivityId' => 'ca-1',
				'period' => '2025-YTD',
				'status' => 'final',
				'componenten' => [
					'directPayrollCost' => 1.0,
					'directMaterials' => 2.0,
					'directDepreciations' => 4.0,
					'indirecteOverhead' => ['staf' => 0.67, 'ict' => 1.0],
					'capitalCost' => 0.5,
					'profitMarkup' => 0.1,
				],
				'totalCost' => 10.0,
				'soldUnits' => 4.0,
				'unitLabel' => 'uur',
				'costPricePerUnit' => 2.5,
				'appliedRate' => 2.5,
				'marge' => 0.0,
				'margePercentage' => 0.0,
				'compliant' => true,
				'finalSignedBy' => 'acc-1',
				'administrationId' => 'adm-1',
			],
			$record
		);

	}//end testLockedRecord()

	/**
	 * Without sold units or applied rate the price fields are null and the
	 * record is not compliant; a zero cost price divides the margin by 1.
	 *
	 * @return void
	 */
	public function testLockedRecordWithoutPrice(): void {
		$svc = new IntegralCostPriceLockService();
		$base = ['commercialActivityId' => 'ca-1', 'administrationId' => 'adm-1', 'fiscalYear' => '2025', 'signedBy' => 'a'];

		$record = $svc->lock($base + ['voorlopigRecords' => [['totalCost' => 5]]]);
		foreach (['soldUnits', 'unitLabel', 'costPricePerUnit', 'appliedRate', 'marge', 'margePercentage'] as $key) {
			self::assertNull($record[$key], $key);
		}

		self::assertFalse($record['compliant']);

		$free = $svc->lock($base + ['voorlopigRecords' => [['totalCost' => 0]], 'soldUnits' => 2, 'appliedRate' => 3]);
		self::assertSame(0.0, $free['costPricePerUnit']);
		self::assertSame(3.0, $free['marge']);
		self::assertSame(300.0, $free['margePercentage']);

	}//end testLockedRecordWithoutPrice()

	/**
	 * Each refusal carries its own message, in order.
	 *
	 * @return void
	 */
	public function testLockRefusals(): void {
		$cases = [
			'Cannot lock without any voorlopig IKP records' => [],
			'Year-end lock requires an accountant user-id (signedBy)' => ['voorlopigRecords' => [[]], 'signedBy' => ' '],
			'Invalid fiscalYear (expected YYYY): 25' => ['voorlopigRecords' => [[]], 'signedBy' => 'a', 'fiscalYear' => '25'],
		];
		foreach ($cases as $message => $input) {
			try {
				(new IntegralCostPriceLockService())->lock($input);
				self::fail('expected refusal: ' . $message);
			} catch (InvalidArgumentException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

	}//end testLockRefusals()

	/**
	 * The full composed record, with the legacy markup key, revenue-based
	 * compliance and default status.
	 *
	 * @return void
	 */
	public function testComposedRecord(): void {
		$record = (new IntegralCostPriceCalculator())->compose(
			[
				'commercialActivityId' => 'ca-2',
				'period' => '2026-01',
				'administrationId' => 'adm-2',
				'costCentre' => 'K',
				'costObject' => 'D',
				'glLines' => [
					['costCentre' => 'K', 'costObject' => 'D', 'accountKind' => 'payrollCost', 'amount' => 100],
					['costCentre' => 'K', 'costObject' => 'D', 'accountKind' => 'materialen', 'amount' => 50],
				],
				'corporateOverheadCents' => 10000,
				'overheadRule' => ['ratios' => [['bucket' => 'staf', 'ratio' => 0.5]]],
				'investedBookValueCents' => 20000,
				'waccRate' => 0.1,
				'winstopslagRate' => 0.1,
				'omzetEur' => 300,
			]
		);

		$record = $this->withoutTimestamp($record, 'calculatedOn');
		self::assertSame(
			[
				'commercialActivityId' => 'ca-2',
				'period' => '2026-01',
				'status' => 'voorlopig',
				'componenten' => [
					'directPayrollCost' => 100.0,
					'directMaterials' => 50.0,
					'directDepreciations' => 0.0,
					'indirecteOverhead' => ['staf' => 50.0],
					'capitalCost' => 20.0,
					'profitMarkup' => 22.0,
				],
				'totalCost' => 242.0,
				'soldUnits' => null,
				'unitLabel' => null,
				'costPricePerUnit' => null,
				'appliedRate' => null,
				'marge' => null,
				'margePercentage' => null,
				'compliant' => true,
				'administrationId' => 'adm-2',
			],
			$record
		);

	}//end testComposedRecord()
}//end class
