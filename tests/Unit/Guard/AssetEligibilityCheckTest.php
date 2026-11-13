<?php

/**
 * Unit tests for AssetEligibilityCheck.
 *
 * The value object that answers, from one asset's stated facts, which
 * investeringsaftrek schemes it qualifies for: KIA (its acquisition-value
 * band and the art. 3.45 exclusion), EIA/MIA/Vamil (their list hits, the
 * Vamil permission and the shared EUR 2.500 minimum), and the list/minimum
 * helpers those answers are built from.
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
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * @covers \OCA\Shillinq\Guard\AssetEligibilityCheck
 * @uses   \OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\AssetEligibilityCheck;
use OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard as Guard;
use PHPUnit\Framework\TestCase;

final class AssetEligibilityCheckTest extends TestCase {
	/**
	 * Build a check with only the fields a case cares about.
	 *
	 * @param int  $value       Acquisition value in EUR cents.
	 * @param bool $energy      Energielijst hit.
	 * @param bool $environment Milieulijst hit.
	 * @param bool $vamil       Vamil permitted.
	 * @param bool $kiaExcluded art. 3.45 exclusion.
	 *
	 * @return AssetEligibilityCheck
	 */
	private function check(int $value, bool $energy = false, bool $environment = false, bool $vamil = false, bool $kiaExcluded = false): AssetEligibilityCheck {
		return new AssetEligibilityCheck($value, $energy, $environment, $vamil, $kiaExcluded);
	}//end check()

	/**
	 * KIA holds on the inclusive value band when the asset is not excluded.
	 *
	 * @return void
	 */
	public function testKiaBandIsInclusiveAtBothEnds(): void {
		$this->assertTrue($this->check(Guard::MIN_KIA)->qualifiesForKia());
		$this->assertTrue($this->check(Guard::MAX_KIA)->qualifiesForKia());
		$this->assertTrue($this->check((Guard::MIN_KIA + 1))->qualifiesForKia());
	}//end testKiaBandIsInclusiveAtBothEnds()

	/**
	 * KIA fails below the floor, above the ceiling, or when art. 3.45 excludes it.
	 *
	 * @return void
	 */
	public function testKiaFailsOutsideBandOrWhenExcluded(): void {
		$this->assertFalse($this->check((Guard::MIN_KIA - 1))->qualifiesForKia());
		$this->assertFalse($this->check((Guard::MAX_KIA + 1))->qualifiesForKia());
		$this->assertFalse($this->check(Guard::MIN_KIA, kiaExcluded: true)->qualifiesForKia());
	}//end testKiaFailsOutsideBandOrWhenExcluded()

	/**
	 * The list schemes need their list hit AND the EUR 2.500 minimum.
	 *
	 * @return void
	 */
	public function testListSchemesNeedHitAndMinimum(): void {
		$energy = $this->check(Guard::MIN_EIA_MIA_VAMIL, energy: true);
		$this->assertTrue($energy->qualifiesForEia());
		$this->assertFalse($energy->qualifiesForMia());

		$environment = $this->check(Guard::MIN_EIA_MIA_VAMIL, environment: true);
		$this->assertTrue($environment->qualifiesForMia());
		$this->assertFalse($environment->qualifiesForEia());

		$belowMin = $this->check((Guard::MIN_EIA_MIA_VAMIL - 1), energy: true, environment: true);
		$this->assertFalse($belowMin->qualifiesForEia());
		$this->assertFalse($belowMin->qualifiesForMia());
	}//end testListSchemesNeedHitAndMinimum()

	/**
	 * Vamil additionally needs the matched Milieulijst code to permit it.
	 *
	 * @return void
	 */
	public function testVamilNeedsPermissionOnTopOfMia(): void {
		$permitted = $this->check(Guard::MIN_EIA_MIA_VAMIL, environment: true, vamil: true);
		$this->assertTrue($permitted->qualifiesForVamil());

		$notPermitted = $this->check(Guard::MIN_EIA_MIA_VAMIL, environment: true, vamil: false);
		$this->assertFalse($notPermitted->qualifiesForVamil());

		$noHit = $this->check(Guard::MIN_EIA_MIA_VAMIL, environment: false, vamil: true);
		$this->assertFalse($noHit->qualifiesForVamil());
	}//end testVamilNeedsPermissionOnTopOfMia()

	/**
	 * listHit reads the Energielijst for EIA and the Milieulijst otherwise.
	 *
	 * @return void
	 */
	public function testListHitSelectsTheSchemeList(): void {
		$check = $this->check(0, energy: true, environment: false);
		$this->assertTrue($check->listHit('EIA'));
		$this->assertFalse($check->listHit('MIA'));
		$this->assertFalse($check->listHit('anything-not-eia'));

		$other = $this->check(0, energy: false, environment: true);
		$this->assertFalse($other->listHit('EIA'));
		$this->assertTrue($other->listHit('MIA'));
	}//end testListHitSelectsTheSchemeList()

	/**
	 * The list minimum is inclusive at EUR 2.500 and fails one cent below.
	 *
	 * @return void
	 */
	public function testMeetsListMinimumBoundary(): void {
		$this->assertTrue($this->check(Guard::MIN_EIA_MIA_VAMIL)->meetsListMinimum());
		$this->assertFalse($this->check((Guard::MIN_EIA_MIA_VAMIL - 1))->meetsListMinimum());
	}//end testMeetsListMinimumBoundary()
}//end class
