<?php

/**
 * Unit cost pricing
 *
 * The per-unit pricing fields of an IntegralCostPrice record: the units sold
 * and their label, the cost price per unit, the applied rate, the margin per
 * unit and the margin as a percentage of the cost price (of 1 when the cost
 * price is zero). The margin fields are null unless the rate and the cost
 * price are known. Also carries the revenue the input reports.
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
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The units sold, the applied rate and the margin it leaves over the cost price.
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
 */
final class UnitCostPricing {
	/**
	 * The units sold, when any were.
	 *
	 * @var float|null
	 */
	public readonly ?float $soldUnits;

	/**
	 * The label of a unit, when the input carries one.
	 *
	 * @var mixed
	 */
	public readonly mixed $unitLabel;

	/**
	 * The revenue in euros, when the input carries it.
	 *
	 * @var float|null
	 */
	public readonly ?float $revenueEur;

	/**
	 * The applied rate per unit, when the input carries one.
	 *
	 * @var float|null
	 */
	public readonly ?float $appliedRate;

	/**
	 * Applied rate minus cost price per unit.
	 *
	 * @var float|null
	 */
	public readonly ?float $marge;

	/**
	 * The margin as a percentage of the cost price.
	 *
	 * @var float|null
	 */
	public readonly ?float $margePercentage;

	/**
	 * Read the units, rate and revenue from the record input and derive the
	 * margin.
	 *
	 * @param array<string,mixed> $input            The record input (soldUnits, unitLabel, appliedRate, omzetEur).
	 * @param float|null          $costPricePerUnit The cost price per unit, when known.
	 */
	public function __construct(
		array $input,
		public readonly ?float $costPricePerUnit,
	) {
		$this->soldUnits = $this->positiveOrNull(value: (float)($input['soldUnits'] ?? 0));
		$this->unitLabel = ($input['unitLabel'] ?? null);
		$this->appliedRate = $this->floatOrNull(input: $input, key: 'appliedRate');
		$this->revenueEur = $this->floatOrNull(input: $input, key: 'omzetEur');
		$this->marge = $this->margin();
		$this->margePercentage = $this->percentage();
	}//end __construct()

	/**
	 * True when the applied rate is known and covers the cost price.
	 *
	 * @return bool Whether the rate is at or above the cost price.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function coversCostPrice(): bool {
		return $this->marge !== null && $this->appliedRate >= $this->costPricePerUnit;
	}//end coversCostPrice()

	/**
	 * The price fields of the record: soldUnits, unitLabel, costPricePerUnit,
	 * appliedRate, marge, margePercentage.
	 *
	 * @return array<string,mixed> The price fields, in record order.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function fields(): array {
		return [
			'soldUnits' => $this->soldUnits,
			'unitLabel' => $this->unitLabel,
			'costPricePerUnit' => $this->costPricePerUnit,
			'appliedRate' => $this->appliedRate,
			'marge' => $this->marge,
			'margePercentage' => $this->margePercentage,
		];
	}//end fields()

	/**
	 * A value when it is positive, otherwise null.
	 *
	 * @param float $value The value.
	 *
	 * @return float|null The value, or null.
	 */
	private function positiveOrNull(float $value): ?float {
		if ($value > 0.0) {
			return $value;
		}

		return null;
	}//end positiveOrNull()

	/**
	 * An input value as a float, or null when the input does not set it.
	 *
	 * @param array<string,mixed> $input The record input.
	 * @param string              $key   The input key.
	 *
	 * @return float|null The value, or null.
	 */
	private function floatOrNull(array $input, string $key): ?float {
		if (isset($input[$key]) === true) {
			return (float)$input[$key];
		}

		return null;
	}//end floatOrNull()

	/**
	 * Applied rate minus cost price, or null when either is unknown.
	 *
	 * @return float|null The margin per unit.
	 */
	private function margin(): ?float {
		if ($this->appliedRate === null || $this->costPricePerUnit === null) {
			return null;
		}

		return round(($this->appliedRate - $this->costPricePerUnit), 4);
	}//end margin()

	/**
	 * The margin as a percentage of the cost price (of 1 when the cost price
	 * is zero), or null without a margin.
	 *
	 * @return float|null The margin percentage.
	 */
	private function percentage(): ?float {
		if ($this->marge === null) {
			return null;
		}

		$base = 1.0;
		if ($this->costPricePerUnit > 0.0) {
			$base = $this->costPricePerUnit;
		}

		return round((($this->marge / $base) * 100), 4);
	}//end percentage()
}//end class
