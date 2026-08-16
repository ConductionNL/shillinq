<?php

/**
 * Unit tests for CashflowRecurringGuard.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-29
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\CashflowRecurringGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CashflowRecurringGuard::validateOnSave per REQ-CF-005.
 *
 * Covers:
 * - Happy path monthly rent definition is accepted.
 * - Happy path annual CPI-indexed insurance is accepted.
 * - Negative amount is denied.
 * - MAANDELIJKS without a valid dagVanMaand is denied.
 * - JAARLIJKS without a valid maandVanJaar is denied.
 * - geldigTot before geldigVan is denied.
 * - Unparseable geldigVan is denied.
 * - CPI indexing on a non-annual frequency is denied.
 * - Fail-closed on malformed input.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowRecurringGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var CashflowRecurringGuard
	 */
	private CashflowRecurringGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new CashflowRecurringGuard($this->logger);

	}//end setUp()

	/**
	 * A valid monthly rent definition is accepted.
	 *
	 * @return void
	 */
	public function testValidMonthlyRentIsAccepted(): void {
		$recurring = [
			'recurId' => 'rec-huur',
			'label' => 'Huur kantoor',
			'category' => 'RECURRING_RENT',
			'direction' => 'OUT',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'standardAmount' => 850.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2024-09-01',
		];

		self::assertTrue($this->guard->validateOnSave($recurring));

	}//end testValidMonthlyRentIsAccepted()

	/**
	 * A valid annual CPI-indexed insurance definition is accepted.
	 *
	 * @return void
	 */
	public function testValidAnnualCpiInsuranceIsAccepted(): void {
		$recurring = [
			'recurId' => 'rec-bav',
			'label' => 'BAV-verzekering',
			'category' => 'RECURRING_INSURANCE',
			'direction' => 'OUT',
			'frequency' => 'ANNUALLY',
			'monthOfYear' => 7,
			'dagFromMonth' => 1,
			'standardAmount' => 620.0,
			'indexationRule' => 'CPI_PAST_YEAR',
			'validFrom' => '2024-07-01',
		];

		self::assertTrue($this->guard->validateOnSave($recurring));

	}//end testValidAnnualCpiInsuranceIsAccepted()

	/**
	 * A negative amount is denied.
	 *
	 * @return void
	 */
	public function testNegativeAmountIsDenied(): void {
		$recurring = [
			'recurId' => 'rec-neg',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'standardAmount' => -100.0,
			'validFrom' => '2024-09-01',
		];

		self::assertFalse($this->guard->validateOnSave($recurring));

	}//end testNegativeAmountIsDenied()

	/**
	 * A monthly item without a valid day-of-month is denied.
	 *
	 * @return void
	 */
	public function testMonthlyWithoutDayIsDenied(): void {
		$recurring = [
			'recurId' => 'rec-noday',
			'frequency' => 'MONTHLY',
			'standardAmount' => 850.0,
			'validFrom' => '2024-09-01',
		];

		self::assertFalse($this->guard->validateOnSave($recurring));

	}//end testMonthlyWithoutDayIsDenied()

	/**
	 * An annual item without a valid month-of-year is denied.
	 *
	 * @return void
	 */
	public function testAnnualWithoutMonthIsDenied(): void {
		$recurring = [
			'recurId' => 'rec-nomonth',
			'frequency' => 'ANNUALLY',
			'dagFromMonth' => 1,
			'standardAmount' => 620.0,
			'validFrom' => '2024-07-01',
		];

		self::assertFalse($this->guard->validateOnSave($recurring));

	}//end testAnnualWithoutMonthIsDenied()

	/**
	 * geldigTot before geldigVan is denied.
	 *
	 * @return void
	 */
	public function testValidityWindowReversedIsDenied(): void {
		$recurring = [
			'recurId' => 'rec-rev',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'standardAmount' => 850.0,
			'validFrom' => '2026-09-01',
			'validTo' => '2024-09-01',
		];

		self::assertFalse($this->guard->validateOnSave($recurring));

	}//end testValidityWindowReversedIsDenied()

	/**
	 * An unparseable geldigVan is denied.
	 *
	 * @return void
	 */
	public function testUnparseableGeldigVanIsDenied(): void {
		$recurring = [
			'recurId' => 'rec-bad',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'standardAmount' => 850.0,
			'validFrom' => 'not-a-date',
		];

		self::assertFalse($this->guard->validateOnSave($recurring));

	}//end testUnparseableGeldigVanIsDenied()

	/**
	 * CPI indexing on a non-annual frequency is denied.
	 *
	 * @return void
	 */
	public function testCpiOnMonthlyIsDenied(): void {
		$recurring = [
			'recurId' => 'rec-cpi-monthly',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'standardAmount' => 850.0,
			'indexationRule' => 'CPI_PAST_YEAR',
			'validFrom' => '2024-09-01',
		];

		self::assertFalse($this->guard->validateOnSave($recurring));

	}//end testCpiOnMonthlyIsDenied()

	/**
	 * An indefinite validity window (no geldigTot) is accepted.
	 *
	 * @return void
	 */
	public function testIndefiniteWindowIsAccepted(): void {
		$recurring = [
			'recurId' => 'rec-indef',
			'frequency' => 'WEEKLY',
			'standardAmount' => 200.0,
			'validFrom' => '2024-09-01',
		];

		self::assertTrue($this->guard->validateOnSave($recurring));

	}//end testIndefiniteWindowIsAccepted()
}//end class
