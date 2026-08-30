<?php

/**
 * Usage Rating Calculator
 *
 * Pure rating engine that turns a metered quantity plus a UsageRatePlan into a
 * cost in integer cents (REQ-UMB-002). It is the "rate" step of the
 * meter-reading -> rated-line -> invoice path that extends shillinq's billing
 * beyond flat recurring/retainer engagements to consumption-based (usage)
 * billing.
 *
 * Two rating methods are supported:
 *  - `flat`      — quantity x unitPriceCents.
 *  - `graduated` — the standard graduated-tier model: the quantity is split
 *                  across ascending tiers and each slice is priced at that
 *                  tier's unitPriceCents (the final tier with a null `upTo`
 *                  catches all remaining volume).
 *
 * Deterministic and dependency-free — no OpenRegister, no clock — so it is unit
 * testable in isolation and reusable by BillingModelEngine::calculateUsage().
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
 * @spec openspec/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure meter-quantity -> cost rating (REQ-UMB-002).
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class UsageRatingCalculator {
	/**
	 * Default VAT rate applied when a plan declares none (Dutch standard rate).
	 *
	 * @var float
	 */
	public const DEFAULT_VAT_RATE = 21.0;

	/**
	 * Rate a metered quantity against a UsageRatePlan.
	 *
	 * @param float $quantity The metered quantity (units of plan.unit).
	 * @param array<string,mixed> $plan The UsageRatePlan record (ratingMethod, unitPriceCents, tiers, vatRate).
	 *
	 * @return array{costAmountCents:int,billableUnits:float,unitPriceCents:?int,vatRate:float,ratingMethod:string}
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 */
	public function rate(float $quantity, array $plan): array {
		$method = (string)($plan['ratingMethod'] ?? '');
		$tiers = $this->normaliseTiers(tiers: ($plan['tiers'] ?? []));
		$vatRate = (float)($plan['vatRate'] ?? self::DEFAULT_VAT_RATE);

		// A plan with tiers defaults to graduated rating; otherwise flat.
		if ($method === '') {
			$method = 'flat';
			if ($tiers !== []) {
				$method = 'graduated';
			}
		}

		$quantity = max(0.0, $quantity);

		if ($method === 'graduated' && $tiers !== []) {
			return [
				'costAmountCents' => $this->rateGraduated(quantity: $quantity, tiers: $tiers),
				'billableUnits' => $quantity,
				'unitPriceCents' => null,
				'vatRate' => $vatRate,
				'ratingMethod' => 'graduated',
			];
		}

		$unitPriceCents = (int)round((float)($plan['unitPriceCents'] ?? 0));
		$costCents = (int)round($quantity * $unitPriceCents);

		return [
			'costAmountCents' => $costCents,
			'billableUnits' => $quantity,
			'unitPriceCents' => $unitPriceCents,
			'vatRate' => $vatRate,
			'ratingMethod' => 'flat',
		];

	}//end rate()

	/**
	 * Graduated-tier rating: split the quantity across ascending tiers, pricing
	 * each slice at its tier's unitPriceCents. The final tier's `upTo` is null
	 * (unbounded) and catches all remaining volume.
	 *
	 * @param float $quantity The metered quantity.
	 * @param array<int,array{upTo:?float,unitPriceCents:int}> $tiers Ascending, normalised tiers.
	 *
	 * @return int Cost in integer cents.
	 */
	private function rateGraduated(float $quantity, array $tiers): int {
		$remaining = $quantity;
		$prevCap = 0.0;
		$costCents = 0.0;

		foreach ($tiers as $tier) {
			if ($remaining <= 0.0) {
				break;
			}

			$upTo = $tier['upTo'];
			if ($upTo === null) {
				$sliceUnits = $remaining;
			} else {
				$sliceUnits = min($remaining, max(0.0, ($upTo - $prevCap)));
				$prevCap = $upTo;
			}

			$costCents += ($sliceUnits * $tier['unitPriceCents']);
			$remaining -= $sliceUnits;
		}//end foreach

		return (int)round($costCents);
	}//end rateGraduated()

	/**
	 * Normalise and sort a tiers array by ascending `upTo` (nulls last).
	 *
	 * @param mixed $tiers Raw tiers from the plan record.
	 *
	 * @return array<int,array{upTo:?float,unitPriceCents:int}>
	 */
	private function normaliseTiers(mixed $tiers): array {
		if (is_array($tiers) === false || $tiers === []) {
			return [];
		}

		$normalised = [];
		foreach ($tiers as $tier) {
			if (is_array($tier) === false) {
				continue;
			}

			$upToRaw = ($tier['upTo'] ?? null);
			if ($upToRaw === null || $upToRaw === '') {
				$upTo = null;
			} else {
				$upTo = (float)$upToRaw;
			}

			$normalised[] = [
				'upTo' => $upTo,
				'unitPriceCents' => (int)round((float)($tier['unitPriceCents'] ?? 0)),
			];
		}//end foreach

		usort(
			$normalised,
			static function (array $a, array $b): int {
				if ($a['upTo'] === null) {
					return 1;
				}

				if ($b['upTo'] === null) {
					return -1;
				}

				return ($a['upTo'] <=> $b['upTo']);
			}
		);

		return $normalised;
	}//end normaliseTiers()
}//end class
