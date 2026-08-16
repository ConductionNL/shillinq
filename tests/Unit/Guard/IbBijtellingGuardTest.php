<?php

/**
 * Unit tests for IbBijtellingGuard.
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

use OCA\Shillinq\Guard\IbBijtellingGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the auto-bijtelling computation (art. 3.20 Wet IB, REQ-IB-013).
 */
class IbBijtellingGuardTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var IbBijtellingGuard
	 */
	private IbBijtellingGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new IbBijtellingGuard(logger: $this->logger);
	}//end setUp()

	/**
	 * Regular car: 22% flat on the catalogue value (spec scenario).
	 *
	 * @return void
	 */
	public function testRegularCarFlatRate(): void {
		$benefitInKind = $this->guard->computeBijtelling(38000.0, 'REGULAR_22_PCT', 0.17, 30000.0, 0.22);
		self::assertSame(8360.0, $benefitInKind);
	}//end testRegularCarFlatRate()

	/**
	 * EV staffel: 17% on the first 30.000, 22% on the excess (spec scenario).
	 *
	 * EUR 52.000: (0.17 × 30.000) + (0.22 × 22.000) = 5.100 + 4.840 = 9.940.
	 *
	 * @return void
	 */
	public function testEvTieredStaffel(): void {
		$benefitInKind = $this->guard->computeBijtelling(52000.0, 'EV_TIERED_17_22PCT', 0.17, 30000.0, 0.22);
		self::assertSame(9940.0, $benefitInKind);
	}//end testEvTieredStaffel()

	/**
	 * EV under the tier-1 cap pays the tier-1 percentage on the whole value.
	 *
	 * @return void
	 */
	public function testEvUnderCapUsesTier1Only(): void {
		// 25.000 < 30.000 cap: 0.17 × 25.000 = 4.250.
		$benefitInKind = $this->guard->computeBijtelling(25000.0, 'EV_TIERED_17_22PCT', 0.17, 30000.0, 0.22);
		self::assertSame(4250.0, $benefitInKind);
	}//end testEvUnderCapUsesTier1Only()

	/**
	 * Zero catalogue value yields zero bijtelling.
	 *
	 * @return void
	 */
	public function testZeroCatalogueValueYieldsZero(): void {
		self::assertSame(0.0, $this->guard->computeBijtelling(0.0, 'REGULAR_22_PCT', 0.17, 30000.0, 0.22));
	}//end testZeroCatalogueValueYieldsZero()

	/**
	 * A custom regular percentage (e.g. an updated year) is honoured, proving
	 * no rate is hard-coded.
	 *
	 * @return void
	 */
	public function testRateIsNotHardCoded(): void {
		// Hypothetical 25% rate from a future IBTaxParameterYear.
		$benefitInKind = $this->guard->computeBijtelling(40000.0, 'REGULAR_22_PCT', 0.17, 30000.0, 0.25);
		self::assertSame(10000.0, $benefitInKind);
	}//end testRateIsNotHardCoded()
}//end class
