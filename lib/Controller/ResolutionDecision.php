<?php

/**
 * Resolution Decision
 *
 * The classification and audit reason an operator gives a reconciliation match.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The posted resolution status and reason, trimmed.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
final class ResolutionDecision {
	/**
	 * The resolution status.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * The audit-trailed reason.
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Read the posted decision.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->status = trim((string)$request->getParam('resolutionStatus', ''));
		$this->reason = trim((string)$request->getParam('resolutionReason', ''));

	}//end __construct()
}//end class
