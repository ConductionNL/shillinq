<?php

/**
 * Unit tests for UrencriteriumGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\UrencriteriumGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for UrencriteriumGuard.
 *
 * Covers REQ-ZZP-002/003:
 * - Excluded-category hours do not count toward the 1225-urencriterium.
 * - Other-person and other-year hours are filtered out.
 * - Reproducible: same input → identical output.
 */
class UrencriteriumGuardTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var UrencriteriumGuard
	 */
	private UrencriteriumGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new UrencriteriumGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * REQ-ZZP-002: 200 excluded (sick) hours are not counted; 1200 qualify.
	 *
	 * @return void
	 */
	public function testExcludedHoursDoNotQualify(): void {
		$records = [
			['personId' => 'p1', 'workDate' => '2026-03-01', 'hours' => 1200, 'category' => 'billable'],
			['personId' => 'p1', 'workDate' => '2026-04-01', 'hours' => 200, 'category' => 'excluded', 'excludedReason' => 'sick'],
		];

		$result = $this->guard->currentYtdHours($records, 'p1', 2026);

		self::assertSame(1200.0, $result);
		self::assertLessThan(1225.0, $result);

	}//end testExcludedHoursDoNotQualify()

	/**
	 * REQ-ZZP-003: hours for another person or another year are excluded.
	 *
	 * @return void
	 */
	public function testFiltersByPersonAndYear(): void {
		$records = [
			['personId' => 'p1', 'workDate' => '2026-03-01', 'hours' => 800, 'category' => 'billable'],
			['personId' => 'p2', 'workDate' => '2026-03-01', 'hours' => 999, 'category' => 'billable'],
			['personId' => 'p1', 'workDate' => '2025-12-31', 'hours' => 999, 'category' => 'billable'],
			['personId' => 'p1', 'workDate' => '2026-09-01', 'hours' => 500, 'category' => 'project'],
		];

		$result = $this->guard->currentYtdHours($records, 'p1', 2026);

		self::assertSame(1300.0, $result);

	}//end testFiltersByPersonAndYear()

	/**
	 * Empty input yields zero.
	 *
	 * @return void
	 */
	public function testEmptyInputIsZero(): void {
		self::assertSame(0.0, $this->guard->currentYtdHours([], 'p1', 2026));

	}//end testEmptyInputIsZero()
}//end class
