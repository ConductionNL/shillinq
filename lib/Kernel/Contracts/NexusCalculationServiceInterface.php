<?php

/**
 * Contract of NexusCalculationService
 *
 * The public surface of NexusCalculationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of NexusCalculationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NexusCalculationServiceInterface {

	/**
	 * The fixed OECD BEPS Action 5 uplift factor.
	 *
	 * @var float
	 */
	public const UPLIFT_FACTOR = 1.3;

	/**
	 * Compute the modified-nexus break for one asset/year (REQ-IBA-002).
	 *
	 * Returns the full breakdown so callers (and the audit trail) can record
	 * every intermediate value. All monetary inputs are plain euros; the ratios
	 * are rounded to four decimals to keep the result stable.
	 *
	 * @param float $eigenRdCost Internal R&D cost (loon + material).
	 * @param float $uitbesteedDerden R&D outsourced to unrelated third parties (teller).
	 * @param float $uitbesteedVerbonden R&D outsourced to related entities (noemer only).
	 * @param float $upliftFactor Uplift factor, default 1.3 per OECD.
	 *
	 * @return array{
	 *   numeratorBeforeUplift: float,
	 *   numeratorAfterUplift: float,
	 *   denominator: float,
	 *   nexusFractionUncapped: float,
	 *   nexusFractionApplied: float,
	 *   totalRdCost: float
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-002
	 */
	public function calculateNexusBreak(
		float $eigenRdCost,
		float $uitbesteedDerden,
		float $uitbesteedVerbonden,
		float $upliftFactor = self::UPLIFT_FACTOR,
	): array;
}//end interface
