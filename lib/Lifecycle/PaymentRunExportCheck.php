<?php

/**
 * One PaymentRunDuplicateGuard::check request.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The batch being exported, the transition action, its administration scope
 * and the invoice refs its lines settle.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
final class PaymentRunExportCheck {

	/**
	 * Construct the check.
	 *
	 * @param string $objectId The PaymentRun id.
	 * @param string $action The transition action.
	 * @param string $administrationId The administration scope ('' to skip scoping).
	 * @param array<string,true> $refs The apTransactionRef set.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly string $action,
		public readonly string $administrationId,
		public readonly array $refs = [],
	) {

	}//end __construct()

	/**
	 * The same check over the given ref set.
	 *
	 * @param array<string,true> $refs The apTransactionRef set.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 */
	public function withRefs(array $refs): self {
		return new self(
			objectId: $this->objectId,
			action: $this->action,
			administrationId: $this->administrationId,
			refs: $refs
		);

	}//end withRefs()
}//end class
