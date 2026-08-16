<?php

/**
 * Unit tests for the DepreciationCalculator (REQ-FA-003, REQ-FA-004).
 *
 * Exercises the linear / degressive / none depreciation paths, the
 * parallel commercial vs fiscal book-value streams (Wet IB / Wet VPB
 * divergence), the residual-value floor, and the monthsElapsed helper.
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
 * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DepreciationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * DepreciationCalculator unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DepreciationCalculatorTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var DepreciationCalculator
	 */
	private DepreciationCalculator $calc;

	/**
	 * Bootstrap the subject under test before each scenario.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new DepreciationCalculator();

	}//end setUp()

	/**
	 * REQ-FA-003 worked example: cost 2400, residual 0, life 48m,
	 * linear method → monthlyDepreciation 50 and currentBookValue 2100
	 * after 6 months.
	 *
	 * @return void
	 */
	public function testLinearMonthlyAndBookValueWorkedExample(): void {
		$asset = $this->linearAsset();

		self::assertSame(50.0, $this->calc->monthlyDepreciation($asset, '2026-07-01'));
		self::assertSame(2100.0, $this->calc->currentBookValue($asset, '2026-07-01'));

	}//end testLinearMonthlyAndBookValueWorkedExample()

	/**
	 * Linear bookValue floors at residualValue once fully depreciated.
	 *
	 * @return void
	 */
	public function testLinearBookValueFloorsAtResidualValue(): void {
		$asset = $this->linearAsset();
		$asset['residualValue'] = 200.0;

		// Past end of useful life — should clamp to residualValue.
		self::assertSame(200.0, $this->calc->currentBookValue($asset, '2032-01-01'));

	}//end testLinearBookValueFloorsAtResidualValue()

	/**
	 * REQ-FA-003 scenario: depreciationMethod=none → monthlyDepreciation=0
	 * and currentBookValue==acquisitionCost indefinitely.
	 *
	 * @return void
	 */
	public function testNoneMethodKeepsBookValueAtCost(): void {
		$asset = $this->linearAsset();
		$asset['depreciationMethod'] = 'none';

		self::assertSame(0.0, $this->calc->monthlyDepreciation($asset, '2030-01-01'));
		self::assertSame(2400.0, $this->calc->currentBookValue($asset, '2030-01-01'));

	}//end testNoneMethodKeepsBookValueAtCost()

	/**
	 * REQ-FA-004 worked example: cost 10000, useful 60m,
	 * commercialRate 0.20 and fiscalRate 0.33 → after 12 months
	 * commercialBookValue 8000, fiscalBookValue 6700.
	 *
	 * @return void
	 */
	public function testParallelCommercialAndFiscalStreamsDiverge(): void {
		$asset = [
			'acquisitionCost' => 10000.0,
			'residualValue' => 0.0,
			'usefulLifeMonths' => 60,
			'depreciationMethod' => 'linear',
			'acquisitionDate' => '2026-01-01',
			'commercialRate' => 0.20,
			'fiscalRate' => 0.33,
		];

		self::assertSame(8000.0, $this->calc->commercialBookValue($asset, '2027-01-01'));
		self::assertSame(6700.0, $this->calc->fiscalBookValue($asset, '2027-01-01'));

	}//end testParallelCommercialAndFiscalStreamsDiverge()

	/**
	 * When commercialRate / fiscalRate are unset, the stream falls back
	 * to currentBookValue so single-stream assets keep working.
	 *
	 * @return void
	 */
	public function testStreamFallsBackToCurrentBookValueWhenRateMissing(): void {
		$asset = $this->linearAsset();
		// No commercial/fiscal rate set.
		$expected = $this->calc->currentBookValue($asset, '2026-07-01');

		self::assertSame($expected, $this->calc->commercialBookValue($asset, '2026-07-01'));
		self::assertSame($expected, $this->calc->fiscalBookValue($asset, '2026-07-01'));

	}//end testStreamFallsBackToCurrentBookValueWhenRateMissing()

	/**
	 * Degressive monthly depreciation follows
	 * `currentBookValue * rate / 12` at the reference date.
	 *
	 * @return void
	 */
	public function testDegressiveMonthlyUsesCurrentBookValue(): void {
		$asset = [
			'acquisitionCost' => 10000.0,
			'residualValue' => 0.0,
			'usefulLifeMonths' => 60,
			'depreciationMethod' => 'degressive',
			'degressiveRate' => 0.20,
			'acquisitionDate' => '2026-01-01',
		];

		// At acquisition the book value equals cost, so monthly = 10000 * 0.20 / 12 = 166.67.
		$monthly = $this->calc->monthlyDepreciation($asset, '2026-01-01');
		self::assertSame(166.67, $monthly);

	}//end testDegressiveMonthlyUsesCurrentBookValue()

	/**
	 * `monthsElapsed` counts whole calendar months between two ISO dates.
	 *
	 * @return void
	 */
	public function testMonthsElapsedCountsWholeCalendarMonths(): void {
		self::assertSame(0, $this->calc->monthsElapsed('2026-01-15', '2026-01-31'));
		self::assertSame(0, $this->calc->monthsElapsed('2026-01-15', '2026-02-14'));
		self::assertSame(1, $this->calc->monthsElapsed('2026-01-15', '2026-02-15'));
		self::assertSame(6, $this->calc->monthsElapsed('2026-01-01', '2026-07-01'));
		self::assertSame(12, $this->calc->monthsElapsed('2026-01-01', '2027-01-01'));
		// Reference date earlier than acquisition → 0.
		self::assertSame(0, $this->calc->monthsElapsed('2026-06-01', '2026-05-01'));

	}//end testMonthsElapsedCountsWholeCalendarMonths()

	/**
	 * `derivedFields` returns all four values keyed by the calc field names.
	 *
	 * @return void
	 */
	public function testDerivedFieldsReturnsAllFour(): void {
		$asset = $this->linearAsset();

		$fields = $this->calc->derivedFields($asset, '2026-07-01');

		self::assertArrayHasKey('monthlyDepreciation', $fields);
		self::assertArrayHasKey('currentBookValue', $fields);
		self::assertArrayHasKey('commercialBookValue', $fields);
		self::assertArrayHasKey('fiscalBookValue', $fields);
		self::assertSame(50.0, $fields['monthlyDepreciation']);
		self::assertSame(2100.0, $fields['currentBookValue']);

	}//end testDerivedFieldsReturnsAllFour()

	/**
	 * Integer-cent arithmetic: float drift on cost 0.1 + 0.2 stays put.
	 *
	 * @return void
	 */
	public function testToCentsAndFromCentsAreSymmetric(): void {
		self::assertSame(123, $this->calc->toCents(1.23));
		self::assertSame(1.23, $this->calc->fromCents(123));
		self::assertSame(0, $this->calc->toCents(null));

	}//end testToCentsAndFromCentsAreSymmetric()

	/**
	 * Linear monthly depreciation with usefulLife=0 short-circuits to 0.
	 *
	 * @return void
	 */
	public function testLinearMonthlyReturnsZeroWhenUsefulLifeMissing(): void {
		$asset = $this->linearAsset();
		$asset['usefulLifeMonths'] = 0;

		self::assertSame(0.0, $this->calc->monthlyDepreciation($asset, '2026-07-01'));

	}//end testLinearMonthlyReturnsZeroWhenUsefulLifeMissing()

	/**
	 * Reusable linear-asset fixture (spec REQ-FA-003 worked example).
	 *
	 * @return array<string,mixed>
	 */
	private function linearAsset(): array {
		return [
			'assetNumber' => 'FA-0001',
			'name' => 'Dell XPS-15',
			'acquisitionCost' => 2400.0,
			'residualValue' => 0.0,
			'usefulLifeMonths' => 48,
			'depreciationMethod' => 'linear',
			'acquisitionDate' => '2026-01-01',
			'assetAccountNumber' => '0220',
			'accumulatedDepAccountNumber' => '0225',
			'depreciationExpenseAccountNumber' => '4500',
			'currency' => 'EUR',
			'administrationId' => 'adm-1',
		];

	}//end linearAsset()
}//end class
