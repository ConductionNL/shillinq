<?php

/**
 * IB auto-bijtelling computation — ADR-031 exception-path guard.
 *
 * Invoked by the x-openregister-calculations engine (via the `guard:` clause on
 * IBBijtellingAuto.bijtellingBedrag) when the tiered EV-staffel bijtelling
 * cannot be expressed natively in the declarative calculation syntax. Single
 * deterministic method, no persistence, pure arithmetic per ADR-031 §"PHP
 * guards remain a legitimate seam". Computes the private-use addition per
 * art. 3.20 Wet IB 2001: a flat percentage for regular cars, and a two-tier
 * staffel for zero-emission vehicles (REQ-IB-013).
 *
 * Exception documented in
 * openspec/changes/bookkeeping-ib-aangifte-zzp/design.md §D1 (per-vehicle entity).
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
 * @spec openspec/specs/bookkeeping-ib-aangifte-zzp/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard for the private-use car bijtelling (art. 3.20 Wet IB).
 *
 * Called by the calculation engine with the catalogue value and the staffel
 * parameters (read from IBTaxParameterYear, never hard-coded). Returns the
 * gross bijtelling amount; the netto bijtelling (minus eigen bijdrage) is
 * derived declaratively.
 *
 * @spec openspec/specs/bookkeeping-ib-aangifte-zzp/spec.md
 */
class IbBijtellingGuard {
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
	 * Compute the gross bijtelling per art. 3.20 Wet IB 2001.
	 *
	 * Regular cars apply the tier-2 percentage to the whole catalogue value.
	 * Zero-emission cars apply the tier-1 percentage up to the tier-1 cap and
	 * the tier-2 percentage to the excess. All percentages/caps are supplied by
	 * the caller from IBTaxParameterYear so no tariff is hard-coded (REQ-IB-013).
	 *
	 * @param float $listValue Catalogue value when new (>= 0).
	 * @param string $category Bijtelling category (REGULIER_22PCT / EV_TIERED_17_22PCT / ZERO_EMISSION / OTHER).
	 * @param float $staffel1Pct Tier-1 (EV) percentage, e.g. 0.17.
	 * @param float $staffel1Cap Tier-1 cap, e.g. 30000.0.
	 * @param float $staffel2Pct Tier-2 / regular percentage, e.g. 0.22.
	 *
	 * @return float The gross bijtelling amount, rounded to cents (>= 0).
	 *
	 * @spec openspec/specs/bookkeeping-ib-aangifte-zzp/spec.md
	 */
	public function computeBijtelling(
		float $listValue,
		string $category,
		float $staffel1Pct,
		float $staffel1Cap,
		float $staffel2Pct,
	): float {
		$this->logger->debug(
			'IbBijtellingGuard: computeBijtelling',
			['cataloguswaarde' => $listValue, 'category' => $category]
		);

		if ($listValue <= 0.0) {
			return 0.0;
		}

		if ($category === 'EV_TIERED_17_22PCT') {
			$tier1Base = min($listValue, $staffel1Cap);
			$tier2Base = max(($listValue - $staffel1Cap), 0.0);
			$benefitInKind = (($tier1Base * $staffel1Pct) + ($tier2Base * $staffel2Pct));
			return round($benefitInKind, 2);
		}

		return round(($listValue * $staffel2Pct), 2);
	}//end computeBijtelling()
}//end class
