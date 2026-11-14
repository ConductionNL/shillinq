<?php

/**
 * Shillinq LeaseEventRequest
 *
 * Who asks for a lease reassessment event: the lease contract, the
 * administration it is scoped to, the business reason and the approver,
 * as LeaseReassessmentService's public record methods receive them.
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
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The lease, scope, reason and approver of one reassessment event.
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 */
final class LeaseEventRequest {
	/**
	 * Hold the request.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param string $triggerDescription Free-text business reason ('' for the event's default).
	 * @param string $approver Approver person id.
	 */
	public function __construct(
		public readonly string $leaseContractId,
		public readonly string $administrationId,
		public readonly string $triggerDescription,
		public readonly string $approver,
	) {
	}//end __construct()

	/**
	 * The business reason, or the given default when none was supplied.
	 *
	 * @param string $default The event's default reason.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function triggerOr(string $default): string {
		if ($this->triggerDescription !== '') {
			return $this->triggerDescription;
		}

		return $default;
	}//end triggerOr()
}//end class
