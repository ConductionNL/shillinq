<?php

/**
 * Unit tests for IbFiscalAdjustmentGuard.
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
 * @spec openspec/changes/bookkeeping-ib-aangifte-zzp/specs/bookkeeping-ib-aangifte-zzp/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\IbFiscalAdjustmentGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the representatiebeperking correction (art. 3.15 Wet IB, REQ-IB-001).
 */
class IbFiscalAdjustmentGuardTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var IbFiscalAdjustmentGuard
	 */
	private IbFiscalAdjustmentGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new IbFiscalAdjustmentGuard(logger: $this->logger);
	}//end setUp()

	/**
	 * Costs above 5% of profit yield a positive add-back of the excess.
	 *
	 * EUR 4.200 representation costs on EUR 47.820 profit: cap = 5% × 47.820 =
	 * 2.391; correction = 4.200 − 2.391 = 1.809.
	 *
	 * @return void
	 */
	public function testExcessOverCapIsCorrected(): void {
		$correction = $this->guard->representatieDrempel(4200.0, 47820.0);
		self::assertSame(1809.0, $correction);
	}//end testExcessOverCapIsCorrected()

	/**
	 * Costs within the 5% cap yield no correction.
	 *
	 * @return void
	 */
	public function testCostsWithinCapAreNotCorrected(): void {
		// 5% of 47.820 = 2.391; 1.500 is within the cap.
		self::assertSame(0.0, $this->guard->representatieDrempel(1500.0, 47820.0));
	}//end testCostsWithinCapAreNotCorrected()

	/**
	 * Zero or negative profit base disables the correction (no division by a
	 * meaningless base).
	 *
	 * @return void
	 */
	public function testZeroOrNegativeProfitYieldsNoCorrection(): void {
		self::assertSame(0.0, $this->guard->representatieDrempel(4200.0, 0.0));
		self::assertSame(0.0, $this->guard->representatieDrempel(4200.0, -1000.0));
	}//end testZeroOrNegativeProfitYieldsNoCorrection()

	/**
	 * Zero costs yield no correction.
	 *
	 * @return void
	 */
	public function testZeroCostsYieldNoCorrection(): void {
		self::assertSame(0.0, $this->guard->representatieDrempel(0.0, 47820.0));
	}//end testZeroCostsYieldNoCorrection()

	/**
	 * The correction is rounded to cents.
	 *
	 * @return void
	 */
	public function testCorrectionIsRoundedToCents(): void {
		// 5% of 1234.55 = 61.7275; 200 − 61.7275 = 138.2725 → 138.27.
		self::assertSame(138.27, $this->guard->representatieDrempel(200.0, 1234.55));
	}//end testCorrectionIsRoundedToCents()
}//end class
