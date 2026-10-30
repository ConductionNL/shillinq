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

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\ServerWriteAuthority;

/**
 * Lets an invoice leave `exception` only through an accepted three-way-match exception.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 */
final class SupplierInvoiceExceptionApprovalGuard implements LifecycleGuardInterface {

	/**
	 * The grant ExceptionResolutionService holds while it releases the invoice.
	 *
	 * @var string
	 */
	public const PURPOSE = 'three-way-match-acceptance';

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'An invoice in exception is approved by accepting its three-way-match exception with a motivation.';

	/**
	 * Construct the guard.
	 *
	 * @param ServerWriteAuthority $authority The request-scoped write grants.
	 */
	public function __construct(
		private readonly ServerWriteAuthority $authority,
	) {
	}//end __construct()

	/**
	 * Allow the transition only under the acceptance grant.
	 *
	 * @param array<string, mixed> $object The invoice as it would be saved.
	 * @param string               $action The transition, `approveException`.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$id = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
		if ($this->authority->holds(purpose: self::PURPOSE, objectId: $id) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::MESSAGE);
	}//end check()
}//end class
