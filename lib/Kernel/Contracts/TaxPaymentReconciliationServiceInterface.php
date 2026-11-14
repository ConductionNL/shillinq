<?php

/**
 * Contract of TaxPaymentReconciliationService
 *
 * The public surface of TaxPaymentReconciliationService that other components call, so they depend
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
 * Contract of TaxPaymentReconciliationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaxPaymentReconciliationServiceInterface {

	/**
	 * Reconcile a single payment record against the GL (REQ-VPB-008).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $paymentId Slug or id of the TaxPaymentTracking record.
	 *
	 * @return array{matched: bool, paymentAmount: float, glAmount: float, variance: float, glLineCount: int}
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-18
	 */
	public function reconcile(string $administrationId, string $paymentId): array;
}//end interface
