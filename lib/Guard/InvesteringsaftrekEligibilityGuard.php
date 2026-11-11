<?php

/**
 * Investeringsaftrek eligibility + cumulation guard — ADR-031 exception path.
 *
 * Classifies a capitalised asset against the four schemes (KIA/EIA/MIA/Vamil)
 * at capitalisation (REQ-INV-001), enforces the per-asset minimum thresholds
 * (REQ-INV-003) and applies the legal cumulation matrix (REQ-INV-004,
 * Wet IB 2001 art. 3.42 lid 7). Pure logic, no persistence — the calculations
 * engine passes the asset record and the resolved Energielijst/Milieulijst
 * match; this guard returns the per-scheme eligibility plus any cumulation
 * violation. A boekhouder override is applied on top of this baseline (D2).
 *
 * Exception documented in
 * openspec/changes/bookkeeping-investeringsaftrek/design.md §D2/D3.
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

use Psr\Log\LoggerInterface;

/**
 * ADR-031 eligibility + cumulation guard for investeringsaftrek.
 *
 * All monetary amounts are integer EUR cents.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 */
class InvesteringsaftrekEligibilityGuard {
	/**
	 * Per-asset minimum for EIA/MIA/Vamil (EUR 2.500), in EUR cents.
	 *
	 * @var int
	 */
	public const MIN_EIA_MIA_VAMIL = 250000;

	/**
	 * Per-asset minimum for KIA (EUR 450), in EUR cents.
	 *
	 * @var int
	 */
	public const MIN_KIA = 45000;

	/**
	 * Per-asset/per-year combined KIA plafond (EUR 392.230), in EUR cents.
	 *
	 * @var int
	 */
	public const MAX_KIA = 39223000;

	/**
	 * The list each list scheme matches against, as named in its rationale.
	 *
	 * @var array<string, string>
	 */
	private const LIST_LABELS = [
		'EIA' => 'Energielijst-code',
		'MIA' => 'Milieulijst-code',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for computation diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Classify an asset against the four schemes (REQ-INV-001 / REQ-INV-003).
	 *
	 * Inputs are the asset's grondslag plus whether a list code matched and,
	 * for Vamil, whether the matched MilieulijstCode carries vamilToegestaan.
	 * The result is the baseline machine classification BEFORE any boekhouder
	 * override; each flag carries a human-readable rationale.
	 *
	 * @param AssetEligibilityCheck $check The asset's facts.
	 *
	 * @return array{kia: bool, eia: bool, mia: bool, vamil: bool, rationale: array<string,string>}
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function classify(AssetEligibilityCheck $check): array {
		$flags = [
			'kia' => $check->qualifiesForKia(),
			'eia' => $check->qualifiesForEia(),
			'mia' => $check->qualifiesForMia(),
			'vamil' => $check->qualifiesForVamil(),
		];

		$this->logger->debug(
			'InvesteringsaftrekEligibilityGuard: classify',
			['acquisitionValue' => $check->acquisitionValue] + $flags
		);

		return $flags + ['rationale' => $this->rationale(check: $check)];

	}//end classify()

	/**
	 * Why each scheme does or does not apply.
	 *
	 * @param AssetEligibilityCheck $check The asset's facts.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	private function rationale(AssetEligibilityCheck $check): array {
		return [
			'kia' => $this->kiaRationale(check: $check),
			'eia' => $this->listRationale(label: 'EIA', check: $check),
			'mia' => $this->listRationale(label: 'MIA', check: $check),
			'vamil' => $this->vamilRationale(check: $check),
		];
	}//end rationale()

	/**
	 * Build the KIA rationale string (REQ-INV-001 / REQ-INV-003).
	 *
	 * @param AssetEligibilityCheck $check The asset's facts.
	 *
	 * @return string
	 */
	private function kiaRationale(AssetEligibilityCheck $check): string {
		if ($check->kiaExcluded === true) {
			return 'KIA: uitgesloten bedrijfsmiddel (art. 3.45 Wet IB 2001)';
		}

		if ($check->acquisitionValue < self::MIN_KIA) {
			return 'KIA: onder per-asset minimum van EUR 450';
		}

		if ($check->acquisitionValue > self::MAX_KIA) {
			return 'KIA: boven plafond van EUR 392.230';
		}

		return 'KIA: in aanmerking (binnen drempel en plafond)';
	}//end kiaRationale()

	/**
	 * Build the Vamil rationale string (REQ-INV-001 / REQ-INV-003).
	 *
	 * @param AssetEligibilityCheck $check The asset's facts.
	 *
	 * @return string
	 */
	private function vamilRationale(AssetEligibilityCheck $check): string {
		if ($check->environmentListHit === false) {
			return 'Vamil: geen Milieulijst-code match';
		}

		if ($check->vamilPermitted === false) {
			return 'Vamil: code staat geen willekeurige afschrijving toe';
		}

		if ($check->meetsListMinimum() === false) {
			return 'Vamil: onder minimum van EUR 2.500';
		}

		return 'Vamil: in aanmerking (Milieulijst + vamilToegestaan)';
	}//end vamilRationale()

	/**
	 * Validate a requested set of schemes against the cumulation matrix (REQ-INV-004).
	 *
	 * Forbidden on the same asset: EIA + MIA (art. 3.42 lid 7) and EIA + Vamil
	 * (Vamil only on the Milieulijst). All other combinations are allowed,
	 * including KIA stacked with any single non-conflicting scheme and the
	 * KIA + MIA + Vamil triple stack.
	 *
	 * @param array<int,string> $schemes Requested scheme codes (KIA/EIA/MIA/Vamil).
	 *
	 * @return array{allowed: bool, violation: ?string}
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function validateCumulation(array $schemes): array {
		$violation = $this->cumulationViolation(set: array_flip($schemes));
		if ($violation === null) {
			return ['allowed' => true, 'violation' => null];
		}

		return [
			'allowed' => false,
			'violation' => $violation,
		];
	}//end validateCumulation()

	/**
	 * The first cumulation rule the schemes break, or null.
	 *
	 * @param array<string, int> $set The schemes, as keys.
	 *
	 * @return string|null
	 */
	private function cumulationViolation(array $set): string|null {
		if (isset($set['EIA']) === true && isset($set['MIA']) === true) {
			return 'Art. 3.42 lid 7: EIA en MIA kunnen niet op hetzelfde bedrijfsmiddel worden gecombineerd. Kies een van beide.';
		}

		if (isset($set['EIA']) === true && isset($set['Vamil']) === true) {
			return 'Vamil is alleen toegestaan op Milieulijst-bedrijfsmiddelen, niet in combinatie met EIA.';
		}

		return null;
	}//end cumulationViolation()

	/**
	 * Whether the KIA jaartotaal has reached the 80% plafond-warning threshold (REQ-INV-003).
	 *
	 * @param int $kiaJaartotaal Running KIA investment total in EUR cents.
	 *
	 * @return bool True when at or above 80% of the EUR 392.230 plafond.
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function isApproachingKiaPlafond(int $kiaJaartotaal): bool {
		return $kiaJaartotaal >= (int)round(self::MAX_KIA * 0.8);
	}//end isApproachingKiaPlafond()

	/**
	 * Build a threshold rationale string for EIA/MIA.
	 *
	 * @param string                $label Scheme label (EIA or MIA).
	 * @param AssetEligibilityCheck $check The asset's facts.
	 *
	 * @return string
	 */
	private function listRationale(string $label, AssetEligibilityCheck $check): string {
		$hitLabel = self::LIST_LABELS[$label];
		if ($check->listHit(scheme: $label) === false) {
			return $label . ': geen ' . $hitLabel . ' match';
		}

		if ($check->meetsListMinimum() === false) {
			return $label . ': onder minimum van EUR 2.500';
		}

		return $label . ': in aanmerking (' . $hitLabel . ' match, bedrag >= EUR 2.500)';
	}//end listRationale()
}//end class
