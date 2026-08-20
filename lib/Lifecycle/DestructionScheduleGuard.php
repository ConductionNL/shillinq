<?php

/**
 * Destruction Schedule Guard
 *
 * REQ-RAP-008 — enforces the destruction-schedule state machine
 * documented in `bookkeeping-rekenkamer-audit-pack/design.md` D3
 * across every register that has destruction-eligible records (T1
 * GLTransaction / T2 APInvoice + ARInvoice / T3 PurchaseOrder + …
 * — anything subject to Archiefwet retention).
 *
 * State machine:
 *   active
 *     -> marked-for-destruction          (compliance officer approval)
 *     -> active                          (rollback / unmark — allowed
 *                                          while the destruction order
 *                                          has not been executed)
 *   marked-for-destruction
 *     -> destruction-completed           (destruction executed)
 *     -> active                          (rollback)
 *   destruction-completed                (TERMINAL — immutable)
 *
 * Transition rules:
 *  - `active` records may transition to `marked-for-destruction` only
 *    when the record is older than 7 years (Archiefwet article 7) AND
 *    the caller is a compliance officer.
 *  - `marked-for-destruction` records may revert to `active` ONLY
 *    while the destruction order is unexecuted (this guard does not
 *    have access to the order; callers MUST verify).
 *  - `destruction-completed` is terminal — no modifications, no
 *    deletions. The record itself is preserved (Archiefwet requires
 *    proof of destruction; "destruction-completed" is the proof, not
 *    a true deletion).
 *
 * ADR-031 exception path: the state-transition policy is not yet
 * expressible in the declarative lifecycle DSL (it needs an age
 * check + a role check + a terminal-state flag). When the engine
 * gains those primitives, this guard becomes a declarative
 * lifecycle block and this file is deleted.
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
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * REQ-RAP-008 destruction-schedule state machine enforcement.
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 */
class DestructionScheduleGuard {

	/**
	 * Active state — record is in normal use.
	 *
	 * @var string
	 */
	public const STATE_ACTIVE = 'active';

	/**
	 * Marked-for-destruction state — compliance officer has approved
	 * the record for destruction but the destruction has not yet
	 * been executed.
	 *
	 * @var string
	 */
	public const STATE_MARKED = 'marked-for-destruction';

	/**
	 * Destruction-completed state — the record has been destroyed
	 * per Archiefwet article 7. TERMINAL.
	 *
	 * @var string
	 */
	public const STATE_COMPLETED = 'destruction-completed';

	/**
	 * Archiefwet retention floor — records younger than this many
	 * years MUST NOT be marked for destruction (REQ-RAP-008).
	 *
	 * @var int
	 */
	public const RETENTION_FLOOR_YEARS = 7;

	/**
	 * Closed set of recognised lifecycle states.
	 *
	 * @var array<string>
	 */
	public const STATES = [
		self::STATE_ACTIVE,
		self::STATE_MARKED,
		self::STATE_COMPLETED,
	];

	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check modifiability: `destruction-completed` records are immutable.
	 *
	 * @param array<string,mixed> $record The record being mutated.
	 *
	 * @return bool
	 */
	public function canModify(array $record): bool {
		$state = (string)($record['destructionState'] ?? ($record['status'] ?? self::STATE_ACTIVE));
		return $state !== self::STATE_COMPLETED;
	}//end canModify()

	/**
	 * Check deletability: destruction is a state transition, NEVER a true
	 * deletion. Archiefwet requires proof; deletion of a destruction-
	 * completed record would erase the proof.
	 *
	 * @param array<string,mixed> $record The record being deleted.
	 *
	 * @return bool
	 */
	public function canDelete(array $record): bool {
		$state = (string)($record['destructionState'] ?? ($record['status'] ?? self::STATE_ACTIVE));
		// Active records may be deleted by their normal lifecycle owner.
		// Once on the destruction schedule, only the destruction transition
		// may "remove" them — and that's a state change, not a delete.
		return $state === self::STATE_ACTIVE;
	}//end canDelete()

	/**
	 * Validate a proposed state transition.
	 *
	 * @param string $from Current state.
	 * @param string $to Proposed state.
	 * @param array<string,mixed> $record The record being transitioned (for age check).
	 * @param array<string,mixed> $options Transition options:
	 *                                     - `actorRoles`:
	 *                                     array of caller
	 *                                     roles (e.g.
	 *                                     ['compliance-officer']).
	 *                                     - `now`: ISO
	 *                                     timestamp to use as
	 *                                     "now" (for
	 *                                     deterministic
	 *                                     tests).
	 *
	 * @return bool True if the transition is allowed; false otherwise.
	 */
	public function canTransition(string $from, string $to, array $record, array $options = []): bool {
		if (in_array($from, self::STATES, true) === false || in_array($to, self::STATES, true) === false) {
			return false;
		}

		if ($from === $to) {
			return false;
		}

		// Destruction-completed is terminal.
		if ($from === self::STATE_COMPLETED) {
			return false;
		}

		// Active -> marked-for-destruction: compliance officer + 7+ years.
		if ($from === self::STATE_ACTIVE && $to === self::STATE_MARKED) {
			return $this->isComplianceOfficer(options: $options)
				&& $this->isOlderThanRetentionFloor(record: $record, options: $options);
		}

		// Active -> destruction-completed: NOT allowed (must go via marked).
		if ($from === self::STATE_ACTIVE && $to === self::STATE_COMPLETED) {
			return false;
		}

		// Marked -> active: allowed (rollback / unmark).
		if ($from === self::STATE_MARKED && $to === self::STATE_ACTIVE) {
			return true;
		}

		// Marked -> destruction-completed: compliance officer / system.
		if ($from === self::STATE_MARKED && $to === self::STATE_COMPLETED) {
			return $this->isComplianceOfficerOrSystem(options: $options);
		}

		return false;
	}//end canTransition()

	/**
	 * Build an audit event payload for a destruction-schedule transition
	 * so the caller can hand it to the OR audit-trail-immutable channel
	 * with the canonical fields (REQ-RAP-008): actor, action (lifecycle
	 * arrow), selectielijstCode, legalBasis.
	 *
	 * @param array<string,mixed> $record The record being transitioned.
	 * @param string $from Current state.
	 * @param string $to New state.
	 * @param array<string,mixed> $options Optional fields:
	 *                                     - `actorUid`: actor UID (defaults
	 *                                     to 'system').
	 *                                     - `selectielijstCode`: legal basis
	 *                                     code (e.g. "5.1.2").
	 *                                     - `legalBasis`: citation
	 *                                     (e.g. "Archiefwet Article 7").
	 *
	 * @return array<string,mixed>
	 */
	public function buildTransitionEvent(array $record, string $from, string $to, array $options = []): array {
		return [
			'action' => sprintf('lifecycle:%s→%s', $from, $to),
			'actor' => (string)($options['actorUid'] ?? 'system'),
			'objectType' => (string)($record['_objectType'] ?? ($record['schema'] ?? '')),
			'objectId' => (string)($record['id'] ?? ($record['uuid'] ?? '')),
			'selectionListCode' => (string)($options['selectionListCode'] ?? '5.1.2'),
			'legalBasis' => (string)($options['legalBasis'] ?? 'Archiefwet Article 7'),
			'timestamp' => (string)($options['now'] ?? date('c')),
			'requirementId' => 'REQ-RAP-008',
		];

	}//end buildTransitionEvent()

	/**
	 * Is the caller a compliance officer?
	 *
	 * @param array<string,mixed> $options Transition options.
	 *
	 * @return bool
	 */
	private function isComplianceOfficer(array $options): bool {
		$roles = (array)($options['actorRoles'] ?? []);
		return in_array('compliance-officer', $roles, true);
	}//end isComplianceOfficer()

	/**
	 * Is the caller a compliance officer OR a system / scheduled job
	 * caller (the destruction-completed transition can be executed
	 * by an automated runner under the compliance officer's pre-approval).
	 *
	 * @param array<string,mixed> $options Transition options.
	 *
	 * @return bool
	 */
	private function isComplianceOfficerOrSystem(array $options): bool {
		if ($this->isComplianceOfficer(options: $options) === true) {
			return true;
		}

		$actorUid = (string)($options['actorUid'] ?? '');
		return $actorUid === 'system' || $actorUid === 'shillinq-destruction-runner';
	}//end isComplianceOfficerOrSystem()

	/**
	 * Is the record older than the Archiefwet retention floor?
	 *
	 * @param array<string,mixed> $record The record (needs `createdAt` /
	 *                                    `created` / `recordedAt` ISO date).
	 * @param array<string,mixed> $options Transition options (may carry `now`).
	 *
	 * @return bool
	 */
	private function isOlderThanRetentionFloor(array $record, array $options): bool {
		$createdAt = (string)($record['createdAt'] ?? ($record['created'] ?? ($record['recordedAt'] ?? '')));
		if ($createdAt === '') {
			$this->logger->info(
				'DestructionScheduleGuard: cannot determine record age — refusing marked-for-destruction transition',
				['recordId' => (string)($record['id'] ?? '')]
			);
			return false;
		}

		try {
			$createdDate = new DateTimeImmutable($createdAt);
			$nowDate = new DateTimeImmutable((string)($options['now'] ?? 'now'));
		} catch (\Throwable $e) {
			return false;
		}

		$diff = $nowDate->diff($createdDate);
		return $diff->y >= self::RETENTION_FLOOR_YEARS;
	}//end isOlderThanRetentionFloor()
}//end class
