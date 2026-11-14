<?php

/**
 * Contract of PaymentRunReconciliationService
 *
 * The public surface of PaymentRunReconciliationService that other components call, so they depend
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
 * Contract of PaymentRunReconciliationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentRunReconciliationServiceInterface {

	/**
	 * Reconcile an exported PaymentRun against a CAMT.053 statement.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 * @param string $contents The raw CAMT.053 statement XML.
	 *
	 * @return array<string, mixed> An envelope: `{ result: 'full'|'partial',
	 *                              matchedCount, totalLines, unmatchedLines,
	 *                              reconciledAt?, lifecycleState, mismatchNote? }`
	 *                              or `{ error: ... }` on rejection.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
	 */
	public function reconcile(array $paymentRun, string $contents): array;
}//end interface
