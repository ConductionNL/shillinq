<?php

/**
 * IB fiscal-adjustment computations — ADR-031 exception-path guard.
 *
 * Invoked by the x-openregister-calculations engine (via the `guard:` clause on
 * IBWinstOpgave.representatieCorrectie) when a fiscal commercial-to-fiscal P&L
 * correction cannot be expressed natively in the declarative calculation
 * syntax. Single deterministic method, no persistence, pure arithmetic per
 * ADR-031 §"PHP guards remain a legitimate seam". The representatiebeperking
 * caps the deductible representation costs at 5% of profit; the disallowed
 * excess is the correction that is added back and logged as a fiscaleAfwijking
 * (REQ-IB-001).
 *
 * Exception documented in
 * openspec/changes/bookkeeping-ib-aangifte-zzp/design.md §D3.
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
 * ADR-031 exception guard for the representatiebeperking (art. 3.15 Wet IB).
 *
 * Called by the calculation engine with the booked representation costs and the
 * profit base. Returns the non-deductible correction (the amount that must be
 * added back to fiscal profit), never a negative value.
 *
 * @spec openspec/specs/bookkeeping-ib-aangifte-zzp/spec.md
 */
class IbFiscalAdjustmentGuard {
	/**
	 * The statutory deductibility cap for representation costs (5% of profit).
	 *
	 * @var float
	 */
	private const REPRESENTATIE_CAP_RATE = 0.05;

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
	 * Compute the representatiebeperking correction per art. 3.15 Wet IB 2001.
	 *
	 * Representation costs are deductible only up to 5% of the profit base; the
	 * excess is non-deductible and returned here as a positive add-back. When
	 * the costs are within the cap (or the profit base is zero/negative) the
	 * correction is 0.0.
	 *
	 * @param float $representatiekosten Booked representation costs (>= 0).
	 * @param float $profitBasis Profit base the 5% cap is computed over.
	 *
	 * @return float The non-deductible correction to add back to fiscal profit (>= 0).
	 *
	 * @spec openspec/specs/bookkeeping-ib-aangifte-zzp/spec.md
	 */
	public function representatieDrempel(float $representatiekosten, float $profitBasis): float {
		$this->logger->debug(
			'IbFiscalAdjustmentGuard: representatieDrempel',
			['representatiekosten' => $representatiekosten, 'winstBasis' => $profitBasis]
		);

		if ($representatiekosten <= 0.0 || $profitBasis <= 0.0) {
			return 0.0;
		}

		$deductible = ($profitBasis * self::REPRESENTATIE_CAP_RATE);
		if ($representatiekosten <= $deductible) {
			return 0.0;
		}

		return round(($representatiekosten - $deductible), 2);
	}//end representatieDrempel()
}//end class
