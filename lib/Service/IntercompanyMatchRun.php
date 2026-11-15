<?php

/**
 * Intercompany match run
 *
 * The state of one IntercompanyMatchingService::matchRelationPeriod() call:
 * the relation and period being matched, the FX rates, the register, the two
 * sides of the relation's transactions and their totals, and the match status.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of matching one intercompany relation for one period.
 *
 * @spec openspec/specs/bookkeeping-intercompany-elimination/spec.md#req-ice-003-periodieke-matching-auto-run
 */
final class IntercompanyMatchRun {
	/**
	 * Currency => reporting-currency rates.
	 *
	 * @var array<string,float>
	 */
	public array $rates = [];

	/**
	 * The register slug, resolved once for the run.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The IntercompanyRelation ([] when unknown).
	 *
	 * @var array<string,mixed>
	 */
	public array $relation = [];

	/**
	 * The relation's transactions split per side (sideA, sideB, idsA, idsB, hasFx).
	 *
	 * @var array<string,mixed>
	 */
	public array $sides = [];

	/**
	 * Side A total in cents.
	 *
	 * @var int
	 */
	public int $totalACents = 0;

	/**
	 * Side B total in cents.
	 *
	 * @var int
	 */
	public int $totalBCents = 0;

	/**
	 * The absolute side difference in cents.
	 *
	 * @var int
	 */
	public int $mismatchCents = 0;

	/**
	 * The relative side difference in percent.
	 *
	 * @var float
	 */
	public float $mismatchPercentage = 0.0;

	/**
	 * The match status.
	 *
	 * @var string
	 */
	public string $status = '';

	/**
	 * Start matching a relation for a period.
	 *
	 * @param string $relationId The IntercompanyRelation id.
	 * @param string $periodId   The consolidation period id.
	 */
	public function __construct(
		public readonly string $relationId,
		public readonly string $periodId,
	) {
	}//end __construct()
}//end class
