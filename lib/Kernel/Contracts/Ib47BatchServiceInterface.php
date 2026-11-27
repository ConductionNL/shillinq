<?php

/**
 * Contract of Ib47BatchService
 *
 * The public surface of Ib47BatchService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of Ib47BatchService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface Ib47BatchServiceInterface {
	/**
	 * Prepare the IB47 batch of one administration and tax year: one record per
	 * payee marked as an IB47 recipient, holding what that payee was paid in
	 * the year. Preparing the same year again updates those records.
	 *
	 * @param string $administrationId The administration that made the payments.
	 * @param int    $taxYear          The tax year.
	 *
	 * @return array<string, mixed> `taxYear`, `administrationId`, `prepared` (per payee: payeeId,
	 *                              recipientName, paymentsTotal, recordId, created) and `incomplete`
	 *                              (per payee: payeeId, name, missing fields).
	 *
	 * @throws InvalidArgumentException When the administration is empty or the year is out of range.
	 *
	 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#requirement-the-annual-ib47-batch-is-prepared-from-the-payments-to-third-parties-req-dpa-021
	 */
	public function prepare(string $administrationId, int $taxYear): array;
}//end interface
