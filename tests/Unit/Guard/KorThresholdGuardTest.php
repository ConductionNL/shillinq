<?php

/**
 * Unit tests for KorThresholdGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\KorThresholdGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KorThresholdGuard.
 *
 * Covers REQ-KOR-004:
 * - YTD revenue sums issued invoices for the administration/year.
 * - Cancelled invoices and credit notes are excluded from the omzetdrempel.
 * - Other-administration and other-year invoices are filtered out.
 */
class KorThresholdGuardTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var KorThresholdGuard
	 */
	private KorThresholdGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new KorThresholdGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * REQ-KOR-004: sums qualifying invoices, excludes cancelled + credit notes.
	 *
	 * @return void
	 */
	public function testExcludesCancelledAndCreditNotes(): void {
		$invoices = [
			['administrationId' => 'a1', 'invoiceDate' => '2026-02-01', 'amount' => 10000, 'status' => 'issued'],
			['administrationId' => 'a1', 'invoiceDate' => '2026-05-01', 'amount' => 8000, 'status' => 'paid'],
			['administrationId' => 'a1', 'invoiceDate' => '2026-06-01', 'amount' => 5000, 'status' => 'cancelled'],
			['administrationId' => 'a1', 'invoiceDate' => '2026-06-15', 'amount' => 2000, 'status' => 'issued', 'documentType' => 'credit-note'],
		];

		$result = $this->guard->currentYtdRevenue($invoices, 'a1', 2026);

		self::assertSame(18000.0, $result);

	}//end testExcludesCancelledAndCreditNotes()

	/**
	 * REQ-KOR-004: filters by administration and year.
	 *
	 * @return void
	 */
	public function testFiltersByAdministrationAndYear(): void {
		$invoices = [
			['administrationId' => 'a1', 'invoiceDate' => '2026-02-01', 'amount' => 12000, 'status' => 'issued'],
			['administrationId' => 'a2', 'invoiceDate' => '2026-02-01', 'amount' => 99999, 'status' => 'issued'],
			['administrationId' => 'a1', 'invoiceDate' => '2025-12-01', 'amount' => 99999, 'status' => 'issued'],
		];

		$result = $this->guard->currentYtdRevenue($invoices, 'a1', 2026);

		self::assertSame(12000.0, $result);

	}//end testFiltersByAdministrationAndYear()

	/**
	 * Empty input yields zero.
	 *
	 * @return void
	 */
	public function testEmptyInputIsZero(): void {
		self::assertSame(0.0, $this->guard->currentYtdRevenue([], 'a1', 2026));

	}//end testEmptyInputIsZero()
}//end class
