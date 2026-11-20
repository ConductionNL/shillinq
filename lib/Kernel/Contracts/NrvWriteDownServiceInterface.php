<?php

/**
 * Contract of NrvWriteDownService
 *
 * The public surface of NrvWriteDownService that other components call, so they depend
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
 * Contract of NrvWriteDownService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NrvWriteDownServiceInterface {

	/**
	 * Run lower-of-cost-or-NRV across every active snapshot for an
	 * administration, driven by an operator-supplied NRV map keyed by
	 * productId.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $periodId Period identifier.
	 * @param array<string,float> $nrvBySku Map productId => NRV per unit.
	 * @param string $postingDate ISO yyyy-mm-dd; empty defaults to today.
	 *
	 * @return array<string,mixed> Batch envelope: 'writeDownCount', 'totalWriteDownCents', 'results'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function runForAdministration(
		string $administrationId,
		string $periodId,
		array $nrvBySku,
		string $postingDate = '',
	): array;
}//end interface
