<?php

/**
 * Unit tests for UrencriteriumYearGuard.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-000/003/006/007:
 * - norm-determination (1225 regulier, 800 AO, 525 meewerk)
 * - norm/grondslag consistency on save
 * - grotendeels-criterium >50% threshold
 * - drempel-status classification (BEHAALD/OP_KOERS/RISICO/KRITIEK)
 * - fail-closed on inconsistency
 */
class UrencriteriumYearGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var UrencriteriumYearGuard
	 */
	private UrencriteriumYearGuard $guard;

	/**
	 * Set up the guard with a mocked logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new UrencriteriumYearGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * A regular eenmanszaak profile yields the 1.225 norm (REQ-URC-000).
	 *
	 * @return void
	 */
	public function testRegularProfileYields1225(): void {
		self::assertSame(1225, $this->guard->bepaalDoelNorm(profiel: []));
		self::assertSame(
			'art. 3.6 lid 1 Wet IB 2001',
			$this->guard->bepaalNormGrondslag(purposeNorm: 1225)
		);

	}//end testRegularProfileYields1225()

	/**
	 * An arbeidsongeschikt profile yields the 800 norm citing lid 5 (REQ-URC-006).
	 *
	 * @return void
	 */
	public function testAoProfileYields800Lid5(): void {
		self::assertSame(800, $this->guard->bepaalDoelNorm(profiel: ['arbeidsongeschikt' => true]));
		self::assertStringContainsString('lid 5', $this->guard->bepaalNormGrondslag(purposeNorm: 800));

	}//end testAoProfileYields800Lid5()

	/**
	 * A meewerkende-partner profile yields the 525 norm.
	 *
	 * @return void
	 */
	public function testMeewerkProfileYields525(): void {
		self::assertSame(525, $this->guard->bepaalDoelNorm(profiel: ['meewerkendePartner' => true]));

	}//end testMeewerkProfileYields525()

	/**
	 * Drempel-status is BEHAALD when lopende uren already reach the norm.
	 *
	 * @return void
	 */
	public function testDrempelStatusBehaald(): void {
		self::assertSame('ACHIEVED', $this->guard->bepaalDrempelStatus(currentHours: 1300, prognose: 1300, norm: 1225));

	}//end testDrempelStatusBehaald()

	/**
	 * Drempel-status follows the prognose vs norm relationship (REQ-URC-003).
	 *
	 * @return void
	 */
	public function testDrempelStatusFromPrognose(): void {
		self::assertSame('ON_RATE', $this->guard->bepaalDrempelStatus(currentHours: 600, prognose: 1300, norm: 1225));
		// 1180 is >= 80% of 1225 (980) but < 1225 → RISICO (the Q3 scenario).
		self::assertSame('RISK', $this->guard->bepaalDrempelStatus(currentHours: 916, prognose: 1180, norm: 1225));
		self::assertSame('CRITICAL', $this->guard->bepaalDrempelStatus(currentHours: 400, prognose: 700, norm: 1225));

	}//end testDrempelStatusFromPrognose()

	/**
	 * Grotendeels-criterium is NIET_TOEPASSELIJK without parallel loondienst.
	 *
	 * @return void
	 */
	public function testGrotendeelsNotApplicableWithoutLoondienst(): void {
		self::assertSame(
			'NON_APPLICABLE',
			$this->guard->bepaalGrotendeelsCriterium(enterpriseHours: 1240, employmentHours: 0)
		);

	}//end testGrotendeelsNotApplicableWithoutLoondienst()

	/**
	 * Loondienst exceeding onderneming hours flips the grotendeels-criterium to
	 * NIET_GROTENDEELS_ONDERNEMING (REQ-URC-007 scenario: 1670 loondienst vs 1240).
	 *
	 * @return void
	 */
	public function testGrotendeelsFailsWhenLoondienstDominates(): void {
		self::assertSame(
			'NON_LARGELY_ENTERPRISE',
			$this->guard->bepaalGrotendeelsCriterium(enterpriseHours: 1240, employmentHours: 1670)
		);
		self::assertSame(
			'LARGELY_ENTERPRISE',
			$this->guard->bepaalGrotendeelsCriterium(enterpriseHours: 1800, employmentHours: 600)
		);

	}//end testGrotendeelsFailsWhenLoondienstDominates()

	/**
	 * A consistent year record passes the save precondition.
	 *
	 * @return void
	 */
	public function testValidYearPassesSave(): void {
		$year = [
			'enterpriseId' => 'ond-1',
			'purposeNorm' => 1225,
			'normBasis' => 'art. 3.6 lid 1 Wet IB 2001',
			'currentHours' => 916,
			'forecastYearEnd' => 1180,
			'thresholdStatus' => 'RISK',
			'largelyCriterium' => 'NON_APPLICABLE',
		];
		self::assertTrue($this->guard->validateOnSave(year: $year));

	}//end testValidYearPassesSave()

	/**
	 * An 800 norm not citing lid 5 is rejected (REQ-URC-006 consistency).
	 *
	 * @return void
	 */
	public function testAoNormWithoutLid5Rejected(): void {
		$year = [
			'purposeNorm' => 800,
			'normBasis' => 'art. 3.6 lid 1 Wet IB 2001',
			'thresholdStatus' => 'ON_RATE',
		];
		self::assertFalse($this->guard->validateOnSave(year: $year));

	}//end testAoNormWithoutLid5Rejected()

	/**
	 * An unrecognised norm value is rejected.
	 *
	 * @return void
	 */
	public function testUnrecognisedNormRejected(): void {
		$year = [
			'purposeNorm' => 1000,
			'normBasis' => 'art. 3.6 lid 1 Wet IB 2001',
			'thresholdStatus' => 'ON_RATE',
		];
		self::assertFalse($this->guard->validateOnSave(year: $year));

	}//end testUnrecognisedNormRejected()

	/**
	 * A drempel-status inconsistent with the prognose/norm is rejected.
	 *
	 * @return void
	 */
	public function testInconsistentDrempelStatusRejected(): void {
		$year = [
			'purposeNorm' => 1225,
			'normBasis' => 'art. 3.6 lid 1 Wet IB 2001',
			'currentHours' => 916,
			'forecastYearEnd' => 1180,
			// Should be RISICO, not OP_KOERS.
			'thresholdStatus' => 'ON_RATE',
		];
		self::assertFalse($this->guard->validateOnSave(year: $year));

	}//end testInconsistentDrempelStatusRejected()
}//end class
