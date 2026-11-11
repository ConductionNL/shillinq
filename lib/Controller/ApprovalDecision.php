<?php

/**
 * Approval Decision
 *
 * The administration, purchase order, decision and comment of one
 * purchase-order approval step.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * One approval decision, read from the request.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
final class ApprovalDecision {
	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * The administration, '' when blank or malformed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The trimmed purchase order id.
	 *
	 * @var string
	 */
	public readonly string $purchaseOrderId;

	/**
	 * The trimmed decision.
	 *
	 * @var string
	 */
	public readonly string $decision;

	/**
	 * The comment, null unless a string was posted.
	 *
	 * @var string|null
	 */
	public readonly ?string $comment;

	/**
	 * Read one decision.
	 *
	 * @param IRequest $request The request.
	 * @param string   $id      The purchase order route id.
	 */
	public function __construct(IRequest $request, string $id) {
		$administrationId = trim((string)$request->getParam('administrationId', ''));
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			$administrationId = '';
		}

		$comment = $request->getParam('comment', null);
		if (is_string($comment) === false) {
			$comment = null;
		}

		$this->administrationId = $administrationId;
		$this->purchaseOrderId = trim($id);
		$this->decision = trim((string)$request->getParam('decision', ''));
		$this->comment = $comment;

	}//end __construct()

	/**
	 * Whether the purchase order id is a short slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function wellFormedOrder(): bool {
		return $this->purchaseOrderId !== '' && preg_match(self::ID_PATTERN, $this->purchaseOrderId) === 1;
	}//end wellFormedOrder()
}//end class
