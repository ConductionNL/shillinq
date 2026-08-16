<?php

/**
 * Carry Forward Loss Service
 *
 * Pure-logic helper for asset-specific loss carry-forward (REQ-IBA-005,
 * REQ-IBA-007, Wet Vpb art. 12be). Innovation losses are asset-specific: a loss
 * from asset A can only offset future profit on asset A. The offset order is
 * strict — the open carry-forward loss is recovered FIRST at the full statutory
 * tariff (NOT reduced by the nexusbreuk), and only the residual profit is taxed
 * at the innovatiebox tariff (0.09) x nexus. This deliberately gives a loss
 * offset a higher effective benefit than the 9% innovatiebox rate.
 *
 * No OpenRegister dependency so the arithmetic is unit-testable in isolation;
 * CarryForwardLossAggregation (the OR-backed caller) walks the open-loss queue
 * per asset and persists the verrekend_boekjaar entries.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free loss carry-forward arithmetic helper (REQ-IBA-005, REQ-IBA-007).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-007
 */
class CarryForwardLossService {
	/**
	 * Innovatiebox tariff applied to the residual profit (REQ-IBA-010).
	 *
	 * @var float
	 */
	public const INNOVATIEBOX_TARIFF = 0.09;

	/**
	 * Full statutory rate at which a carry-forward loss is recovered (2024).
	 *
	 * @var float
	 */
	public const FULL_TARIFF = 0.258;

	/**
	 * Offset an open loss against current-year profit (REQ-IBA-007).
	 *
	 * The open loss is recovered first at the full tariff; the residual profit
	 * is taxed at the innovatiebox tariff x nexus. Returns the split plus the
	 * total benefit and the new open balance for the loss.
	 *
	 * @param float $openLoss Open carry-forward loss balance (positive euros).
	 * @param float $currentYearProfit Current-year qualifying profit after nexus (positive euros).
	 * @param float $nexusBreak Applied nexusbreuk for the residual profit (0..1).
	 * @param float $fullTariff Full statutory tariff for the loss offset (default 0.258).
	 *
	 * @return array{
	 *   lossOffset: float,
	 *   lossOffsetAtFullRate: float,
	 *   residualProfit: float,
	 *   residualProfitAt9Pct: float,
	 *   totalBenefit: float,
	 *   balanceAfter: float,
	 *   status: string
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-007
	 */
	public function offsetLossAgainstProfit(
		float $openLoss,
		float $currentYearProfit,
		float $nexusBreak = 1.0,
		float $fullTariff = self::FULL_TARIFF,
	): array {
		$openLoss = max(0.0, $openLoss);
		$profit = max(0.0, $currentYearProfit);
		$nexus = max(0.0, min($nexusBreak, 1.0));

		// The loss is recovered up to the available profit.
		$lossOffset = min($openLoss, $profit);
		$residual = ($profit - $lossOffset);

		// Loss recovered at the FULL tariff; residual at innovatiebox x nexus.
		$benefitFull = ($lossOffset * $fullTariff);
		$benefit9 = (($residual * $nexus) * self::INNOVATIEBOX_TARIFF);

		$balanceAfter = ($openLoss - $lossOffset);
		$status = 'open';
		if ($balanceAfter <= 0.0) {
			$status = 'volledig_verrekend';
			$balanceAfter = 0.0;
		}

		return [
			'lossOffset' => round($lossOffset, 2),
			'lossOffsetAtFullRate' => round($benefitFull, 2),
			'residualProfit' => round($residual, 2),
			'residualProfitAt9Pct' => round($benefit9, 2),
			'totalBenefit' => round(($benefitFull + $benefit9), 2),
			'balanceAfter' => round($balanceAfter, 2),
			'status' => $status,
		];

	}//end offsetLossAgainstProfit()
}//end class
