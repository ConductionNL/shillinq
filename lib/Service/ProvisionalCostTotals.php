<?php

/**
 * Provisional cost totals
 *
 * The running totals of the voorlopig (provisional) IntegralCostPrice records
 * a year-end lock aggregates: each cost component, the overhead per bucket
 * and the total cost.
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
 * Component totals over provisional IntegralCostPrice records.
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
 */
final class ProvisionalCostTotals {
	/**
	 * Summed component amounts, keyed by component name.
	 *
	 * @var array<string,float>
	 */
	private array $components = [
		'directPayrollCost' => 0.0,
		'directMaterials' => 0.0,
		'directDepreciations' => 0.0,
		'capitalCost' => 0.0,
		'profitMarkup' => 0.0,
	];

	/**
	 * Summed indirect overhead per bucket.
	 *
	 * @var array<string,float>
	 */
	private array $overheadBuckets = [];

	/**
	 * Summed total cost.
	 *
	 * @var float
	 */
	public float $totalCost = 0.0;

	/**
	 * Add one provisional record.
	 *
	 * @param array<string,mixed> $record A voorlopig IntegralCostPrice record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function add(array $record): void {
		$componenten = (array)($record['componenten'] ?? []);
		foreach (array_keys($this->components) as $component) {
			$this->components[$component] += (float)($componenten[$component] ?? 0);
		}

		foreach ((array)($componenten['indirecteOverhead'] ?? []) as $bucket => $amount) {
			$this->overheadBuckets[(string)$bucket] = ($this->overheadBuckets[(string)$bucket] ?? 0.0) + (float)$amount;
		}

		$this->totalCost += (float)($record['totalCost'] ?? 0);
	}//end add()

	/**
	 * The total cost per sold unit (4 decimals), or null without sold units.
	 *
	 * @param float $soldUnits The units sold.
	 *
	 * @return float|null The cost price per unit.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function costPricePerUnit(float $soldUnits): ?float {
		if ($soldUnits > 0.0) {
			return round(($this->totalCost / $soldUnits), 4);
		}

		return null;
	}//end costPricePerUnit()

	/**
	 * The componenten block of the locked record, rounded to cents.
	 *
	 * @return array<string,mixed> The components in record order.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function componenten(): array {
		return [
			'directPayrollCost' => round($this->components['directPayrollCost'], 2),
			'directMaterials' => round($this->components['directMaterials'], 2),
			'directDepreciations' => round($this->components['directDepreciations'], 2),
			'indirecteOverhead' => array_map(fn (float $v): float => round($v, 2), $this->overheadBuckets),
			'capitalCost' => round($this->components['capitalCost'], 2),
			'profitMarkup' => round($this->components['profitMarkup'], 2),
		];
	}//end componenten()
}//end class
