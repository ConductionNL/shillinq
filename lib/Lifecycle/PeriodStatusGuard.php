<?php

/**
 * Period Status Guard
 *
 * ADR-031 exception-path lifecycle guard for the continuous-close feature.
 * Referenced from the bookkeeping-soft-close-flux register fragment
 * GLTransaction.post.preconditions: rejects a posting whose periodId resolves
 * to a PeriodStatus in stage hard-closed, audited, or locked unless the
 * transaction carries an explicit controller-override flag together with the
 * exception-journal privilege marker (REQ-CLS-001). A period with no
 * PeriodStatus record is treated as open (the continuous-close feature has not
 * gated it yet) so existing ledgers keep posting; this preserves the additive
 * augmentation guarantee with the sibling PeriodCloseGuard::periodOpen
 * precondition that already runs on the same transition.
 *
 * Fail-closed on any exception (CWE-863): an unexpected error denies the
 * post rather than letting a posting slip through.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the continuous-close feature.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-19
 */
class PeriodStatusGuard {
	/**
	 * Stages in which a period rejects new postings without an override (REQ-CLS-001).
	 *
	 * @var array<string>
	 */
	private const RESTRICTED_STAGES = [
		'hard-closed',
		'audited',
		'locked',
	];

	/**
	 * Stages in which only accrual reversals + corrections are allowed (REQ-CLS-001).
	 *
	 * @var array<string>
	 */
	private const RESTRICTED_TO_REVERSALS_STAGES = [
		'soft-closed',
	];

	/**
	 * Posting-kinds that are allowed under soft-closed (REQ-CLS-001).
	 *
	 * @var array<string>
	 */
	private const REVERSAL_POSTING_KINDS = [
		'accrual-reversal',
		'correction',
	];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the posting is allowed in the current PeriodStatus stage (REQ-CLS-001).
	 *
	 * Posting kind is read from `$transaction['postingKind']` (defaults to 'regular').
	 * Override flags are read from `$transaction['controllerOverride']` (bool) and
	 * `$transaction['exceptionJournal']` (bool); BOTH must be present and truthy to
	 * post into a restricted stage. soft-closed allows postings whose `postingKind`
	 * is in {accrual-reversal, correction}. Any other stage allows everything.
	 *
	 * @param array<string,mixed>|string $transaction The GLTransaction record (or its id).
	 *
	 * @return bool True when the posting may proceed.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-19
	 */
	public function postingAllowed(array|string $transaction): bool {
		try {
			$resolved = $this->resolveTransaction(transaction: $transaction);
			$periodId = (string)($resolved['periodId'] ?? '');
			if ($periodId === '') {
				return true;
			}

			$administrationId = (string)($resolved['administrationId'] ?? '');
			$status = $this->findStatus(periodId: $periodId, administrationId: $administrationId);
			if ($status === null) {
				return true;
			}

			$stage = (string)($status['stage'] ?? 'open');
			if ($stage === 'open') {
				return true;
			}

			$postingKind = (string)($resolved['postingKind'] ?? 'regular');

			if (in_array($stage, self::RESTRICTED_TO_REVERSALS_STAGES, true) === true) {
				if (in_array($postingKind, self::REVERSAL_POSTING_KINDS, true) === true) {
					return true;
				}

				$this->logger->info(
					'PeriodStatusGuard: posting rejected — soft-closed period accepts only accrual reversals + corrections',
					['periodId' => $periodId, 'stage' => $stage, 'postingKind' => $postingKind]
				);
				return false;
			}

			if (in_array($stage, self::RESTRICTED_STAGES, true) === true) {
				if ($stage === 'locked') {
					$this->logger->info(
						'PeriodStatusGuard: posting rejected — period is locked',
						['periodId' => $periodId, 'stage' => $stage]
					);
					return false;
				}

				$controllerOverride = ($resolved['controllerOverride'] ?? false) === true;
				$exceptionJournal = ($resolved['exceptionJournal'] ?? false) === true;
				if ($controllerOverride === true && $exceptionJournal === true) {
					return true;
				}

				$this->logger->info(
					'PeriodStatusGuard: posting rejected — restricted stage; controllerOverride + exceptionJournal required',
					['periodId' => $periodId, 'stage' => $stage]
				);
				return false;
			}//end if

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodStatusGuard: postingAllowed check failed — denying post (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end postingAllowed()

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
	 * Find the PeriodStatus record for a period (scoped by administration when known).
	 *
	 * @param string $periodId The period identifier (typically yyyy-mm).
	 * @param string $administrationId The administration scope ('' to skip).
	 *
	 * @return array<string,mixed>|null The PeriodStatus record, or null when none exists.
	 */
	private function findStatus(string $periodId, string $administrationId): ?array {
		// PeriodId is `${year}-${month}` per the schema; split into the two fields.
		[$year, $month] = $this->splitPeriodId(periodId: $periodId);
		if ($year === 0 || $month === 0) {
			return null;
		}

		$filters = ['periodYear' => $year, 'periodMonth' => $month];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('PeriodStatus')
			->findAll(['filters' => $filters, 'limit' => 1]);

		if ($found !== []) {
			return (array)$found[0];
		}

		return null;
	}//end findStatus()

	/**
	 * Split a yyyy-mm or yyyy-MM periodId into integer year + month.
	 *
	 * @param string $periodId The period identifier.
	 *
	 * @return array{0:int,1:int} [year, month]; [0, 0] on parse failure.
	 */
	private function splitPeriodId(string $periodId): array {
		$matched = preg_match('/^(\d{4})-(\d{1,2})$/', $periodId, $parts);
		if ($matched !== 1) {
			return [0, 0];
		}

		$year = (int)$parts[1];
		$month = (int)$parts[2];
		if ($month < 1 || $month > 12) {
			return [0, 0];
		}

		return [$year, $month];
	}//end splitPeriodId()

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
