<?php

/**
 * Period Close Service
 *
 * Tier-2 guided period-close orchestration (REQ-PC-002, REQ-PC-006, REQ-PC-008).
 * Drives the open → closing → closed → audit-locked lifecycle on PeriodClose
 * records through the real OpenRegister ObjectService API (find / findAll /
 * saveObject) and enforces the role gates server-side:
 *
 *  - period-closer: startClose (open → closing), close (closing → closed),
 *    reopen (closed → open)
 *  - auditor: lockForAudit (closed → audit-locked, irreversible)
 *
 * Role membership is resolved from Nextcloud groups (IGroupManager) named after
 * the role; the Nextcloud admin group always satisfies every gate (REQ-PC-008).
 * Reopen preserves the original close timestamp + actor in reopenedHistory and
 * requires a non-empty close reason. audit-locked is terminal — no transition
 * leaves it.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Orchestrates the PeriodClose lifecycle with server-side role enforcement.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
 */
class PeriodCloseService {
	/**
	 * Thrown-status sentinel for an authorization failure.
	 *
	 * @var string
	 */
	public const ERR_FORBIDDEN = 'forbidden';

	/**
	 * Thrown-status sentinel for an invalid lifecycle state.
	 *
	 * @var string
	 */
	public const ERR_INVALID_STATE = 'invalid-state';

	/**
	 * Thrown-status sentinel for a missing record.
	 *
	 * @var string
	 */
	public const ERR_NOT_FOUND = 'not-found';

	/**
	 * Thrown-status sentinel for a validation failure.
	 *
	 * @var string
	 */
	public const ERR_VALIDATION = 'validation';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IGroupManager $groupManager Group manager for role resolution.
	 * @param SuspenseAgeingService $suspenseAgeing Suspense worklist ageing (close blocker, REQ-PCG-003).
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly SuspenseAgeingService $suspenseAgeing,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Fetch a PeriodClose record by id, scoped to an administration (REQ-PC-005).
	 *
	 * @param string $periodId The PeriodClose record id (uuid) or business periodId.
	 * @param string $administrationId The administration the caller is scoped to (REQ-PC-008).
	 *
	 * @return array<string,mixed>|null The record, or null when not found / out of scope.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function getPeriodForClose(string $periodId, string $administrationId): ?array {
		$record = $this->find(periodId: $periodId, administrationId: $administrationId);
		if ($record === null) {
			return null;
		}

		return $record;
	}//end getPeriodForClose()

	/**
	 * Start the guided close for a period: open → closing (REQ-PC-002, REQ-PC-008).
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function startClose(string $periodId, string $administrationId, string $userId): array {
		$record = $this->requirePeriod(periodId: $periodId, administrationId: $administrationId);
		$this->requireRole(userId: $userId, role: 'period-closer');
		$this->requireState(record: $record, expected: 'open');

		$record['state'] = 'closing';

		return $this->persist(record: $record);
	}//end startClose()

	/**
	 * Close a period: closing → closed (REQ-PC-002, REQ-PC-008).
	 *
	 * Enforces the period-closer role, the closing state, and the mandatory
	 * checklist (AP / AR items resolved). Stamps closedAt / closedBy.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state / validation error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function closePeriod(string $periodId, string $administrationId, string $userId): array {
		$record = $this->requirePeriod(periodId: $periodId, administrationId: $administrationId);
		$this->requireRole(userId: $userId, role: 'period-closer');

		// Allow close from open (atomic open → closing → closed) or from closing.
		$state = (string)($record['state'] ?? '');
		if (in_array($state, ['open', 'closing'], true) === false) {
			throw new PeriodCloseException(
				message: 'Period is not in a closable state',
				status: self::ERR_INVALID_STATE
			);
		}

		if ($this->mandatoryChecklistResolved(record: $record) === false) {
			throw new PeriodCloseException(
				message: 'All mandatory checklist items (AP, AR) must be resolved before close',
				status: self::ERR_VALIDATION
			);
		}

		// Payment-control-guards REQ-PCG-003: block the close while the
		// bank-reconciliation suspense worklist is non-empty (unmatched /
		// routed-to-suspense bank items). Scoped to the period's own
		// administration, falling back to the caller's scope. Fail closed: an
		// unreadable worklist blocks the close rather than being treated as empty.
		$suspenseScope = (string)($record['administrationId'] ?? $administrationId);
		try {
			$suspenseBlocked = $this->suspenseAgeing->hasUnresolvedItems(administrationId: $suspenseScope);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodCloseService: suspense worklist could not be verified — blocking close (fail-closed)',
				['administrationId' => $suspenseScope, 'exception' => $e->getMessage()]
			);
			throw new PeriodCloseException(
				message: 'Cannot close the period: the bank-reconciliation suspense worklist could not be verified (fail-closed). '
					. 'Retry once the reconciliation data is available.',
				status: self::ERR_VALIDATION
			);
		}

		if ($suspenseBlocked === true) {
			$summary = $this->suspenseAgeing->agedUnmatchedItems(administrationId: $suspenseScope);
			throw new PeriodCloseException(
				message: sprintf(
					'Cannot close the period: %d unmatched bank/suspense item(s) remain (oldest %d day(s) outstanding). '
						. 'Match, route or resolve every suspense item before closing.',
					$summary['count'],
					$summary['oldestDaysOutstanding']
				),
				status: self::ERR_VALIDATION
			);
		}

		$record['state'] = 'closed';
		$record['closedAt'] = $this->now();
		$record['closedBy'] = $userId;

		$this->logger->info(
			'PeriodCloseService: period closed',
			['periodId' => ($record['periodId'] ?? $periodId), 'closedBy' => $userId]
		);

		return $this->persist(record: $record);
	}//end closePeriod()

	/**
	 * Reopen a closed period: closed → open (REQ-PC-006, REQ-PC-008).
	 *
	 * Requires the period-closer role and a non-empty close reason. Appends the
	 * original close timestamp + actor to reopenedHistory and clears closedAt /
	 * closedBy. An audit-locked period cannot be reopened.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $closeReason The audit-trailed reopen reason (non-empty).
	 * @param string $userId The acting user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state / validation error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function reopenPeriod(string $periodId, string $administrationId, string $closeReason, string $userId): array {
		$record = $this->requirePeriod(periodId: $periodId, administrationId: $administrationId);
		$this->requireRole(userId: $userId, role: 'period-closer');
		$this->requireState(record: $record, expected: 'closed');

		$reason = trim($closeReason);
		if ($reason === '') {
			throw new PeriodCloseException(
				message: 'A close reason is required to reopen a period',
				status: self::ERR_VALIDATION
			);
		}

		$history = ($record['reopenedHistory'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = [
			'closedAt' => ($record['closedAt'] ?? null),
			'closedBy' => ($record['closedBy'] ?? null),
			'reopenedAt' => $this->now(),
			'reopenedBy' => $userId,
			'closeReason' => $reason,
		];

		$record['state'] = 'open';
		$record['reopenedHistory'] = $history;
		$record['closeReason'] = $reason;
		$record['closedAt'] = null;
		$record['closedBy'] = null;

		$this->logger->info(
			'PeriodCloseService: period reopened',
			['periodId' => ($record['periodId'] ?? $periodId), 'reopenedBy' => $userId]
		);

		return $this->persist(record: $record);
	}//end reopenPeriod()

	/**
	 * Audit-lock a closed period: closed → audit-locked, irreversible (REQ-PC-002, REQ-PC-008).
	 *
	 * Requires the auditor role and the closed state. Stamps auditLockedAt /
	 * auditLockedBy. The audit-locked state is terminal.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $userId The acting auditor's user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function lockForAudit(string $periodId, string $administrationId, string $userId): array {
		$record = $this->requirePeriod(periodId: $periodId, administrationId: $administrationId);
		$this->requireRole(userId: $userId, role: 'auditor');
		$this->requireState(record: $record, expected: 'closed');

		$record['state'] = 'audit-locked';
		$record['auditLockedAt'] = $this->now();
		$record['auditLockedBy'] = $userId;

		$this->logger->info(
			'PeriodCloseService: period audit-locked',
			['periodId' => ($record['periodId'] ?? $periodId), 'auditLockedBy' => $userId]
		);

		return $this->persist(record: $record);
	}//end lockForAudit()

	/**
	 * Whether every mandatory (AP / AR) checklist item on the record is resolved.
	 *
	 * @param array<string,mixed> $record The PeriodClose record.
	 *
	 * @return bool True when all mandatory items are resolved (or none exist).
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function mandatoryChecklistResolved(array $record): bool {
		$items = ($record['taskChecklistItems'] ?? []);
		if (is_array($items) === false) {
			return true;
		}

		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$category = (string)($item['category'] ?? '');
			if (in_array($category, ['ap', 'ar'], true) === false) {
				continue;
			}

			if (($item['resolved'] ?? false) !== true) {
				return false;
			}
		}

		return true;
	}//end mandatoryChecklistResolved()

	/**
	 * Resolve the period record or throw a not-found error (REQ-PC-008 scoping).
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The administration scope.
	 *
	 * @return array<string,mixed> The record.
	 *
	 * @throws PeriodCloseException When the record does not exist in scope.
	 */
	private function requirePeriod(string $periodId, string $administrationId): array {
		$record = $this->find(periodId: $periodId, administrationId: $administrationId);
		if ($record === null) {
			throw new PeriodCloseException(
				message: 'Period not found',
				status: self::ERR_NOT_FOUND
			);
		}

		return $record;
	}//end requirePeriod()

	/**
	 * Throw unless the record is in the expected state.
	 *
	 * @param array<string,mixed> $record The PeriodClose record.
	 * @param string $expected The required current state.
	 *
	 * @return void
	 *
	 * @throws PeriodCloseException When the state does not match.
	 */
	private function requireState(array $record, string $expected): void {
		if ((string)($record['state'] ?? '') !== $expected) {
			throw new PeriodCloseException(
				message: 'Period is not in the required state (' . $expected . ')',
				status: self::ERR_INVALID_STATE
			);
		}

	}//end requireState()

	/**
	 * Throw unless the user holds the given role (or is a Nextcloud admin).
	 *
	 * Role membership maps to a Nextcloud group of the same name (REQ-PC-008).
	 *
	 * @param string $userId The acting user id.
	 * @param string $role The required role (period-closer / auditor).
	 *
	 * @return void
	 *
	 * @throws PeriodCloseException When the user lacks the role.
	 */
	private function requireRole(string $userId, string $role): void {
		if ($this->hasRole(userId: $userId, role: $role) === false) {
			$this->logger->warning(
				'PeriodCloseService: role check denied',
				['userId' => $userId, 'role' => $role]
			);
			throw new PeriodCloseException(
				message: 'You do not have permission to perform this action',
				status: self::ERR_FORBIDDEN
			);
		}

	}//end requireRole()

	/**
	 * Whether the user is in the role group or the Nextcloud admin group.
	 *
	 * @param string $userId The user id.
	 * @param string $role The role / group id.
	 *
	 * @return bool True when authorised.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function hasRole(string $userId, string $role): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->groupManager->isInGroup($userId, $role);
	}//end hasRole()

	/**
	 * Find a PeriodClose by record id or business periodId, scoped to an administration.
	 *
	 * @param string $periodId The PeriodClose record id (uuid) or business periodId.
	 * @param string $administrationId The administration scope (REQ-PC-008).
	 *
	 * @return array<string,mixed>|null The record, or null when not found.
	 */
	private function find(string $periodId, string $administrationId): ?array {
		$register = $this->register();

		// Match on the business periodId within the administration first (the
		// common case: the UI/route carries the business period identifier).
		$filters = ['periodId' => $periodId];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$found = $this->objectService
			->setRegister($register)
			->setSchema('FiscalPeriod')
			->findAll(['filters' => $filters, 'limit' => 1]);

		if ($found !== []) {
			return $this->normaliseRow(row: $found[0]);
		}

		// Fall back to the record-id lookup, still scoped to the administration.
		// NOT findAll(['filters' => ['id' => …]]) — `filters` addresses JSON
		// properties and the entity's `id` is not one, so this arm matched
		// nothing for every value and the fallback never actually fell back.
		$record = ObjectIdentifier::findOne(
			scoped: $this->objectService
				->setRegister($register)
				->setSchema('FiscalPeriod'),
			id: $periodId
		);

		if ($record !== null) {
			$recordAdmin = (string)($record['administrationId'] ?? '');
			if ($administrationId === '' || $recordAdmin === '' || $recordAdmin === $administrationId) {
				return $record;
			}
		}

		return null;
	}//end find()

	/**
	 * Normalise one OpenRegister result row into its data array.
	 *
	 * `ObjectService::findAll()` yields `ObjectEntity` instances, not arrays.
	 * A bare `(array) $entity` cast does NOT produce the record — PHP's
	 * object-to-array cast returns the entity's own (NUL-byte-prefixed) private
	 * properties, so `$record['periodId']`, `$record['state']` and
	 * `$record['administrationId']` all came back missing. The visible symptoms
	 * were `GET /api/period-close/{id}` answering 200 with `data.id = null` and
	 * no `periodId`, and `POST .../start-close` answering 409 "Period is not in
	 * the required state (open)" against a period whose stored state IS `open`.
	 *
	 * `jsonSerialize()` is the entity's rendered form (object data + `@self`),
	 * which is also what `persist()` needs in order to UPDATE the existing row
	 * rather than insert a new one. Arrays are passed through so a stub or
	 * already-rendered backend keeps working.
	 *
	 * @param mixed $row One row as returned by ObjectService::findAll().
	 *
	 * @return array<string,mixed> The record's data array.
	 *
	 * @spec openspec/specs/bookkeeping-period-close/spec.md
	 */
	private function normaliseRow(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$rendered = $row->jsonSerialize();
			if (is_array($rendered) === true) {
				return $rendered;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$data = $row->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end normaliseRow()

	/**
	 * Persist the record via the OpenRegister ObjectService (REQ-PC-002).
	 *
	 * @param array<string,mixed> $record The PeriodClose record to save.
	 *
	 * @return array<string,mixed> The saved record (echoes the input on stub backends).
	 */
	private function persist(array $record): array {
		$this->objectService->saveObject(
			object: $record,
			register: $this->register(),
			schema: 'FiscalPeriod',
		);

		return $record;
	}//end persist()

	/**
	 * Current timestamp in ISO-8601, used for closedAt / auditLockedAt stamps.
	 *
	 * @return string ISO-8601 timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
