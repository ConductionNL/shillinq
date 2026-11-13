<?php

/**
 * Supplier Invoice Exception Approval Guard
 *
 * Guards `SupplierInvoice.approveException` (exception -> approved). An invoice
 * in exception is released for payment only when somebody with the exception
 * approver role accepts its three-way-match exception with a motivation, which
 * ExceptionResolutionService records on the match before it moves the invoice
 * under the `three-way-match-acceptance` grant. A PATCH of `statusCode` through
 * the object API carries no grant and no motivation, and is refused.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceExceptionApprovalGuardInterface;

/**
 * Lets an invoice leave `exception` only through an accepted three-way-match exception.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 */
final class SupplierInvoiceExceptionApprovalGuard extends ServerWriteGrantGuard implements SupplierInvoiceExceptionApprovalGuardInterface {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'An invoice in exception is approved by accepting its three-way-match exception with a motivation.';
}//end class
