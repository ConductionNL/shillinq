<?php

/**
 * Deferred Tax Run
 *
 * The state of one TaxCalculationService step: the fiscal period and the
 * administration it runs for, and the jurisdiction (and, while rate changes
 * are applied, the enacted rate) it is working on.
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
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Per-call state of a deferred-tax calculation step.
 *
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 */
final class DeferredTaxRun {

	/**
	 * The jurisdiction being worked on (an enacted-rates key or a country code).
	 *
	 * @var string|int
	 */
	public string|int $jurisdiction = '';

	/**
	 * The enacted rate being applied, in basis points.
	 *
	 * @var int
	 */
	public int $newRateBps = 0;

	/**
	 * The ISO date the enacted rate takes effect.
	 *
	 * @var string
	 */
	public string $effectiveDate = '';

	/**
	 * Constructor.
	 *
	 * @param string $periodId         Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function __construct(
		public readonly string $periodId,
		public readonly string $administrationId,
	) {

	}//end __construct()

	/**
	 * The filters of the period's administration-scoped records.
	 *
	 * @return array{periodId:string,administrationId:string}
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function periodFilters(): array {
		return [
			'periodId' => $this->periodId,
			'administrationId' => $this->administrationId,
		];

	}//end periodFilters()

	/**
	 * The filters of the period's records in the current jurisdiction.
	 *
	 * @return array{periodId:string,jurisdiction:string|int,administrationId:string}
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function jurisdictionFilters(): array {
		return [
			'periodId' => $this->periodId,
			'jurisdiction' => $this->jurisdiction,
			'administrationId' => $this->administrationId,
		];

	}//end jurisdictionFilters()
}//end class
