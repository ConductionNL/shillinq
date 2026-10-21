<?php

/**
 * Payment Run Reconcile Guard
 *
 * Guards `PaymentRun.reconcile` (exported -> reconciled). A run is reconciled
 * when every payment line is found on a booked CAMT.053 bank statement;
 * PaymentRunReconciliationService does that match and saves the transition
 * under the `payment-run-camt-reconcile` grant. A PATCH of `lifecycleState`
 * through the object API carries no grant and no statement, and is refused.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
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
 * Lets a payment run become reconciled only through a CAMT.053 match.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
 */
final class PaymentRunReconcileGuard implements LifecycleGuardInterface {

	/**
	 * The grant PaymentRunReconciliationService holds while it saves a full match.
	 *
	 * @var string
	 */
	public const PURPOSE = 'payment-run-camt-reconcile';

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'A payment run is reconciled by matching it against a CAMT.053 bank statement. Upload the statement to reconcile it.';

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
	 * Allow the transition only under the CAMT match grant.
	 *
	 * @param array<string, mixed> $object The run as it would be saved.
	 * @param string               $action The transition, `reconcile`.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$id = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
		if ($this->authority->holds(purpose: self::PURPOSE, objectId: $id) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::MESSAGE);
	}//end check()
}//end class
