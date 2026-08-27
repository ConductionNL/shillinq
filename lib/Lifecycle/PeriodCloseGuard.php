<?php

/**
 * Period Close Guard
 *
 * ADR-031 exception-path lifecycle guards for the period-close feature. Four
 * declarative-precondition methods, each referenced from a register fragment
 * lifecycle transition that the OpenRegister x-openregister-lifecycle engine
 * cannot yet express purely declaratively:
 *
 *  - periodOpen(): referenced from GLTransaction.post.preconditions — rejects a
 *    posting whose periodId resolves to a PeriodClose in state closed or
 *    audit-locked (REQ-PC-003, backdating prevention). The cross-schema lookup
 *    (resolve the GLTransaction's periodId to a PeriodClose row, then inspect
 *    its state) is not expressible in the declarative DSL.
 *  - mandatoryChecklistResolved(): referenced from PeriodClose.close — requires
 *    every mandatory checklist item (AP, AR) marked resolved (REQ-PC-002).
 *  - closeReasonSupplied(): referenced from PeriodClose.reopen — requires a
 *    non-empty close reason before a closed period may be reopened (REQ-PC-006).
 *  - trialBalanceVerifies(): referenced from FiscalPeriod.beginClose
 *    (lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json,
 *    open -> closing) — requires total posted debits to equal total posted
 *    credits for the period before the close may start (REQ-TB-003).
 *    shillinq#425: this method did not exist prior to this change, so
 *    beginClose hard-failed (RuntimeException from LifecycleGuardRegistry).
 *
 *
 * All three fail closed: any exception denies the transition (CWE-863).
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the period-close feature.
 *
 * Referenced from the bookkeeping-period-close register fragment:
 * GLTransaction.post.preconditions and PeriodClose.{close,reopen}.preconditions.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-3
 */
class PeriodCloseGuard {
	/**
	 * States in which a period rejects new postings (REQ-PC-003).
	 *
	 * @var array<string>
	 */
	private const FROZEN_STATES = [
		'closed',
		'audit-locked',
	];

	/**
	 * Mandatory checklist categories that must be resolved before close (REQ-PC-002).
	 *
	 * @var array<string>
	 */
	private const MANDATORY_CATEGORIES = [
		'ap',
		'ar',
	];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the posting's period is open enough to accept it (REQ-PC-003).
	 *
	 * Resolves the GLTransaction's periodId (+ administrationId, when present) to a
	 * PeriodClose record and denies the post transition when that period is in a
	 * frozen state (closed or audit-locked). A period with no PeriodClose record is
	 * treated as open (the close feature has not gated it yet) so existing ledgers
	 * keep posting. Fail-closed on any error.
	 *
	 * @param array<string,mixed>|string $transaction The GLTransaction record (or its id).
	 *
	 * @return bool True when the posting may proceed.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-4
	 */
	public function periodOpen(array|string $transaction): bool {
		try {
			$resolved = $this->resolveTransaction(transaction: $transaction);
			$periodId = (string)($resolved['periodId'] ?? '');
			if ($periodId === '') {
				// No period scope to gate against — allow (REQ-PC-003 applies only
				// to postings that resolve to a PeriodClose).
				return true;
			}

			$administrationId = (string)($resolved['administrationId'] ?? '');
			$period = $this->findPeriod(periodId: $periodId, administrationId: $administrationId);
			if ($period === null) {
				return true;
			}

			$state = (string)($period['state'] ?? 'open');
			if (in_array($state, self::FROZEN_STATES, true) === true) {
				$this->logger->info(
					'PeriodCloseGuard: posting rejected — period is frozen',
					['periodId' => $periodId, 'state' => $state]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodCloseGuard: periodOpen check failed — denying post (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end periodOpen()

	/**
	 * Returns true iff total posted debits equal total posted credits for the
	 * period (REQ-TB-003), computed directly from GLLine records (`side` +
	 * `amount`) filtered by the period's business `periodId`. A period with
	 * no posted lines yet is trivially balanced (0 === 0) and permitted to
	 * begin closing, mirroring periodOpen()'s "no scope, allow" convention.
	 * Fail-closed on any error.
	 *
	 * @param array<string,mixed>|string $period The FiscalPeriod record (or its id).
	 *
	 * @return bool True when the period's trial balance verifies.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function trialBalanceVerifies(array|string $period): bool {
		try {
			$resolved = $this->resolvePeriod(period: $period);
			if ($resolved === null) {
				return false;
			}

			$periodId = (string)($resolved['periodId'] ?? '');
			if ($periodId === '') {
				return true;
			}

			$lines = $this->objectService
				->setRegister($this->register())
				->setSchema('GLLine')
				->findAll(['filters' => ['periodId' => $periodId]]);

			if ($lines === []) {
				return true;
			}

			$debitCents = 0;
			$creditCents = 0;
			foreach ($lines as $line) {
				$amountCents = (int)round(((float)($line['amount'] ?? 0)) * 100);
				if ((string)($line['side'] ?? '') === 'debit') {
					$debitCents += $amountCents;
				} elseif ((string)($line['side'] ?? '') === 'credit') {
					$creditCents += $amountCents;
				}
			}

			if ($debitCents !== $creditCents) {
				$this->logger->info(
					'PeriodCloseGuard: trial balance does not verify — denying beginClose',
					['periodId' => $periodId, 'debitCents' => $debitCents, 'creditCents' => $creditCents]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodCloseGuard: trialBalanceVerifies check failed — denying beginClose (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end trialBalanceVerifies()

	/**
	 * Returns true iff every mandatory checklist item on the period is resolved (REQ-PC-002).
	 *
	 * The close transition (closing → closed) is gated on all AP and AR checklist
	 * items being marked resolved. Periods with no mandatory items are allowed to
	 * close. Fail-closed on any error.
	 *
	 * @param array<string,mixed>|string $period The PeriodClose record (or its id).
	 *
	 * @return bool True when the period may transition to closed.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-3
	 */
	public function mandatoryChecklistResolved(array|string $period): bool {
		try {
			$resolved = $this->resolvePeriod(period: $period);
			if ($resolved === null) {
				return false;
			}

			$items = ($resolved['taskChecklistItems'] ?? []);
			if (is_array($items) === false) {
				return true;
			}

			foreach ($items as $item) {
				if (is_array($item) === false) {
					continue;
				}

				$category = (string)($item['category'] ?? '');
				if (in_array($category, self::MANDATORY_CATEGORIES, true) === false) {
					continue;
				}

				if (($item['resolved'] ?? false) !== true) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodCloseGuard: mandatoryChecklistResolved check failed — denying close (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end mandatoryChecklistResolved()

	/**
	 * Returns true iff the bank-reconciliation suspense worklist is empty for the
	 * period's administration (payment-control-guards REQ-PCG-003).
	 *
	 * A period cannot be closed while unmatched / routed-to-suspense bank items
	 * remain: those are money the bank moved that the ledger has not yet matched,
	 * so the trial balance carries an unexplained suspense balance. Delegates the
	 * cross-schema BankStatementLine ageing to SuspenseAgeingService (resolved
	 * lazily from the container so this guard's constructor is unchanged). A
	 * period with no administration scope, or an empty worklist, is allowed to
	 * close (mirroring the "no scope, allow" convention). Fail-closed on any
	 * error — an indeterminate suspense check blocks the close.
	 *
	 * This is the declarative-parity method referenced from PeriodClose.close
	 * `preconditions`; the enforced block also lives imperatively in
	 * PeriodCloseService::closePeriod() (mirroring mandatoryChecklistResolved).
	 *
	 * @param array<string,mixed>|string $period The FiscalPeriod record (or its id).
	 *
	 * @return bool True when the period may close (suspense worklist empty).
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md (REQ-PCG-003)
	 */
	public function suspenseAccountDrained(array|string $period): bool {
		try {
			$resolved = $this->resolvePeriod(period: $period);
			if ($resolved === null) {
				return false;
			}

			$administrationId = trim((string)($resolved['administrationId'] ?? ''));
			if ($administrationId === '') {
				// No administration scope to check against — allow.
				return true;
			}

			$ageing = $this->container->get(SuspenseAgeingService::class);
			if ($ageing->hasUnresolvedItems($administrationId) === true) {
				$this->logger->info(
					'PeriodCloseGuard: suspense worklist non-empty — denying close',
					['administrationId' => $administrationId]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodCloseGuard: suspenseAccountDrained check failed — denying close (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end suspenseAccountDrained()

	/**
	 * Returns true iff a non-empty close reason is supplied for a reopen (REQ-PC-006).
	 *
	 * @param array<string,mixed>|string $period The PeriodClose record (or its id).
	 *
	 * @return bool True when the reopen may proceed.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-3
	 */
	public function closeReasonSupplied(array|string $period): bool {
		try {
			$resolved = $this->resolvePeriod(period: $period);
			if ($resolved === null) {
				return false;
			}

			return trim((string)($resolved['closeReason'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodCloseGuard: closeReasonSupplied check failed — denying reopen (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end closeReasonSupplied()

	/**
	 * Resolve a transaction argument (record array or id) to a GLTransaction record.
	 *
	 * @param array<string,mixed>|string $transaction The transaction record or id.
	 *
	 * @return array<string,mixed> The resolved transaction (possibly the input array).
	 */
	private function resolveTransaction(array|string $transaction): array {
		if (is_array($transaction) === true) {
			return $transaction;
		}

		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('GLTransaction')
			->findAll(['filters' => ['id' => $transaction], 'limit' => 1]);

		if ($found !== []) {
			return (array)$found[0];
		}

		return [];
	}//end resolveTransaction()

	/**
	 * Resolve a period argument (record array or id) to a PeriodClose record.
	 *
	 * @param array<string,mixed>|string $period The PeriodClose record or id.
	 *
	 * @return array<string,mixed>|null The resolved record, or null when not found.
	 */
	private function resolvePeriod(array|string $period): ?array {
		if (is_array($period) === true) {
			return $period;
		}

		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('FiscalPeriod')
			->findAll(['filters' => ['id' => $period], 'limit' => 1]);

		if ($found !== []) {
			return (array)$found[0];
		}

		return null;
	}//end resolvePeriod()

	/**
	 * Find the PeriodClose record for a period (scoped by administration when known).
	 *
	 * @param string $periodId The period identifier.
	 * @param string $administrationId The administration scope ('' to skip).
	 *
	 * @return array<string,mixed>|null The PeriodClose record, or null when none exists.
	 */
	private function findPeriod(string $periodId, string $administrationId): ?array {
		$filters = ['periodId' => $periodId];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('FiscalPeriod')
			->findAll(['filters' => $filters, 'limit' => 1]);

		if ($found !== []) {
			return (array)$found[0];
		}

		return null;
	}//end findPeriod()

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
