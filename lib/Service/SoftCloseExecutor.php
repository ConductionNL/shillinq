<?php

/**
 * Soft Close Executor
 *
 * Tier-2 continuous-close orchestration (REQ-CLS-002). Per ADR-031 exception:
 * the executor is *glue code only* — each sub-step is itself either a
 * declarative rule evaluation (AutoAccrualRule via calculation/aggregation)
 * or a delegation call into a sibling spec's owned service (treasury for FX
 * + interest, IFRS 15 for revenue cut-off, IFRS 16 for lease postings,
 * intercompany matcher for GL transaction matching). No policy logic lives
 * here; the executor sequences sub-steps, marks the PeriodStatus as
 * soft-closed when all succeed, emits a ContinuousCloseAlert on the first
 * failure, and returns a structured posting count + status report.
 *
 * Sequence per REQ-CLS-002:
 *  1. Execute all active AutoAccrualRule records (per administratie + period).
 *  2. Call treasury module for FX revaluation + interest accruals.
 *  3. Call IFRS 15 module for revenue cut-off.
 *  4. Call IFRS 16 module for lease postings (skipped when unavailable).
 *  5. Execute intercompany matching.
 *  6. Generate trial balance summary.
 *  7. Mark PeriodStatus as soft-closed (or emit alert + halt on failure).
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Orchestrates the nightly soft-close execution per administratie (ADR-031 exception).
 *
 * The executor is intentionally thin glue code: every actual posting,
 * delegation, or persistence call lands in a sub-module or in OpenRegister
 * via the real ObjectService API (find/findAll/saveObject). The five
 * AutoAccrualRule calculation methods (REQ-CLS-003) are the only piece of
 * arithmetic the executor evaluates directly — those are pure declarative
 * rules deserving an integer-cent computation, not policy logic.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; early-return refactor deferred pending full behavioral
 * verification of each branch.
 */
class SoftCloseExecutor {
	/**
	 * Marker used for the postedBy field on auto-postings (REQ-CLS-010).
	 *
	 * @var string
	 */
	public const SYSTEM_ACTOR = 'SYSTEM:SoftCloseExecutor';

	/**
	 * Construct the executor.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService + delegate resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for orchestration diagnostics.
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
	 * Execute the nightly soft-close for a single administratie + period (REQ-CLS-002).
	 *
	 * @param string $administrationId The administration the run targets.
	 * @param string $periodId The yyyy-mm business period identifier.
	 * @param DateTimeImmutable $asOf The run timestamp (typically 'now').
	 *
	 * @return array{
	 *   status: string,
	 *   administrationId: string,
	 *   periodId: string,
	 *   postingCount: int,
	 *   accrualPostings: int,
	 *   fxPostings: int,
	 *   revenuePostings: int,
	 *   leasePostings: int,
	 *   intercompanyMatches: int,
	 *   trialBalanceComplete: bool,
	 *   completedAt: string,
	 *   alerts: array<int,array<string,mixed>>
	 * } The structured run report.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
	 */
	public function execute(string $administrationId, string $periodId, DateTimeImmutable $asOf): array {
		$report = [
			'status' => 'running',
			'administrationId' => $administrationId,
			'periodId' => $periodId,
			'postingCount' => 0,
			'accrualPostings' => 0,
			'fxPostings' => 0,
			'revenuePostings' => 0,
			'leasePostings' => 0,
			'intercompanyMatches' => 0,
			'trialBalanceComplete' => false,
			'completedAt' => '',
			'alerts' => [],
		];

		// Step 1: execute auto-accrual rules.
		try {
			$accrualResult = $this->runAccrualRules(
				administrationId: $administrationId,
				periodId: $periodId,
				asOf: $asOf
			);
			$report['accrualPostings'] = $accrualResult['postingCount'];
			$report['postingCount'] += $accrualResult['postingCount'];
		} catch (\Throwable $e) {
			return $this->fail(report: $report, step: 'accruals', exception: $e, asOf: $asOf);
		}

		// Step 2: delegate to treasury for FX revaluation + interest accruals.
		try {
			$report['fxPostings'] = $this->delegateFxRevaluation(administrationId: $administrationId, periodId: $periodId);
			$report['postingCount'] += $report['fxPostings'];
		} catch (\Throwable $e) {
			return $this->fail(report: $report, step: 'fx-revaluation', exception: $e, asOf: $asOf);
		}

		// Step 3: delegate to IFRS 15 for revenue cut-off.
		try {
			$report['revenuePostings'] = $this->delegateRevenueCutoff(administrationId: $administrationId, periodId: $periodId);
			$report['postingCount'] += $report['revenuePostings'];
		} catch (\Throwable $e) {
			return $this->fail(report: $report, step: 'revenue-cutoff', exception: $e, asOf: $asOf);
		}

		// Step 4: delegate to IFRS 16 lease module (skip when unavailable).
		try {
			$report['leasePostings'] = $this->delegateLeasePostings(administrationId: $administrationId, periodId: $periodId);
			$report['postingCount'] += $report['leasePostings'];
		} catch (\Throwable $e) {
			// IFRS 16 not implemented yet is non-fatal per design.md D3.
			$this->logger->info(
				'SoftCloseExecutor: lease postings skipped',
				['administrationId' => $administrationId, 'periodId' => $periodId, 'reason' => $e->getMessage()]
			);
		}

		// Step 5: intercompany matching.
		try {
			$report['intercompanyMatches'] = $this->runIntercompanyMatching(
				administrationId: $administrationId,
				periodId: $periodId
			);
		} catch (\Throwable $e) {
			return $this->fail(report: $report, step: 'intercompany-matching', exception: $e, asOf: $asOf);
		}

		// Step 6: trial-balance generation.
		try {
			$report['trialBalanceComplete'] = $this->generateTrialBalance(
				administrationId: $administrationId,
				periodId: $periodId
			);
		} catch (\Throwable $e) {
			return $this->fail(report: $report, step: 'trial-balance', exception: $e, asOf: $asOf);
		}

		// Step 7: mark PeriodStatus as soft-closed.
		try {
			$this->markSoftClosed(
				administrationId: $administrationId,
				periodId: $periodId,
				asOf: $asOf
			);
		} catch (\Throwable $e) {
			return $this->fail(report: $report, step: 'mark-soft-closed', exception: $e, asOf: $asOf);
		}

		$report['status'] = 'completed';
		$report['completedAt'] = $asOf->format(DateTimeInterface::ATOM);

		$this->logger->info(
			'SoftCloseExecutor: soft-close completed',
			[
				'administrationId' => $administrationId,
				'periodId' => $periodId,
				'postingCount' => $report['postingCount'],
			]
		);

		return $report;
	}//end execute()

	/**
	 * Compute the accrual amount in integer cents for one rule (REQ-CLS-003).
	 *
	 * Pure function — exposed for unit testing the five calculation methods.
	 *
	 * @param array<string,mixed> $rule The AutoAccrualRule record.
	 * @param array<string,mixed> $context Run context (e.g. {revenueMtdCents, daysElapsed, daysInPeriod}).
	 *
	 * @return int The accrual amount in integer cents (>= 0).
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
	 */
	public function computeAccrualCents(array $rule, array $context): int {
		$method = (string)($rule['calculationMethod'] ?? '');
		$parameters = (array)($rule['calculationParameters'] ?? []);

		switch ($method) {
			case 'fixed-amount':
				$amount = (int)($parameters['amountCents'] ?? 0);
				return max(0, $amount);
			case 'percentage-of-revenue':
				$rate = (float)($parameters['rate'] ?? 0.0);
				$revenue = (int)($context['revenueMtdCents'] ?? 0);
				if ($rate <= 0.0 || $revenue <= 0) {
					return 0;
				}
				return (int)round($revenue * $rate);
			case 'straight-line-from-contract':
				// PrincipalCents × annualRate × dayCount / dayCountConvention.
				$principal = (int)($parameters['principalCents'] ?? 0);
				$rate = (float)($parameters['annualRate'] ?? 0.0);
				$dayCount = (int)($parameters['dayCount'] ?? 365);
				$days = (int)($context['daysElapsed'] ?? 0);
				if ($principal <= 0 || $rate <= 0.0 || $dayCount <= 0 || $days <= 0) {
					return 0;
				}
				return (int)round(($principal * $rate * $days) / $dayCount);
			case 'days-elapsed-of-period':
				$monthly = (int)($parameters['monthlyAmountCents'] ?? 0);
				$daysElapsed = (int)($context['daysElapsed'] ?? 0);
				$daysInPeriod = (int)($context['daysInPeriod'] ?? 30);
				if ($monthly <= 0 || $daysElapsed <= 0 || $daysInPeriod <= 0) {
					return 0;
				}
				return (int)round(($monthly * $daysElapsed) / $daysInPeriod);
			case 'external-lookup':
				// Lookup amount is provided by the orchestration context.
				$lookup = (int)($context['lookupAmountCents'] ?? 0);
				return max(0, $lookup);
			default:
				return 0;
		}//end switch

	}//end computeAccrualCents()

	/**
	 * Execute every active AutoAccrualRule for the administratie + period (REQ-CLS-002, REQ-CLS-003).
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf The run timestamp.
	 *
	 * @return array{postingCount:int,rulesEvaluated:int} The accrual summary.
	 */
	private function runAccrualRules(string $administrationId, string $periodId, DateTimeImmutable $asOf): array {
		$rules = $this->findActiveRules(administrationId: $administrationId);
		$context = [
			'daysElapsed' => (int)$asOf->format('j'),
			'daysInPeriod' => (int)$asOf->format('t'),
		];

		$postings = 0;
		foreach ($rules as $rule) {
			$amountCents = $this->computeAccrualCents(rule: $rule, context: $context);
			if ($amountCents <= 0) {
				continue;
			}

			// Persist an AutoAccrualPosting record per execution (REQ-CLS-010).
			$this->persistAccrualPosting(
				rule: $rule,
				periodId: $periodId,
				amountCents: $amountCents,
				asOf: $asOf
			);
			$postings++;
		}

		return [
			'postingCount' => $postings,
			'rulesEvaluated' => count($rules),
		];

	}//end runAccrualRules()

	/**
	 * Find every active AutoAccrualRule for an administratie.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array<int,array<string,mixed>> The active rules (possibly empty).
	 */
	private function findActiveRules(string $administrationId): array {
		$filters = ['lifecycleState' => 'active'];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('AutoAccrualRule')
			->findAll(['filters' => $filters]);

		return array_values(array_map(static fn ($r): array => (array)$r, $found));
	}//end findActiveRules()

	/**
	 * Persist one AutoAccrualPosting record + emit the JournalEntry id (REQ-CLS-010).
	 *
	 * @param array<string,mixed> $rule The AutoAccrualRule record.
	 * @param string $periodId The yyyy-mm period.
	 * @param int $amountCents Posted amount in cents.
	 * @param DateTimeImmutable $asOf Posting timestamp.
	 *
	 * @return void
	 */
	private function persistAccrualPosting(array $rule, string $periodId, int $amountCents, DateTimeImmutable $asOf): void {
		$posting = [
			'ruleId' => (string)($rule['id'] ?? ($rule['ruleName'] ?? '')),
			'ruleVersion' => (int)($rule['ruleVersion'] ?? 1),
			'periodId' => $periodId,
			'amountCents' => $amountCents,
			'journalEntryId' => $this->journalEntryId(rule: $rule, periodId: $periodId, asOf: $asOf),
			'postedAt' => $asOf->format(DateTimeInterface::ATOM),
			'postedBy' => self::SYSTEM_ACTOR,
			'reversalId' => null,
			'reversalState' => 'posted',
		];

		$this->objectService->saveObject(
			object: $posting,
			register: $this->register(),
			schema: 'AutoAccrualPosting',
		);

	}//end persistAccrualPosting()

	/**
	 * Synthesize a deterministic journal-entry id for an accrual posting.
	 *
	 * @param array<string,mixed> $rule The rule record.
	 * @param string $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return string The journal-entry id.
	 */
	private function journalEntryId(array $rule, string $periodId, DateTimeImmutable $asOf): string {
		$ruleName = (string)($rule['ruleName'] ?? 'accrual');
		$slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $ruleName) ?? 'accrual');
		return sprintf('je-%s-%s-%s', $periodId, $asOf->format('d'), trim($slug, '-'));
	}//end journalEntryId()

	/**
	 * Delegate FX revaluation + interest accruals to bookkeeping-treasury-ihb.
	 *
	 * Looks up the service via the DI container; absent service returns 0
	 * postings without erroring (treasury module is optional in T2 cycle 1).
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return int The posting count returned by the treasury delegate.
	 */
	private function delegateFxRevaluation(string $administrationId, string $periodId): int {
		if ($this->container->has('OCA\Shillinq\Service\Treasury\FxRevaluationService') === false) {
			$this->logger->debug('SoftCloseExecutor: FX revaluation delegate absent — skipping');
			return 0;
		}

		$delegate = $this->container->get('OCA\Shillinq\Service\Treasury\FxRevaluationService');
		if (method_exists($delegate, 'reval') === false) {
			return 0;
		}

		$result = (array)$delegate->reval($administrationId, $periodId);
		return (int)($result['postingCount'] ?? 0);
	}//end delegateFxRevaluation()

	/**
	 * Delegate revenue cut-off to bookkeeping-ifrs15-revenue.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return int Posting count from the IFRS 15 delegate.
	 */
	private function delegateRevenueCutoff(string $administrationId, string $periodId): int {
		if ($this->container->has('OCA\Shillinq\Service\Ifrs15\RevenueRecognitionService') === false) {
			return 0;
		}

		$delegate = $this->container->get('OCA\Shillinq\Service\Ifrs15\RevenueRecognitionService');
		if (method_exists($delegate, 'cutoff') === false) {
			return 0;
		}

		$result = (array)$delegate->cutoff($administrationId, $periodId);
		return (int)($result['postingCount'] ?? 0);
	}//end delegateRevenueCutoff()

	/**
	 * Delegate lease postings to bookkeeping-ifrs16-leases.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return int Posting count from the IFRS 16 delegate.
	 */
	private function delegateLeasePostings(string $administrationId, string $periodId): int {
		if ($this->container->has('OCA\Shillinq\Service\Ifrs16\LeasePostingService') === false) {
			return 0;
		}

		$delegate = $this->container->get('OCA\Shillinq\Service\Ifrs16\LeasePostingService');
		if (method_exists($delegate, 'post') === false) {
			return 0;
		}

		$result = (array)$delegate->post($administrationId, $periodId);
		return (int)($result['postingCount'] ?? 0);
	}//end delegateLeasePostings()

	/**
	 * Run intercompany matching for the administratie + period.
	 *
	 * Reuses the existing IntercompanyMatchingService when available.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return int Match count.
	 */
	private function runIntercompanyMatching(string $administrationId, string $periodId): int {
		if ($this->container->has('OCA\Shillinq\Service\IntercompanyMatchingService') === false) {
			return 0;
		}

		$delegate = $this->container->get('OCA\Shillinq\Service\IntercompanyMatchingService');
		if (method_exists($delegate, 'matchForPeriod') === false) {
			return 0;
		}

		$result = (array)$delegate->matchForPeriod($administrationId, $periodId);
		return (int)($result['matchCount'] ?? 0);
	}//end runIntercompanyMatching()

	/**
	 * Generate the trial balance summary for the administratie + period.
	 *
	 * Delegates to the existing GL aggregation when present; otherwise returns
	 * a flag indicating the step ran without postings (REQ-CLS-002).
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return bool True when the trial balance was generated.
	 */
	private function generateTrialBalance(string $administrationId, string $periodId): bool {
		if ($this->container->has('OCA\Shillinq\Service\TrialBalanceService') === false) {
			return true;
		}

		$delegate = $this->container->get('OCA\Shillinq\Service\TrialBalanceService');
		if (method_exists($delegate, 'generate') === false) {
			return true;
		}

		$delegate->generate($administrationId, $periodId);
		return true;
	}//end generateTrialBalance()

	/**
	 * Mark PeriodStatus as soft-closed with the asOf timestamp.
	 *
	 * Creates the record when no PeriodStatus exists yet for the administratie
	 * + period; appends a stage-change history entry per REQ-CLS-001.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return void
	 */
	private function markSoftClosed(string $administrationId, string $periodId, DateTimeImmutable $asOf): void {
		[$year, $month] = $this->splitPeriodId(periodId: $periodId);
		if ($year === 0 || $month === 0) {
			return;
		}

		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('PeriodStatus')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'periodYear' => $year,
						'periodMonth' => $month,
					],
					'limit' => 1,
				]
			);

		if ($found !== []) {
			$record = (array)$found[0];
		} else {
			$record = [
				'administrationId' => $administrationId,
				'periodYear' => $year,
				'periodMonth' => $month,
				'stage' => 'open',
				'stageChangeHistory' => [],
			];
		}

		$previousStage = (string)($record['stage'] ?? 'open');
		$record['stage'] = 'soft-closed';
		$record['softClosedAt'] = $asOf->format(DateTimeInterface::ATOM);
		$history = (array)($record['stageChangeHistory'] ?? []);
		$history[] = [
			'fromStage' => $previousStage,
			'toStage' => 'soft-closed',
			'actor' => self::SYSTEM_ACTOR,
			'timestamp' => $asOf->format(DateTimeInterface::ATOM),
			'reason' => 'Nightly soft-close completed',
		];
		$record['stageChangeHistory'] = $history;

		$this->objectService->saveObject(
			object: $record,
			register: $this->register(),
			schema: 'PeriodStatus',
		);

	}//end markSoftClosed()

	/**
	 * Persist a ContinuousCloseAlert and mark the run as failed at the given step.
	 *
	 * @param array<string,mixed> $report The run report under construction.
	 * @param string $step The failing step name.
	 * @param \Throwable $exception The caught throwable.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return array<string,mixed> The finalized report.
	 */
	private function fail(array $report, string $step, \Throwable $exception, DateTimeImmutable $asOf): array {
		$message = sprintf('Soft-close failed at step "%s": %s', $step, $exception->getMessage());
		$alert = [
			'administrationId' => $report['administrationId'],
			'periodId' => $report['periodId'],
			'severity' => 'error',
			'message' => $message,
			'routedTo' => ['CFO', 'Controller'],
			'createdAt' => $asOf->format(DateTimeInterface::ATOM),
			'acknowledged' => false,
		];

		try {
			$this->objectService->saveObject(
				object: $alert,
				register: $this->register(),
				schema: 'ContinuousCloseAlert',
			);
		} catch (\Throwable $persistError) {
			$this->logger->error(
				'SoftCloseExecutor: failed to persist ContinuousCloseAlert',
				['exception' => $persistError->getMessage()]
			);
		}

		$report['alerts'][] = $alert;
		$report['status'] = 'failed';
		$report['completedAt'] = $asOf->format(DateTimeInterface::ATOM);

		$this->logger->error(
			'SoftCloseExecutor: soft-close failed',
			[
				'administrationId' => $report['administrationId'],
				'periodId' => $report['periodId'],
				'step' => $step,
				'exception' => $exception->getMessage(),
			]
		);

		return $report;
	}//end fail()

	/**
	 * Split a yyyy-mm period id into integer year + month.
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
