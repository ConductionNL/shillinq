<?php

/**
 * Period Status Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\AppIdentity;
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
			return $this->resolvedPostingAllowed(resolved: $this->resolveTransaction(transaction: $transaction));
		} catch (\Throwable $e) {
			$this->logger->error(
				'PeriodStatusGuard: postingAllowed check failed — denying post (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end postingAllowed()

	/**
	 * Whether a resolved transaction may post into its period's stage; a
	 * period without an id or a PeriodStatus record is not gated.
	 *
	 * @param array<string,mixed> $resolved The GLTransaction record.
	 *
	 * @return bool
	 */
	private function resolvedPostingAllowed(array $resolved): bool {
		$periodId = (string)($resolved['periodId'] ?? '');
		if ($periodId === '') {
			return true;
		}

		$status = $this->findStatus(periodId: $periodId, administrationId: (string)($resolved['administrationId'] ?? ''));
		if ($status === null) {
			return true;
		}

		return $this->stageAllows(stage: (string)($status['stage'] ?? 'open'), resolved: $resolved);

	}//end resolvedPostingAllowed()

	/**
	 * Whether the stage accepts the posting: open and unknown stages accept
	 * everything, soft-closed only reversals and corrections, the restricted
	 * stages only an override.
	 *
	 * @param string              $stage    The PeriodStatus stage.
	 * @param array<string,mixed> $resolved The GLTransaction record.
	 *
	 * @return bool
	 */
	private function stageAllows(string $stage, array $resolved): bool {
		if ($stage === 'open') {
			return true;
		}

		if (in_array($stage, self::RESTRICTED_TO_REVERSALS_STAGES, true) === true) {
			return $this->reversalAllowed(stage: $stage, resolved: $resolved);
		}

		if (in_array($stage, self::RESTRICTED_STAGES, true) === true) {
			return $this->overrideAllowed(stage: $stage, resolved: $resolved);
		}

		return true;

	}//end stageAllows()

	/**
	 * Soft-closed: only accrual reversals and corrections post.
	 *
	 * @param string              $stage    The PeriodStatus stage.
	 * @param array<string,mixed> $resolved The GLTransaction record.
	 *
	 * @return bool
	 */
	private function reversalAllowed(string $stage, array $resolved): bool {
		$postingKind = (string)($resolved['postingKind'] ?? 'regular');
		if (in_array($postingKind, self::REVERSAL_POSTING_KINDS, true) === true) {
			return true;
		}

		return $this->refuse(
			message: 'PeriodStatusGuard: posting rejected — soft-closed period accepts only accrual reversals + corrections',
			context: ['periodId' => (string)($resolved['periodId'] ?? ''), 'stage' => $stage, 'postingKind' => $postingKind]
		);

	}//end reversalAllowed()

	/**
	 * A restricted stage: a locked period refuses everything; the others
	 * accept a posting carrying both controllerOverride and exceptionJournal.
	 *
	 * @param string              $stage    The PeriodStatus stage.
	 * @param array<string,mixed> $resolved The GLTransaction record.
	 *
	 * @return bool
	 */
	private function overrideAllowed(string $stage, array $resolved): bool {
		$context = ['periodId' => (string)($resolved['periodId'] ?? ''), 'stage' => $stage];
		if ($stage === 'locked') {
			return $this->refuse(message: 'PeriodStatusGuard: posting rejected — period is locked', context: $context);
		}

		if (($resolved['controllerOverride'] ?? false) === true && ($resolved['exceptionJournal'] ?? false) === true) {
			return true;
		}

		return $this->refuse(
			message: 'PeriodStatusGuard: posting rejected — restricted stage; controllerOverride + exceptionJournal required',
			context: $context
		);

	}//end overrideAllowed()

	/**
	 * Log a refused posting and refuse it.
	 *
	 * @param string              $message The refusal log line.
	 * @param array<string,mixed> $context The log context.
	 *
	 * @return bool Always false.
	 */
	private function refuse(string $message, array $context): bool {
		$this->logger->info($message, $context);
		return false;

	}//end refuse()

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
	private function findStatus(string $periodId, string $administrationId): array|null {
		$filters = $this->statusFilters(periodId: $periodId, administrationId: $administrationId);
		if ($filters === null) {
			return null;
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
	 * The PeriodStatus filters for a period, scoped by administration when
	 * known, or null when the period id does not parse.
	 *
	 * @param string $periodId         The period identifier (typically yyyy-mm).
	 * @param string $administrationId The administration scope ('' to skip).
	 *
	 * @return array<string,int|string>|null
	 */
	private function statusFilters(string $periodId, string $administrationId): array|null {
		// PeriodId is `${year}-${month}` per the schema; split into the two fields.
		[$year, $month] = $this->splitPeriodId(periodId: $periodId);
		if ($year === 0 || $month === 0) {
			return null;
		}

		$filters = ['periodYear' => $year, 'periodMonth' => $month];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		return $filters;

	}//end statusFilters()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
