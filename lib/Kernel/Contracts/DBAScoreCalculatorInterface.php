<?php

/**
 * Contract of DBAScoreCalculator
 *
 * The public surface of DBAScoreCalculator that other components call, so they depend
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
 * Contract of DBAScoreCalculator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DBAScoreCalculatorInterface {

	/**
	 * Compute the full DBA risk-score (REQ-DBA-003).
	 *
	 * Sums the three pillar subtotals (max 60), adds Deliveroo-criteria (max 40),
	 * and applies conditional boosters: +5 when exclusief AND duurRelatie >= 1y;
	 * +5 when vervangbaarheid is theoretisch (vervangbaarScore < 5 AND
	 * vervangingFeitelijkScore = 10). The result is clamped to [0, 100].
	 *
	 * @param array<string,mixed> $intake A DBAIntake object as stored in OpenRegister.
	 *
	 * @return int The total score in [0, 100].
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeTotal(array $intake): int;

	/**
	 * Compute the persoonlijke arbeid subtotal (max 20).
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 20].
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function subtotalArbeid(array $intake): int;

	/**
	 * Compute the Deliveroo-criteria subtotal (max 40).
	 *
	 * Per HR 24-3-2023, ECLI:NL:HR:2023:443: weights each criterion to surface
	 * werknemerschap-signalen. Specialistisch werk + eigen klanten + eigen
	 * reclame reduce risk; exclusiviteit + lange duur + ontbrekend model raise it.
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 40].
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function subtotalDeliveroo(array $intake): int;

	/**
	 * Compute the financieel risico subtotal (max 20).
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 20].
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function subtotalFinancieel(array $intake): int;

	/**
	 * Compute the gezagsverhouding subtotal (max 20).
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 20].
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function subtotalGezag(array $intake): int;
}//end interface
