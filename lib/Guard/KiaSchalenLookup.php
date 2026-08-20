<?php

/**
 * KIA tiered-schaal lookup — ADR-031 exception-path guard.
 *
 * KIA (kleinschaligheidsinvesteringsaftrek, Wet IB 2001 art. 3.41) is
 * aggregated at boekjaar level over the running kiaJaartotaal, not per asset.
 * The 2026 schaal is a piecewise function with a flat-amount band and a
 * tapering band (EUR 19.769 minus 7,56% of (jaartotaal - EUR 130.744)) that
 * the declarative x-openregister-calculations engine cannot express natively,
 * so this single-purpose guard resolves the tier and computes both the total
 * KIA-aftrek and the marginal effect of a single asset (REQ-INV-005/006).
 *
 * Exception documented in
 * openspec/changes/bookkeeping-investeringsaftrek/design.md §D5.
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
 * ADR-031 exception guard computing KIA-aftrek over a boekjaar investment total.
 *
 * All monetary amounts are integer EUR cents. The guard is pure (no
 * persistence): callers pass the pre-fetched KIA-tier table (seeded from
 * investeringsaftrek-kia-tiers-2026.json) and the running jaartotaal.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 */
class KiaSchalenLookup {
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
	 * Resolve the KIA tier row whose [vanaf, tot) band contains the jaartotaal.
	 *
	 * @param array<int,array<string,mixed>> $tiers KIA-tier rows (cents bounds).
	 * @param int $jaartotaal Running KIA investment total in EUR cents.
	 *
	 * @return array<string,mixed>|null The matching tier row, or null if none matches.
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function resolveTier(array $tiers, int $jaartotaal): ?array {
		foreach ($tiers as $tier) {
			$from = (int)($tier['from'] ?? 0);
			$tot = $tier['tot'];
			if ($jaartotaal < $from) {
				continue;
			}

			if ($tot === null || $jaartotaal < (int)$tot) {
				return $tier;
			}
		}

		return null;
	}//end resolveTier()

	/**
	 * Compute the total KIA-aftrek for a boekjaar investment total (REQ-INV-006).
	 *
	 * Tier semantics (2026, art. 3.41 Wet IB 2001), all in EUR cents:
	 * - tier with `percentage` >= 0 and no `vastBedrag`: aftrek = percentage% x jaartotaal.
	 * - tier with a `vastBedrag` and no usable `percentage`: aftrek = vastBedrag (flat band).
	 * - tier-4 taper (`percentage` < 0 + `vastBedrag`): aftrek = vastBedrag + percentage% x (jaartotaal - vanaf).
	 * - tier with percentage 0 and vastBedrag 0: no KIA (below drempel / above plafond).
	 *
	 * @param array<int,array<string,mixed>> $tiers KIA-tier rows.
	 * @param int $jaartotaal Running KIA investment total in EUR cents.
	 *
	 * @return int KIA-aftrek in EUR cents (never negative).
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function computeAftrek(array $tiers, int $jaartotaal): int {
		if ($jaartotaal <= 0) {
			return 0;
		}

		$tier = $this->resolveTier(tiers: $tiers, jaartotaal: $jaartotaal);
		if ($tier === null) {
			$this->logger->debug('KiaSchalenLookup: no tier matched', ['jaartotaal' => $jaartotaal]);
			return 0;
		}

		return max(0, $this->deductionForTier(tier: $tier, jaartotaal: $jaartotaal));
	}//end computeAftrek()

	/**
	 * Apply a single resolved tier's band formula (REQ-INV-006).
	 *
	 * @param array<string,mixed> $tier The resolved KIA tier row.
	 * @param int $jaartotaal Running KIA investment total in EUR cents.
	 *
	 * @return int The (possibly negative, capped by the caller) tier aftrek in EUR cents.
	 */
	private function deductionForTier(array $tier, int $jaartotaal): int {
		$percentage = $tier['percentage'];
		$vastAmount = $tier['fixedAmount'];
		$from = (int)($tier['from'] ?? 0);

		// Tier-4 taper: flat anchor minus a percentage of the excess over `vanaf`.
		if ($percentage !== null && (float)$percentage < 0.0 && $vastAmount !== null) {
			$excess = ($jaartotaal - $from);
			return ((int)$vastAmount + (int)round(((float)$percentage / 100.0) * $excess));
		}

		// Flat-amount band (tier 3): no percentage, fixed maximum.
		if ($percentage === null) {
			return (int)($vastAmount ?? 0);
		}

		// Percentage band (tier 2) and below-drempel / above-plafond (0%) band.
		return (int)round(((float)$percentage / 100.0) * $jaartotaal);
	}//end aftrekForTier()

	/**
	 * Compute the marginal KIA effect of adding one asset (REQ-INV-005).
	 *
	 * The marginal effect is the difference between the KIA-aftrek at the new
	 * total (with the asset) and at the prior total (without it) — NOT the
	 * asset value times the tier percentage, because KIA aggregates per
	 * boekjaar and may straddle tier boundaries.
	 *
	 * @param array<int,array<string,mixed>> $tiers KIA-tier rows.
	 * @param int $priorTotal Boekjaar total BEFORE this asset, EUR cents.
	 * @param int $assetValue This asset's KIA grondslag, EUR cents.
	 *
	 * @return int Marginal KIA contribution of the asset, EUR cents (may be zero, never negative).
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function marginalEffect(array $tiers, int $priorTotal, int $assetValue): int {
		$before = $this->computeAftrek(tiers: $tiers, jaartotaal: max(0, $priorTotal));
		$after = $this->computeAftrek(tiers: $tiers, jaartotaal: max(0, ($priorTotal + $assetValue)));

		return max(0, ($after - $before));
	}//end marginalEffect()
}//end class
