<?php

/**
 * Four-Eyes Payment-Run Guard
 *
 * Server-side segregation-of-duties control on the `PaymentRun.approve`
 * lifecycle transition (draft -> approved). Approval is the hard control point
 * before an outgoing SEPA batch can be exported and money leaves the
 * administration (REQ-PR4E-001). This guard enforces that the user who
 * APPROVES a batch is NOT the user who PREPARED it: the preparer identity is
 * derived exclusively from OpenRegister's immutable audit trail (ADR-022) —
 * the actor of the `create` event, plus every actor who `update`d the batch
 * while it was still a draft — never a hand-rolled parallel actor log.
 *
 * FAIL CLOSED (CWE-863 / OWASP A01:2021): the guard DENIES the transition
 * whenever it cannot positively establish that the approver differs from the
 * preparer — a missing/unknown caller identity, an unidentifiable object, an
 * audit trail that yields no determinable `create` actor, or any thrown
 * exception all block the release. An indeterminate check must never be
 * treated as a pass; this control exists precisely because the prior
 * `ComplianceValidator` fabricated a hardcoded `segregation => true`.
 *
 * The guard is wired to the transition via the schema's
 * `x-openregister-lifecycle.transitions.approve.requires` DI tag (its own
 * FQCN) and invoked by OpenRegister's LifecycleValidationListener, which calls
 * `check($object, $action, $userId)` with `$userId` = the authenticated caller
 * (the approver). It is read-only: it MUST NOT mutate the object.
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
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces approver != preparer on the PaymentRun approve transition.
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 */
final class FourEyesPaymentRunGuard implements LifecycleGuardInterface {
	/**
	 * User-facing denial when the approver is also the preparer.
	 *
	 * @var string
	 */
	public const MESSAGE_SELF_APPROVAL = 'Self-approval is not permitted: you prepared or modified this payment run, so you cannot also approve it. '
		. 'A different authorised user must approve the batch before it can be exported.';

	/**
	 * User-facing denial when the caller (approver) identity is unknown.
	 *
	 * @var string
	 */
	public const MESSAGE_NO_APPROVER = 'The payment run cannot be approved: the approving user could not be identified. Sign in and retry; '
		. 'an unidentified approver is blocked (fail-closed).';

	/**
	 * User-facing denial when the batch itself cannot be identified.
	 *
	 * @var string
	 */
	public const MESSAGE_NO_OBJECT = 'The payment run cannot be approved: the batch could not be identified for the segregation-of-duties check '
		. '(fail-closed).';

	/**
	 * User-facing denial when the preparer cannot be established from the audit trail.
	 *
	 * @var string
	 */
	public const MESSAGE_INDETERMINATE = 'The payment run cannot be approved: the preparer could not be determined from the audit trail, '
		. 'so the four-eyes check cannot be satisfied (fail-closed).';

	/**
	 * FQCN of OpenRegister's ObjectService, resolved lazily from the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Construct the guard.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution (ADR-022).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise (or deny) the payment-run approval transition.
	 *
	 * @param array<string, mixed> $object The loaded PaymentRun payload at its current (draft) state.
	 * @param string $action The transition action being applied (expected: `approve`).
	 * @param string $userId The uid of the caller (the approver).
	 *
	 * @return GuardResult Allow when approver != preparer; deny (fail-closed) otherwise.
	 *
	 * @spec openspec/specs/payment-run-four-eyes/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$approverId = trim($userId);
		if ($approverId === '') {
			$this->logger->warning('FourEyesPaymentRunGuard: approver identity is empty — denying (fail-closed).', ['action' => $action]);
			return GuardResult::deny(self::MESSAGE_NO_APPROVER);
		}

		$objectId = $this->resolveObjectId(object: $object);
		if ($objectId === '') {
			$this->logger->warning('FourEyesPaymentRunGuard: payment-run id is empty — denying (fail-closed).', ['action' => $action]);
			return GuardResult::deny(self::MESSAGE_NO_OBJECT);
		}

		try {
			$preparers = $this->resolvePreparers(objectId: $objectId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'FourEyesPaymentRunGuard: audit-trail read threw — denying (fail-closed).',
				['paymentRun' => $objectId, 'action' => $action, 'error' => $e->getMessage()]
			);
			return GuardResult::deny(self::MESSAGE_INDETERMINATE);
		}

		if ($preparers === null) {
			// No determinable `create` actor in the immutable audit trail.
			$this->logger->error(
				'FourEyesPaymentRunGuard: preparer indeterminate from audit trail — denying (fail-closed).',
				['paymentRun' => $objectId, 'action' => $action]
			);
			return GuardResult::deny(self::MESSAGE_INDETERMINATE);
		}

		if (isset($preparers[$approverId]) === true) {
			$this->logger->warning(
				'FourEyesPaymentRunGuard: approver is also the preparer — denying self-approval.',
				['paymentRun' => $objectId, 'action' => $action, 'user' => $approverId]
			);
			return GuardResult::deny(self::MESSAGE_SELF_APPROVAL);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Extract the object uuid from the transition payload.
	 *
	 * OpenRegister's `ObjectEntity::getObject()` injects the uuid as the `id`
	 * field; the `@self` envelope carries it too when the payload is extended.
	 *
	 * @param array<string, mixed> $object The loaded object payload.
	 *
	 * @return string The uuid, or '' when it cannot be resolved.
	 */
	private function resolveObjectId(array $object): string {
		$id = ($object['id'] ?? ($object['@self']['id'] ?? ''));
		if (is_string($id) === false) {
			return '';
		}

		return trim($id);
	}//end resolveObjectId()

	/**
	 * Build the set of preparer uids from the object's immutable audit trail.
	 *
	 * The preparer set is every distinct, non-empty actor who performed a
	 * `create` or `update` on the batch. The `create` actor is mandatory: when
	 * no `create` log with a determinable user exists, the preparer is
	 * indeterminate and this returns null so the caller fails closed.
	 *
	 * @param string $objectId The PaymentRun uuid.
	 *
	 * @return array<string, true>|null Preparer uids as a set, or null when indeterminate.
	 */
	private function resolvePreparers(string $objectId): ?array {
		$objectService = $this->container->get(self::OBJECT_SERVICE);
		// `getLogs()` returns the object's OpenRegister audit-trail rows
		// (ADR-022). RBAC/multitenancy flags are ignored by getLogs itself;
		// we read the actor purely to make the internal control decision and
		// never surface the rows.
		$logs = $objectService->getLogs($objectId, [], false, false);
		if (is_array($logs) === false || $logs === []) {
			return null;
		}

		$preparers = [];
		$createActor = false;
		foreach ($logs as $log) {
			$logAction = $this->logField(log: $log, key: 'action', getter: 'getAction');
			$logUser = $this->logField(log: $log, key: 'user', getter: 'getUser');

			if ($logAction !== 'create' && $logAction !== 'update') {
				continue;
			}

			if ($logAction === 'create' && $logUser !== '') {
				$createActor = true;
			}

			if ($logUser !== '') {
				$preparers[$logUser] = true;
			}
		}//end foreach

		if ($createActor === false) {
			return null;
		}

		return $preparers;
	}//end resolvePreparers()

	/**
	 * Read a field from an audit-trail row that may be an entity or an array.
	 *
	 * OpenRegister's `getLogs()` yields `AuditTrail` entities (getAction() /
	 * getUser()); we also accept the array/jsonSerialize() shape defensively.
	 *
	 * @param mixed $log A single audit-trail row.
	 * @param string $key Array key for the array shape.
	 * @param string $getter Getter method name for the entity shape.
	 *
	 * @return string The trimmed string value, or '' when absent.
	 */
	private function logField(mixed $log, string $key, string $getter): string {
		$value = null;
		if (is_object($log) === true && method_exists($log, $getter) === true) {
			$value = $log->{$getter}();
		} elseif (is_array($log) === true && array_key_exists($key, $log) === true) {
			$value = $log[$key];
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end logField()
}//end class
