<?php

/**
 * Contract of UrenDagregistratieGuard
 *
 * The public surface of UrenDagregistratieGuard that other components call, so they depend
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
 * Contract of UrenDagregistratieGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface UrenDagregistratieGuardInterface {

	/**
	 * Apply the reistijd-cap to an entry, returning the counted hours and an
	 * optional audit note (REQ-URC-001).
	 *
	 * For REISTIJD_ZAKELIJK entries above the cap, only REISTIJD_CAP_PER_DAY hours
	 * are counted and a "Reistijd-cap toegepast: N uur niet meegeteld" note is
	 * produced. All other categories count their hours unchanged.
	 *
	 * @param string $category The category code.
	 * @param float $hours The registered hours.
	 *
	 * @return array{countedHours: float, capNote: ?string} Counted hours + note.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-24
	 */
	public function pasReistijdCapToe(string $category, float $hours): array;
}//end interface
