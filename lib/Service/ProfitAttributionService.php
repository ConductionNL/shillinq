<?php

/**
 * Profit Attribution Service
 *
 * Pure-logic helper for per-asset winsttoerekening (REQ-IBA-003, Wet Vpb
 * art. 12bd). Implements the three statutory methods:
 *
 *  - per_asset_afpelmethode: kwalificerende winst = bruto_opbrengst
 *      - directe_kosten - sum(routinewinsten); the residual is reduced by the
 *      nexusbreuk, then taxed at the innovatiebox tariff (0.09).
 *  - forfaitair_25pct (art. 12bg): min(0.25 x belastbare winst, EUR 25.000),
 *      no nexus, taxed at 0.09; the EUR 25k cap is surfaced for the audit trail.
 *  - cost_plus: intra-group transfer pricing — only the tariff application is
 *      computed here; the arm's-length mark-up is supplied by the caller.
 *
 * The innovatiebox tariff (0.09) and the comparison standard rate (0.258) are
 * baked in per REQ-IBA-010; no OpenRegister dependency so the logic is
 * unit-testable in isolation.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free profit-attribution arithmetic helper (REQ-IBA-003).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-003
 */
class ProfitAttributionService {
	/**
	 * Statutory innovatiebox tariff (Wet Vpb art. 12b 2026, REQ-IBA-010).
	 *
	 * @var float
	 */
	public const INNOVATIEBOX_TARIFF = 0.09;

	/**
	 * Standard corporate-tax rate used only for the savings comparison (2024).
	 *
	 * @var float
	 */
	public const STANDARD_RATE = 0.258;

	/**
	 * Forfaitair percentage of taxable profit (art. 12bg).
	 *
	 * @var float
	 */
	public const FORFAITAIR_PERCENTAGE = 0.25;

	/**
	 * Forfaitair annual cap in euros (art. 12bg).
	 *
	 * @var float
	 */
	public const FORFAITAIR_CAP = 25000.0;

	/**
	 * Compute the qualifying profit + Vpb impact for one asset/year (REQ-IBA-003).
	 *
	 * Dispatches on the chosen method. For the afpelmethode the residual profit
	 * (bruto - direct - routines) is multiplied by the nexusbreuk; for forfaitair
	 * the result is min(25% x profit, EUR 25k) and the nexus is NOT applied; for
	 * cost_plus the supplied qualifying profit is taxed directly.
	 *
	 * @param string $method One of per_asset_afpelmethode|forfaitair_25pct|cost_plus.
	 * @param float $grossRevenue Gross revenue attributable to the asset.
	 * @param float $directeCost Direct costs (material, subcontracting for production).
	 * @param float $routineProfit Sum of arm's-length routine profits (mfg + distrib + marketing).
	 * @param float $nexusBreak Applied nexusbreuk (0..1); used by afpelmethode only.
	 *
	 * @return array{
	 *   method: string,
	 *   qualifyingProfitForNexus: float,
	 *   nexusFractionApplied: float,
	 *   qualifyingProfitAfterNexus: float,
	 *   effectiveRate: float,
	 *   vpbOnInnovationShare: float,
	 *   vpbWithoutInnovationBox: float,
	 *   innovationBoxBenefit: float,
	 *   forfaitairCapApplied: bool
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-003
	 */
	public function calculateKwalificerendeWinst(
		string $method,
		float $grossRevenue,
		float $directeCost = 0.0,
		float $routineProfit = 0.0,
		float $nexusBreak = 1.0,
	): array {
		if ($method === 'flat_rate_25pct') {
			return $this->forfaitair(profit: $grossRevenue);
		}

		// Afpelmethode (default) and cost_plus both work off the residual profit;
		// cost_plus simply passes the supplied qualifying profit with full nexus.
		$forNexus = ($grossRevenue - $directeCost - $routineProfit);
		if ($forNexus < 0.0) {
			$forNexus = 0.0;
		}

		$appliedNexus = max(0.0, min($nexusBreak, 1.0));
		if ($method === 'cost_plus') {
			$appliedNexus = 1.0;
		}

		$afterNexus = ($forNexus * $appliedNexus);

		$vpbInnovatie = ($afterNexus * self::INNOVATIEBOX_TARIFF);
		$vpbZonder = ($forNexus * self::STANDARD_RATE);

		return [
			'method' => $method,
			'qualifyingProfitForNexus' => round($forNexus, 2),
			'nexusFractionApplied' => round($appliedNexus, 4),
			'qualifyingProfitAfterNexus' => round($afterNexus, 2),
			'effectiveRate' => self::INNOVATIEBOX_TARIFF,
			'vpbOnInnovationShare' => round($vpbInnovatie, 2),
			'vpbWithoutInnovationBox' => round($vpbZonder, 2),
			'innovationBoxBenefit' => round(($vpbZonder - $vpbInnovatie), 2),
			'forfaitairCapApplied' => false,
		];

	}//end calculateKwalificerendeWinst()

	/**
	 * Apply the forfaitaire methode (art. 12bg): min(25% x profit, EUR 25k).
	 *
	 * The nexus is deliberately NOT applied (forfaitair elects out of per-asset
	 * valuation, REQ-IBA-003). The cap-applied flag lets the caller emit the
	 * ForfaitairCap.applied audit event when the EUR 25k cap binds.
	 *
	 * @param float $profit Taxable operating profit.
	 *
	 * @return array{
	 *   method: string,
	 *   qualifyingProfitForNexus: float,
	 *   nexusFractionApplied: float,
	 *   qualifyingProfitAfterNexus: float,
	 *   effectiveRate: float,
	 *   vpbOnInnovationShare: float,
	 *   vpbWithoutInnovationBox: float,
	 *   innovationBoxBenefit: float,
	 *   forfaitairCapApplied: bool
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-003
	 */
	private function forfaitair(float $profit): array {
		$profit = max(0.0, $profit);

		$forCap = ($profit * self::FORFAITAIR_PERCENTAGE);
		$kwalif = min($forCap, self::FORFAITAIR_CAP);
		$capApplied = ($forCap > self::FORFAITAIR_CAP);

		$vpbInnovatie = ($kwalif * self::INNOVATIEBOX_TARIFF);
		$vpbZonder = ($kwalif * self::STANDARD_RATE);

		return [
			'method' => 'flat_rate_25pct',
			'qualifyingProfitForNexus' => round($forCap, 2),
			'nexusFractionApplied' => 1.0,
			'qualifyingProfitAfterNexus' => round($kwalif, 2),
			'effectiveRate' => self::INNOVATIEBOX_TARIFF,
			'vpbOnInnovationShare' => round($vpbInnovatie, 2),
			'vpbWithoutInnovationBox' => round($vpbZonder, 2),
			'innovationBoxBenefit' => round(($vpbZonder - $vpbInnovatie), 2),
			'forfaitairCapApplied' => $capApplied,
		];

	}//end forfaitair()
}//end class
