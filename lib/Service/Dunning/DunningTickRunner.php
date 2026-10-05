<?php

/**
 * Dunning tick runner.
 *
 * The daily pass of receivables-automatic-dunning (design D1, D2): for every
 * administration with automatic reminders switched on, under a lock per
 * administration, it moves each issued invoice past its due date to overdue,
 * then hands every overdue invoice, in pages of 100, to
 * DunningRunService::tickInvoice(). One invoice failing never stops the pass;
 * the counts per administration are kept as the run report.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the daily dunning pass over every administration that switched it on.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
 */
class DunningTickRunner {
	/**
	 * Invoices read per page.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * App-config key holding the last run report per administration, as JSON.
	 */
	public const REPORT_KEY = 'dunning.job_report';

	/**
	 * The Administration property that switches automatic reminders on.
	 */
	public const ENABLED_PROPERTY = 'dunningEnabled';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param DunningRunService      $dunning       Picks and sends the stage of one invoice.
	 * @param ObjectTransitionRunner $transitions   Runs the declared `mark-overdue` transition.
	 * @param ILockingProvider       $locks         The lock per administration.
	 * @param IAppConfig             $appConfig     Register slug and the run report.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly DunningRunService $dunning,
		private readonly ObjectTransitionRunner $transitions,
		private readonly ILockingProvider $locks,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the pass for every administration with automatic reminders on.
	 *
	 * The pass runs as the system. Cron has no session user, and OpenRegister
	 * treats a userless caller as the system only on the command line or
	 * inside runAsSystem(): under AJAX or webcron every read that keeps
	 * organisation scoping on (all of DunningRunService's, and the transition
	 * engine's) answers nothing, so no ladder was found and nothing was sent.
	 *
	 * @param DateTimeImmutable $now The moment of the run.
	 *
	 * @return array<int,array<string,mixed>> One report per administration that was enabled.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
	 */
	public function runAll(DateTimeImmutable $now): array {
		$reports = $this->objectService->runAsSystem(
			function () use ($now): array {
				$reports = [];
				foreach ($this->page(schema: 'Administration', filters: []) as $administration) {
					if (($administration[self::ENABLED_PROPERTY] ?? false) !== true) {
						continue;
					}

					$reports[] = $this->runAdministration(administration: $administration, now: $now);
				}

				return $reports;
			}
		);

		$this->storeReports(reports: $reports);
		return $reports;
	}//end runAll()

	/**
	 * Run the pass for one administration, under its lock.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 * @param DateTimeImmutable   $now            The moment of the run.
	 *
	 * @return array<string,mixed> The run report: markedOverdue, sent, failed, manual, skipped, errors, locked.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
	 */
	public function runAdministration(array $administration, DateTimeImmutable $now): array {
		$keys   = $this->administrationKeys(administration: $administration);
		$report = [
			'administrationId' => ($keys[0] ?? ''),
			'ranAt' => $now->format(DATE_ATOM),
			'markedOverdue' => 0,
			'sent' => 0,
			'failed' => 0,
			'manual' => 0,
			'skipped' => 0,
			'errors' => 0,
			'locked' => false,
		];
		if ($keys === []) {
			return $report;
		}

		$lock = 'shillinq/dunning-tick/' . $keys[0];
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'dunning run of ' . $keys[0]);
		} catch (LockedException) {
			$report['locked'] = true;
			return $report;
		}

		try {
			foreach ($keys as $key) {
				$this->markOverdue(administrationId: $key, now: $now, report: $report);
				$this->tickOverdue(administrationId: $key, now: $now, report: $report);
			}
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('Shillinq dunning: daily pass done', $report);
		return $report;
	}//end runAdministration()

	/**
	 * The last stored run report per administration.
	 *
	 * @return array<string,array<string,mixed>> Reports keyed by administration.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
	 */
	public function lastReports(): array {
		$stored = json_decode($this->appConfig->getValueString(Application::APP_ID, self::REPORT_KEY, '{}', lazy: true), true);
		if (is_array($stored) === false) {
			return [];
		}

		return $stored;
	}//end lastReports()

	/**
	 * The values invoices of this administration carry as `administrationId`.
	 *
	 * The app scopes financial records on the administration code (ADM-001)
	 * and older records on the record id, so both are asked for.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 *
	 * @return array<int,string> Distinct, non-empty; the code first.
	 */
	private function administrationKeys(array $administration): array {
		$keys = [
			(string)($administration['administrationCode'] ?? ''),
			(string)($administration['id'] ?? ''),
			(string)($administration['uuid'] ?? ''),
		];

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end administrationKeys()

	/**
	 * Move every issued invoice past its due date to overdue (design D2).
	 *
	 * The issued invoices are read first and moved after, so a page never
	 * shifts under the transitions it caused.
	 *
	 * @param string              $administrationId The administration key.
	 * @param DateTimeImmutable   $now              The moment of the run.
	 * @param array<string,mixed> $report           The run report, updated.
	 *
	 * @return void
	 */
	private function markOverdue(string $administrationId, DateTimeImmutable $now, array &$report): void {
		$today = $now->format('Y-m-d');
		$due   = [];
		foreach ($this->page(schema: 'ARInvoice', filters: ['administrationId' => $administrationId, 'lifecycleState' => 'issued']) as $invoice) {
			$dueDate = substr((string)($invoice['dueDate'] ?? ''), 0, 10);
			if ($dueDate !== '' && $dueDate < $today) {
				$due[] = (string)$invoice['id'];
			}
		}

		foreach ($due as $invoiceId) {
			try {
				$this->transitions->run(objectId: $invoiceId, action: 'mark-overdue');
				$report['markedOverdue']++;
			} catch (Throwable $e) {
				$report['errors']++;
				$this->logger->error('Shillinq dunning: an invoice could not be marked overdue', ['invoiceId' => $invoiceId, 'exception' => $e->getMessage()]);
			}
		}
	}//end markOverdue()

	/**
	 * Send every overdue invoice the stage that is due, one invoice at a time.
	 *
	 * @param string              $administrationId The administration key.
	 * @param DateTimeImmutable   $now              The moment of the run.
	 * @param array<string,mixed> $report           The run report, updated.
	 *
	 * @return void
	 */
	private function tickOverdue(string $administrationId, DateTimeImmutable $now, array &$report): void {
		foreach ($this->page(schema: 'ARInvoice', filters: ['administrationId' => $administrationId, 'lifecycleState' => 'overdue']) as $invoice) {
			try {
				$run = $this->dunning->tickInvoice(administrationId: $administrationId, invoice: $invoice, now: $now);
			} catch (Throwable $e) {
				$report['errors']++;
				$report['failed']++;
				$this->logger->error(
					'Shillinq dunning: an invoice failed, the run goes on',
					['invoiceId' => (string)$invoice['id'], 'exception' => $e->getMessage()]
				);
				continue;
			}

			$report[$this->outcome(run: $run)]++;
		}
	}//end tickOverdue()

	/**
	 * The report column a tick result counts in.
	 *
	 * @param array<string,mixed>|null $run The saved run, or null when nothing was due.
	 *
	 * @return string One of sent, failed, manual, skipped.
	 */
	private function outcome(?array $run): string {
		if ($run === null) {
			return 'skipped';
		}

		return match ((string)($run['deliveryStatus'] ?? '')) {
			'FAILED' => 'failed',
			'MANUAL' => 'manual',
			default => 'sent',
		};
	}//end outcome()

	/**
	 * Every record of a schema matching the filters, read in pages of 100.
	 *
	 * The job runs without a user, so register RBAC and organisation scoping
	 * are off: the administration filter is the scope.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return \Generator<int,array<string,mixed>>
	 */
	private function page(string $schema, array $filters): \Generator {
		$offset = 0;
		do {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => $offset], _rbac: false, _multitenancy: false);
			foreach ($rows as $row) {
				$record = ObjectIdentifier::recordWithId(candidate: $row);
				if ($record !== null) {
					yield $record;
				}
			}

			$offset += self::PAGE_SIZE;
			$full    = (count($rows) === self::PAGE_SIZE);
		} while ($full === true);
	}//end page()

	/**
	 * Keep the reports of this run, next to those of administrations it did not reach.
	 *
	 * @param array<int,array<string,mixed>> $reports The reports of this run.
	 *
	 * @return void
	 */
	private function storeReports(array $reports): void {
		$stored = $this->lastReports();
		foreach ($reports as $report) {
			$stored[(string)$report['administrationId']] = $report;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::REPORT_KEY, (string)json_encode($stored), lazy: true);
	}//end storeReports()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
