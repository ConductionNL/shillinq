<?php

/**
 * Contract of UrencriteriumYearGuard
 *
 * The public surface of UrencriteriumYearGuard that other components call, so they depend
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
 * Contract of UrencriteriumYearGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface UrencriteriumYearGuardInterface {

	/**
	 * Determine the applicable doel-norm from an entrepreneur profile.
	 *
	 * Pure, deterministic norm-determination per REQ-URC-000/006:
	 *   - AO (arbeidsongeschiktheid) → 800 (art. 3.6 lid 5).
	 *   - meewerkende partner without own onderneming → 525 (meewerkaftrek).
	 *   - otherwise → 1225 (art. 3.6 lid 1).
	 *
	 * @param array<string, mixed> $profiel Profile flags: 'arbeidsongeschikt' (bool),
	 *                                      'meewerkendePartner' (bool).
	 *
	 * @return int One of NORM_REGULIER / NORM_AO / NORM_MEEWERK.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function bepaalDoelNorm(array $profiel): int;

	/**
	 * Evaluate the grotendeels-criterium (>50% onderneming vs loondienst).
	 *
	 * Per art. 3.6 lid 2 Wet IB 2001 (REQ-URC-007): when the entrepreneur also
	 * works in paid employment, more than 50% of total working time must be spent
	 * on the onderneming.
	 *
	 * @param float $enterpriseHours Hours spent on the onderneming.
	 * @param float $employmentHours Hours spent in paid employment (0 = none).
	 *
	 * @return string NIET_TOEPASSELIJK when no loondienst, otherwise
	 *                GROTENDEELS_ONDERNEMING / NIET_GROTENDEELS_ONDERNEMING.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
	 */
	public function bepaalGrotendeelsCriterium(float $enterpriseHours, float $employmentHours): string;

	/**
	 * Determine the fiscal grondslag citation for a doel-norm.
	 *
	 * @param int $purposeNorm One of the recognised norm values.
	 *
	 * @return string The legal grondslag citation.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function bepaalNormGrondslag(int $purposeNorm): string;
}//end interface
