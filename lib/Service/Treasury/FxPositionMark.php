<?php

/**
 * Shillinq FxPositionMark
 *
 * The new mark of one FXPosition at a resolved closing rate: the position as
 * stored, the closing rate and where it came from, and the fair value,
 * movement and unrealised P&L that follow from them (REQ-MC-006).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

/**
 * An FXPosition marked at a closing rate.
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 */
final class FxPositionMark {
	/**
	 * Hold the mark.
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 * @param float $closingRate Rate applied this period.
	 * @param string $rateSource 'live' or 'manual-fallback'.
	 */
	public function __construct(
		public readonly array $position,
		public readonly float $closingRate,
		public readonly string $rateSource,
	) {
	}//end __construct()

	/**
	 * The net foreign-currency position.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function netPosition(): float {
		return (float)($this->position['position'] ?? 0.0);
	}//end netPosition()

	/**
	 * The rate of the prior mark, null when the position was never marked.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function priorSpotRate(): ?float {
		$priorSpotRateRaw = $this->position['spotRate'] ?? null;
		if ($priorSpotRateRaw === null) {
			return null;
		}

		return (float)$priorSpotRateRaw;
	}//end priorSpotRate()

	/**
	 * The fair value at the closing rate (position × closing rate).
	 *
	 * @return float
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function newFairValue(): float {
		return $this->netPosition() * $this->closingRate;
	}//end newFairValue()

	/**
	 * The movement of the fair value since the prior mark.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function delta(): float {
		return $this->newFairValue() - (float)($this->position['fairValue'] ?? 0.0);
	}//end delta()

	/**
	 * The movement in functional-currency cents.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function deltaCents(): int {
		return (int)round($this->delta() * 100);
	}//end deltaCents()

	/**
	 * The cumulative unrealised P&L after this mark; a first-ever mark keeps the prior value.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function newUnrealisedPL(): float {
		$priorUnrealisedPL = (float)($this->position['unrealisedPL'] ?? 0.0);
		if ($this->priorSpotRate() === null) {
			return $priorUnrealisedPL;
		}

		return $priorUnrealisedPL + $this->delta();
	}//end newUnrealisedPL()
}//end class
