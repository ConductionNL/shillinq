<?php

/**
 * Contract of InvoiceBatchService
 *
 * The public surface of InvoiceBatchService that other components call, so they depend
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
use OutOfBoundsException;

/**
 * Contract of InvoiceBatchService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InvoiceBatchServiceInterface {

	/**
	 * Create one invoice per customer of the batch.
	 *
	 * The request: `batchId` (optional; the caller sends the same one again to
	 * retry), `customerIds` (1 to 200), `charge` (`invoiceDate`, optional
	 * `dueDate`, `lines` of `description`, `quantity`, `unitPrice`, `vatRate`),
	 * `issue` and `send`. Sending needs issuing.
	 *
	 * @param string               $administrationId The administration that bills.
	 * @param array<string, mixed> $request          The batch as the caller sent it.
	 * @param string               $requestedBy      The signed-in user, notified when a send is done.
	 *
	 * @return array<string, mixed> The InvoiceBatch as stored, with one outcome per customer.
	 *
	 * @throws OutOfBoundsException     When the user may not act on the administration (shown as not found).
	 * @throws InvalidArgumentException When the batch cannot be created as a whole.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	public function create(string $administrationId, array $request, string $requestedBy = ''): array;

}//end interface
