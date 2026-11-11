<?php

/**
 * One approver decision on a purchase order's approval chain.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * What is decided, on which purchase order, and, once the checks pass, by
 * whom and when. Built and read by PurchaseOrderApprovalService only.
 */
final class ApprovalDecision {

	/**
	 * Tenant scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The purchase order id.
	 *
	 * @var string
	 */
	public string $purchaseOrderId = '';

	/**
	 * The decision, one of the approval-chain decision values.
	 *
	 * @var string
	 */
	public string $decision = '';

	/**
	 * The approver's comment, when given.
	 *
	 * @var string|null
	 */
	public ?string $comment = null;

	/**
	 * The authenticated approver, stamped after the checks.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * When the decision was taken, as ISO-8601.
	 *
	 * @var string
	 */
	public string $decidedAt = '';
}//end class
