<?php

/**
 * Nexus Calculation Service
 *
 * Pure-logic helper for the OECD BEPS Action 5 modified-nexus approach
 * (REQ-IBA-002, Wet Vpb art. 12bc). Computes the nexusbreuk per qualifying
 * asset per fiscal year:
 *
 *   teller_voor_uplift = eigen_rd_kosten + rd_kosten_uitbesteed_derden
 *   teller_na_uplift   = min(1.3 x teller_voor_uplift, totale_rd_kosten)
 *   nexusbreuk         = min(teller_na_uplift / totale_rd_kosten, 1.0)
 *
 * Related-party R&D (rd_kosten_uitbesteed_verbonden) only enlarges the noemer,
 * never the teller, which is what reduces the ratio when R&D is outsourced to a
 * verbonden lichaam. The uplift factor (1.3) and the 100% cap are baked in per
 * the OECD standard; this class holds no OpenRegister dependency so the
 * arithmetic is unit-testable in isolation.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free modified-nexus arithmetic helper (REQ-IBA-002).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-002
 */
class NexusCalculationService {
	/**
	 * The fixed OECD BEPS Action 5 uplift factor.
	 *
	 * @var float
	 */
	public const UPLIFT_FACTOR = 1.3;

	/**
	 * Compute the modified-nexus break for one asset/year (REQ-IBA-002).
	 *
	 * Returns the full breakdown so callers (and the audit trail) can record
	 * every intermediate value. All monetary inputs are plain euros; the ratios
	 * are rounded to four decimals to keep the result stable.
	 *
	 * @param float $eigenRdCost Internal R&D cost (loon + material).
	 * @param float $uitbesteedDerden R&D outsourced to unrelated third parties (teller).
	 * @param float $uitbesteedVerbonden R&D outsourced to related entities (noemer only).
	 * @param float $upliftFactor Uplift factor, default 1.3 per OECD.
	 *
	 * @return array{
	 *   numeratorBeforeUplift: float,
	 *   numeratorAfterUplift: float,
	 *   denominator: float,
	 *   nexusFractionUncapped: float,
	 *   nexusFractionApplied: float,
	 *   totalRdCost: float
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-002
	 */
	public function calculateNexusBreak(
		float $eigenRdCost,
		float $uitbesteedDerden,
		float $uitbesteedVerbonden,
		float $upliftFactor = self::UPLIFT_FACTOR,
	): array {
		$eigen = max(0.0, $eigenRdCost);
		$derden = max(0.0, $uitbesteedDerden);
		$verbonden = max(0.0, $uitbesteedVerbonden);

		$numeratorBeforeUplift = ($eigen + $derden);
		$total = ($eigen + $derden + $verbonden);

		// Teller_na_uplift never exceeds the total R&D cost (OECD cap on the teller).
		$numeratorAfterUplift = min(($upliftFactor * $numeratorBeforeUplift), $total);

		$ongecapt = 0.0;
		if ($total > 0.0) {
			$ongecapt = ($numeratorAfterUplift / $total);
		}

		// The nexusbreuk itself is capped at 100% (1.0).
		$applied = min($ongecapt, 1.0);

		return [
			'numeratorBeforeUplift' => round($numeratorBeforeUplift, 2),
			'numeratorAfterUplift' => round($numeratorAfterUplift, 2),
			'denominator' => round($total, 2),
			'totalRdCost' => round($total, 2),
			'nexusFractionUncapped' => round($ongecapt, 4),
			'nexusFractionApplied' => round($applied, 4),
		];

	}//end calculateNexusBreak()

	/**
	 * Recompute a nexus break for a scenario without persisting it (REQ-IBA-009).
	 *
	 * Convenience wrapper that returns only the applied nexusbreuk so the
	 * scenario endpoint can compare a base position against a what-if change.
	 *
	 * @param float $eigenRdCost Internal R&D cost.
	 * @param float $uitbesteedDerden Unrelated third-party R&D.
	 * @param float $uitbesteedVerbonden Related-party R&D.
	 *
	 * @return float The applied (capped) nexusbreuk for the scenario.
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-002
	 */
	public function scenarioNexusBreak(
		float $eigenRdCost,
		float $uitbesteedDerden,
		float $uitbesteedVerbonden,
	): float {
		$result = $this->calculateNexusBreak(
			eigenRdCost: $eigenRdCost,
			uitbesteedDerden: $uitbesteedDerden,
			uitbesteedVerbonden: $uitbesteedVerbonden
		);

		return (float)$result['nexusFractionApplied'];
	}//end scenarioNexusBreak()
}//end class
