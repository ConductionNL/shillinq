<?php

/**
 * Asset Eligibility Check
 *
 * The facts about one business asset that decide its investment-deduction
 * eligibility (KIA / EIA / MIA / Vamil).
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

/**
 * One asset's eligibility facts and the scheme thresholds applied to them.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 */
final class AssetEligibilityCheck {
	/**
	 * Acquisition value in EUR cents.
	 *
	 * @var integer
	 */
	public readonly int $acquisitionValue;

	/**
	 * Whether an EnergielijstCode matched (EIA).
	 *
	 * @var boolean
	 */
	public readonly bool $energyListHit;

	/**
	 * Whether a MilieulijstCode matched (MIA/Vamil).
	 *
	 * @var boolean
	 */
	public readonly bool $environmentListHit;

	/**
	 * Whether the matched MilieulijstCode permits Vamil.
	 *
	 * @var boolean
	 */
	public readonly bool $vamilPermitted;

	/**
	 * Whether the asset is excluded under art. 3.45 (woonhuis, grond, etc.).
	 *
	 * @var boolean
	 */
	public readonly bool $kiaExcluded;

	/**
	 * State the asset's facts.
	 *
	 * @param int  $acquisitionValue   Acquisition value in EUR cents.
	 * @param bool $energyListHit      Whether an EnergielijstCode matched (EIA).
	 * @param bool $environmentListHit Whether a MilieulijstCode matched (MIA/Vamil).
	 * @param bool $vamilPermitted     Whether the matched MilieulijstCode permits Vamil.
	 * @param bool $kiaExcluded        Whether the asset is excluded under art. 3.45.
	 */
	public function __construct(
		int $acquisitionValue,
		bool $energyListHit,
		bool $environmentListHit,
		bool $vamilPermitted,
		bool $kiaExcluded,
	) {
		$this->acquisitionValue = $acquisitionValue;
		$this->energyListHit = $energyListHit;
		$this->environmentListHit = $environmentListHit;
		$this->vamilPermitted = $vamilPermitted;
		$this->kiaExcluded = $kiaExcluded;

	}//end __construct()

	/**
	 * KIA: between EUR 450 and EUR 392.230 per asset, not art. 3.45-excluded.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function qualifiesForKia(): bool {
		return ($this->kiaExcluded === false
			&& $this->acquisitionValue >= InvesteringsaftrekEligibilityGuard::MIN_KIA
			&& $this->acquisitionValue <= InvesteringsaftrekEligibilityGuard::MAX_KIA);
	}//end qualifiesForKia()

	/**
	 * EIA: Energielijst match + EUR 2.5k minimum.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function qualifiesForEia(): bool {
		return ($this->energyListHit === true && $this->meetsListMinimum() === true);
	}//end qualifiesForEia()

	/**
	 * MIA: Milieulijst match + EUR 2.5k minimum.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function qualifiesForMia(): bool {
		return ($this->environmentListHit === true && $this->meetsListMinimum() === true);
	}//end qualifiesForMia()

	/**
	 * Vamil: Milieulijst match + vamilToegestaan + EUR 2.5k minimum.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function qualifiesForVamil(): bool {
		return ($this->environmentListHit === true
			&& $this->vamilPermitted === true
			&& $this->meetsListMinimum() === true);
	}//end qualifiesForVamil()

	/**
	 * Whether the list a scheme reads matched: the Energielijst for EIA, the Milieulijst otherwise.
	 *
	 * @param string $scheme EIA or MIA.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function listHit(string $scheme): bool {
		if ($scheme === 'EIA') {
			return $this->energyListHit;
		}

		return $this->environmentListHit;
	}//end listHit()

	/**
	 * Whether the value reaches the EUR 2.500 minimum of the list schemes.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function meetsListMinimum(): bool {
		return $this->acquisitionValue >= InvesteringsaftrekEligibilityGuard::MIN_EIA_MIA_VAMIL;
	}//end meetsListMinimum()
}//end class
