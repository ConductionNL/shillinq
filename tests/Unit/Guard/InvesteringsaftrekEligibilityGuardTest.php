<?php

/**
 * Unit tests for InvesteringsaftrekEligibilityGuard.
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
 * @spec openspec/changes/bookkeeping-investeringsaftrek/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InvesteringsaftrekEligibilityGuard.
 *
 * Covers REQ-INV-001/003/004:
 * - KIA range (EUR 450 .. EUR 392.230), art. 3.45 exclusion.
 * - EIA/MIA/Vamil minimum threshold and list-match requirements.
 * - Cumulation matrix (EIA+MIA forbidden, EIA+Vamil forbidden, stacks allowed).
 * - KIA plafond 80% warning.
 */
class InvesteringsaftrekEligibilityGuardTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var InvesteringsaftrekEligibilityGuard
	 */
	private InvesteringsaftrekEligibilityGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new InvesteringsaftrekEligibilityGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * REQ-INV-001: a EUR 50.000 asset with both list hits is eligible for all schemes.
	 *
	 * @return void
	 */
	public function testFullEligibilityWhenAllConditionsMet(): void {
		$result = $this->guard->classify(
			acquisitionValue: 5000000,
			energyListHit: true,
			environmentListHit: true,
			vamilPermitted: true,
			kiaExcluded: false
		);

		self::assertTrue($result['kia']);
		self::assertTrue($result['eia']);
		self::assertTrue($result['mia']);
		self::assertTrue($result['vamil']);

	}//end testFullEligibilityWhenAllConditionsMet()

	/**
	 * REQ-INV-001: art. 3.45-excluded asset is not KIA-eligible.
	 *
	 * @return void
	 */
	public function testKiaExcludedAssetIsNotEligible(): void {
		$result = $this->guard->classify(
			acquisitionValue: 5000000,
			energyListHit: false,
			environmentListHit: false,
			vamilPermitted: false,
			kiaExcluded: true
		);

		self::assertFalse($result['kia']);
		self::assertStringContainsString('art. 3.45', $result['rationale']['kia']);

	}//end testKiaExcludedAssetIsNotEligible()

	/**
	 * REQ-INV-003: below the KIA per-asset minimum (EUR 450) is not eligible.
	 *
	 * @return void
	 */
	public function testKiaBelowMinimumNotEligible(): void {
		$result = $this->guard->classify(
			acquisitionValue: 40000,
			energyListHit: false,
			environmentListHit: false,
			vamilPermitted: false,
			kiaExcluded: false
		);

		self::assertFalse($result['kia']);

	}//end testKiaBelowMinimumNotEligible()

	/**
	 * REQ-INV-003: above the KIA plafond (EUR 392.230) is not eligible.
	 *
	 * @return void
	 */
	public function testKiaAbovePlafondNotEligible(): void {
		$result = $this->guard->classify(
			acquisitionValue: 40000000,
			energyListHit: false,
			environmentListHit: false,
			vamilPermitted: false,
			kiaExcluded: false
		);

		self::assertFalse($result['kia']);

	}//end testKiaAbovePlafondNotEligible()

	/**
	 * REQ-INV-003: EIA/MIA/Vamil require the EUR 2.500 minimum.
	 *
	 * @return void
	 */
	public function testEiaMiaVamilBelowMinimumNotEligible(): void {
		$result = $this->guard->classify(
			acquisitionValue: 200000,
			energyListHit: true,
			environmentListHit: true,
			vamilPermitted: true,
			kiaExcluded: false
		);

		self::assertFalse($result['eia']);
		self::assertFalse($result['mia']);
		self::assertFalse($result['vamil']);

	}//end testEiaMiaVamilBelowMinimumNotEligible()

	/**
	 * REQ-INV-001: Vamil requires the matched code to permit it.
	 *
	 * @return void
	 */
	public function testVamilRequiresVamilToegestaan(): void {
		$result = $this->guard->classify(
			acquisitionValue: 5000000,
			energyListHit: false,
			environmentListHit: true,
			vamilPermitted: false,
			kiaExcluded: false
		);

		self::assertTrue($result['mia']);
		self::assertFalse($result['vamil']);
		self::assertStringContainsString('willekeurige afschrijving', $result['rationale']['vamil']);

	}//end testVamilRequiresVamilToegestaan()

	/**
	 * REQ-INV-004: EIA + MIA on the same asset is forbidden (art. 3.42 lid 7).
	 *
	 * @return void
	 */
	public function testCumulationForbidsEiaAndMia(): void {
		$result = $this->guard->validateCumulation(['EIA', 'MIA']);

		self::assertFalse($result['allowed']);
		self::assertStringContainsString('3.42', (string)$result['violation']);

	}//end testCumulationForbidsEiaAndMia()

	/**
	 * REQ-INV-004: EIA + Vamil is forbidden.
	 *
	 * @return void
	 */
	public function testCumulationForbidsEiaAndVamil(): void {
		$result = $this->guard->validateCumulation(['EIA', 'Vamil']);

		self::assertFalse($result['allowed']);

	}//end testCumulationForbidsEiaAndVamil()

	/**
	 * REQ-INV-004: the KIA + MIA + Vamil triple stack is allowed.
	 *
	 * @return void
	 */
	public function testCumulationAllowsKiaMiaVamilStack(): void {
		$result = $this->guard->validateCumulation(['KIA', 'MIA', 'Vamil']);

		self::assertTrue($result['allowed']);
		self::assertNull($result['violation']);

	}//end testCumulationAllowsKiaMiaVamilStack()

	/**
	 * REQ-INV-004: KIA + EIA is allowed.
	 *
	 * @return void
	 */
	public function testCumulationAllowsKiaAndEia(): void {
		$result = $this->guard->validateCumulation(['KIA', 'EIA']);

		self::assertTrue($result['allowed']);

	}//end testCumulationAllowsKiaAndEia()

	/**
	 * REQ-INV-003: the 80% plafond warning fires at and above EUR 313.784.
	 *
	 * @return void
	 */
	public function testPlafondWarningThreshold(): void {
		// 80% of EUR 392.230 = EUR 313.784,00 = 31378400 cents.
		self::assertFalse($this->guard->isApproachingKiaPlafond(31378399));
		self::assertTrue($this->guard->isApproachingKiaPlafond(31378400));
		self::assertTrue($this->guard->isApproachingKiaPlafond(35000000));

	}//end testPlafondWarningThreshold()
}//end class
