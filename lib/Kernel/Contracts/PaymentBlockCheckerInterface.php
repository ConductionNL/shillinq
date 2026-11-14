<?php

/**
 * Contract of PaymentBlockChecker
 *
 * The public surface of PaymentBlockChecker that other components call, so they depend
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
 * Contract of PaymentBlockChecker.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentBlockCheckerInterface {

	/**
	 * The lines of a run that may not be paid, with the reason for each.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object.
	 *
	 * @return list<array{apTransactionRef: string, invoiceNumber: string, reason: string, detail: string}>
	 *
	 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-2.3
	 */
	public function blockedLines(array $paymentRun): array;
}//end interface
