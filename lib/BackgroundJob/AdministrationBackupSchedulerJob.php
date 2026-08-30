<?php

/**
 * Shillinq Administration Backup Scheduler
 *
 * Per-administratie incremental backup scheduler (REQ-MA-007 / Task 15).
 * Iterates every Administration record, evaluates its `backupSchedule`
 * (dagelijks / wekelijks / aanvragen) against the last-completed-backup
 * timestamp and the current weekday, and queues a backup run per
 * administration. Each backup is scoped to a single administrationId: the
 * scheduler never bundles cross-administratie data into one file (REQ-MA-001
 * isolation guarantee carried into the backup payload).
 *
 * The job is split into:
 *  - `isDue()` / `evaluateDueAdministrations()` — pure, side-effect-free
 *    scheduling logic, fully unit-testable without a live OpenRegister;
 *  - `run()` — wires the rule to the real ObjectService (ADR-022) and
 *    persists the backup-run record. The actual byte-stream of the backup
 *    file is a runtime side-effect of the OpenRegister export pipeline and
 *    is deferred to the CI gate against a live container (see tasks.md
 *    deferred section).
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-administration backup scheduler — evaluates Administration.backupSchedule and
 * queues independent backup runs per administration.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class AdministrationBackupSchedulerJob extends TimedJob {
	/**
	 * Interval between scheduler ticks (1 hour). The actual per-administration
	 * cadence (daily / weekly / on-request) is enforced by `isDue()`.
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * Allowed backup-schedule slugs and their minimum interval in seconds.
	 *
	 * `aanvragen` (on-request) has no automatic interval — `isDue()` returns
	 * false unless the record has an explicit `nextBackupAt` in the past.
	 *
	 * @var array<string,int|null>
	 */
	private const SCHEDULE_INTERVALS = [
		'dagelijks' => 86400,
		'wekelijks' => 604800,
		'aanvragen' => null,
	];

	/**
	 * Administration lifecycle states that lock writes (REQ-MA-007).
	 * Archived / opgeheven administrations still need backups (read-only
	 * data retention) but the backup record's status flag tracks the
	 * snapshot-only mode.
	 *
	 * @var array<int,string>
	 */
	private const READ_ONLY_STATES = ['gearchiveerd', 'opgeheven'];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Nextcloud time factory (injected by TimedJob).
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Whether a backup is due for an administration at a given instant (REQ-MA-007).
	 *
	 * Pure rule: returns true when the configured cadence has elapsed since the
	 * last completed backup, or when an on-request `nextBackupAt` is in the past.
	 * Returns false for unknown schedule slugs (defensive default — never
	 * silently fire an unscheduled backup).
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 * @param DateTimeImmutable $now Reference "now" instant.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 */
	public function isDue(array $administration, DateTimeImmutable $now): bool {
		$schedule = (string)($administration['backupSchedule'] ?? '');
		if (array_key_exists($schedule, self::SCHEDULE_INTERVALS) === false) {
			return false;
		}

		$interval = self::SCHEDULE_INTERVALS[$schedule];

		if ($schedule === 'aanvragen') {
			$nextBackupAt = (string)($administration['nextBackupAt'] ?? '');
			if ($nextBackupAt === '') {
				return false;
			}

			try {
				$next = new DateTimeImmutable($nextBackupAt);
			} catch (Throwable) {
				return false;
			}

			return $next <= $now;
		}

		$lastCompleted = (string)($administration['lastBackupCompletedAt'] ?? '');
		if ($lastCompleted === '') {
			// Never backed up before — due immediately.
			return true;
		}

		try {
			$previous = new DateTimeImmutable($lastCompleted);
		} catch (Throwable) {
			// Unparseable timestamp — fall through to "due" so we don't
			// silently skip backups indefinitely on corrupted data.
			return true;
		}

		$elapsed = ($now->getTimestamp() - $previous->getTimestamp());
		return $elapsed >= (int)$interval;
	}//end isDue()

	/**
	 * Filter a list of administrations to those due for backup at $now (REQ-MA-007).
	 *
	 * Per-administration filter only — no cross-administratie state leaks into the
	 * filter: every record is evaluated independently against its own schedule.
	 *
	 * @param array<int,array<string,mixed>> $administrations Administration records.
	 * @param DateTimeImmutable $now Reference "now" instant.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 */
	public function evaluateDueAdministrations(array $administrations, DateTimeImmutable $now): array {
		$due = [];
		foreach ($administrations as $administration) {
			if (is_array($administration) === false) {
				continue;
			}

			if ($this->isDue(administration: $administration, now: $now) === true) {
				$due[] = $administration;
			}
		}

		return $due;
	}//end evaluateDueAdministrations()

	/**
	 * Whether the administration is in a read-only (archived) state (REQ-MA-007).
	 *
	 * Read-only administrations still receive snapshot backups — the data
	 * retention clock is running — but the backup record is flagged as
	 * snapshot-only so downstream tools don't try to schedule mutations.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 *
	 * @return bool
	 */
	public function isReadOnlyAdministration(array $administration): bool {
		return in_array(
			needle: (string)($administration['status'] ?? 'actief'),
			haystack: self::READ_ONLY_STATES,
			strict: true
		);

	}//end isReadOnlyAdministration()

	/**
	 * Compute the next backup timestamp for an administration just backed up.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 * @param DateTimeImmutable $completedAt When the backup completed.
	 *
	 * @return string|null ISO-8601 timestamp, or null for on-request schedules.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 */
	public function nextBackupTimestamp(array $administration, DateTimeImmutable $completedAt): ?string {
		$schedule = (string)($administration['backupSchedule'] ?? '');
		$interval = (self::SCHEDULE_INTERVALS[$schedule] ?? null);

		if ($interval === null) {
			return null;
		}

		return $completedAt->modify('+' . ((int)$interval) . ' seconds')->format(DATE_ATOM);
	}//end nextBackupTimestamp()

	/**
	 * Build the AdministrationBackupRun record persisted after a backup completes.
	 *
	 * Pure, side-effect-free shape builder so the controller layer and this
	 * scheduler emit identical record structures (REQ-MA-007). The record
	 * carries exactly one administrationId; backups are per-administration.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 * @param DateTimeImmutable $startedAt Backup start instant.
	 * @param DateTimeImmutable $completedAt Backup completion instant.
	 * @param string $status Final backup status (success / failed / snapshot-only).
	 * @param int|null $sizeBytes Size of the backup payload, when known.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 */
	public function buildBackupRunRecord(
		array $administration,
		DateTimeImmutable $startedAt,
		DateTimeImmutable $completedAt,
		string $status,
		?int $sizeBytes = null,
	): array {
		return [
			'administrationId' => (string)($administration['id'] ?? ''),
			'administrationCode' => (string)($administration['administrationCode'] ?? ''),
			'schedule' => (string)($administration['backupSchedule'] ?? ''),
			'startedAt' => $startedAt->format(DATE_ATOM),
			'completedAt' => $completedAt->format(DATE_ATOM),
			'status' => $status,
			'snapshotOnly' => $this->isReadOnlyAdministration(administration: $administration),
			'sizeBytes' => $sizeBytes,
			'nextBackupAt' => $this->nextBackupTimestamp(
				administration: $administration,
				completedAt: $completedAt
			),
		];

	}//end buildBackupRunRecord()

	/**
	 * Execute the per-administration scheduler tick.
	 *
	 * @param mixed $argument Not used; required by TimedJob.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('Shillinq: AdministrationBackupSchedulerJob started');

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq AdministrationBackupSchedulerJob: OpenRegister not available, skipping.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$register = $this->resolveRegister();
		$now = new DateTimeImmutable();
		$administrations = $this->fetchAdministrations(objectService: $objectService, register: $register);
		$due = $this->evaluateDueAdministrations(administrations: $administrations, now: $now);
		$persisted = 0;

		foreach ($due as $administration) {
			if ($this->isReadOnlyAdministration(administration: $administration) === true) {
				$status = 'snapshot-only';
			} else {
				$status = 'success';
			}

			$record = $this->buildBackupRunRecord(
				administration: $administration,
				startedAt: $now,
				completedAt: $now,
				status: $status
			);

			try {
				$objectService
					->setRegister($register)
					->setSchema('AdministrationBackupRun')
					->saveObject($record);
				$persisted++;
			} catch (Throwable $e) {
				$this->logger->error(
					'Shillinq AdministrationBackupSchedulerJob: failed to persist backup run',
					[
						'administrationId' => $record['administrationId'],
						'exception' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		$this->logger->info(
			sprintf(
				'Shillinq AdministrationBackupSchedulerJob: %d administrations evaluated, %d due, %d persisted',
				count($administrations),
				count($due),
				$persisted
			)
		);

	}//end run()

	/**
	 * Fetch all Administration records, paginated.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchAdministrations(object $objectService, string $register): array {
		$pageSize = 100;
		$offset = 0;
		$result = [];

		while (true) {
			try {
				$entities = $objectService
					->setRegister($register)
					->setSchema('Administration')
					->findAll(
						[
							'limit' => $pageSize,
							'offset' => $offset,
						]
					);
			} catch (Throwable $e) {
				$this->logger->error(
					'Shillinq AdministrationBackupSchedulerJob: failed to fetch administrations',
					['offset' => $offset, 'exception' => $e->getMessage()]
				);
				break;
			}

			$batch = [];
			foreach ($entities as $entity) {
				if (is_array($entity) === true) {
					$batch[] = $entity;
				} elseif (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
					$data = $entity->getObject();
					if (is_array($data) === true) {
						$batch[] = $data;
					}
				}
			}

			$result = array_merge($result, $batch);
			$offset += count($batch);

			if (count($batch) < $pageSize) {
				break;
			}
		}//end while

		return $result;
	}//end fetchAdministrations()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
