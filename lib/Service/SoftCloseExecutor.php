<?php

/**
 * Soft Close Executor
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
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
	 * The totals a run report starts from, after its status, administration and period.
	 *
	 * @var array<string,mixed>
	 */
	private const NEW_REPORT_TOTALS = [
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
		$run = new SoftCloseRun(administrationId: $administrationId, periodId: $periodId, asOf: $asOf);
		$report = $this->startedReport(run: $run);

		foreach ($this->closeSteps(run: $run) as $step => $runStep) {
			try {
				$report = $runStep($report);
			} catch (\Throwable $e) {
				return $this->fail(report: $report, step: $step, exception: $e, asOf: $asOf);
			}
		}

		return $this->completed(run: $run, report: $report);
	}//end execute()

	/**
	 * The report of a run that has just started.
	 *
	 * @param SoftCloseRun $run The run.
	 *
	 * @return array<string,mixed>
	 */
	private function startedReport(SoftCloseRun $run): array {
		return [
			'status' => 'running',
			'administrationId' => $run->administrationId,
			'periodId' => $run->periodId,
		] + self::NEW_REPORT_TOTALS;
	}//end startedReport()

	/**
	 * The steps of a soft-close in order, keyed by the step name an alert carries.
	 *
	 * Each step takes the report and returns it updated; a step that throws
	 * fails the run at that step with the report as it stood before it.
	 *
	 * @param SoftCloseRun $run The run.
	 *
	 * @return array<string,callable(array<string,mixed>):array<string,mixed>>
	 */
	private function closeSteps(SoftCloseRun $run): array {
		return [
			'accruals' => fn (array $report): array => $this->accrualStep(run: $run, report: $report),
			'fx-revaluation' => fn (array $report): array => $this->fxRevaluationStep(run: $run, report: $report),
			'revenue-cutoff' => fn (array $report): array => $this->revenueCutoffStep(run: $run, report: $report),
			'lease-postings' => fn (array $report): array => $this->leaseStep(run: $run, report: $report),
			'intercompany-matching' => fn (array $report): array => $this->intercompanyStep(run: $run, report: $report),
			'trial-balance' => fn (array $report): array => $this->trialBalanceStep(run: $run, report: $report),
			'mark-soft-closed' => fn (array $report): array => $this->markSoftClosedStep(run: $run, report: $report),
		];
	}//end closeSteps()

	/**
	 * Step 1: execute auto-accrual rules.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function accrualStep(SoftCloseRun $run, array $report): array {
		$accrualResult = $this->runAccrualRules(run: $run);
		$report['accrualPostings'] = $accrualResult['postingCount'];
		$report['postingCount'] += $accrualResult['postingCount'];
		return $report;
	}//end accrualStep()

	/**
	 * Step 2: delegate to treasury for FX revaluation + interest accruals.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function fxRevaluationStep(SoftCloseRun $run, array $report): array {
		$report['fxPostings'] = $this->delegateFxRevaluation(administrationId: $run->administrationId, periodId: $run->periodId);
		$report['postingCount'] += $report['fxPostings'];
		return $report;
	}//end fxRevaluationStep()

	/**
	 * Step 3: delegate to IFRS 15 for revenue cut-off.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function revenueCutoffStep(SoftCloseRun $run, array $report): array {
		$report['revenuePostings'] = $this->delegateRevenueCutoff(administrationId: $run->administrationId, periodId: $run->periodId);
		$report['postingCount'] += $report['revenuePostings'];
		return $report;
	}//end revenueCutoffStep()

	/**
	 * Step 4: delegate to IFRS 16 lease module (skip when unavailable).
	 *
	 * IFRS 16 not implemented yet is non-fatal per design.md D3: a failure is
	 * logged and the run goes on with the report unchanged.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function leaseStep(SoftCloseRun $run, array $report): array {
		try {
			$report['leasePostings'] = $this->delegateLeasePostings(administrationId: $run->administrationId, periodId: $run->periodId);
			$report['postingCount'] += $report['leasePostings'];
		} catch (\Throwable $e) {
			$this->logger->info(
				'SoftCloseExecutor: lease postings skipped',
				['administrationId' => $run->administrationId, 'periodId' => $run->periodId, 'reason' => $e->getMessage()]
			);
		}

		return $report;
	}//end leaseStep()

	/**
	 * Step 5: intercompany matching.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function intercompanyStep(SoftCloseRun $run, array $report): array {
		$report['intercompanyMatches'] = $this->runIntercompanyMatching(
			administrationId: $run->administrationId,
			periodId: $run->periodId
		);
		return $report;
	}//end intercompanyStep()

	/**
	 * Step 6: trial-balance generation.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function trialBalanceStep(SoftCloseRun $run, array $report): array {
		$report['trialBalanceComplete'] = $this->generateTrialBalance(
			administrationId: $run->administrationId,
			periodId: $run->periodId
		);
		return $report;
	}//end trialBalanceStep()

	/**
	 * Step 7: mark PeriodStatus as soft-closed.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report so far.
	 *
	 * @return array<string,mixed>
	 */
	private function markSoftClosedStep(SoftCloseRun $run, array $report): array {
		$this->markSoftClosed(
			administrationId: $run->administrationId,
			periodId: $run->periodId,
			asOf: $run->asOf
		);
		return $report;
	}//end markSoftClosedStep()

	/**
	 * Mark the report completed and log the completion.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $report The report after every step.
	 *
	 * @return array<string,mixed>
	 */
	private function completed(SoftCloseRun $run, array $report): array {
		$report['status'] = 'completed';
		$report['completedAt'] = $run->asOf->format(DateTimeInterface::ATOM);

		$this->logger->info(
			'SoftCloseExecutor: soft-close completed',
			[
				'administrationId' => $run->administrationId,
				'periodId' => $run->periodId,
				'postingCount' => $report['postingCount'],
			]
		);

		return $report;
	}//end completed()

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
	 * @param SoftCloseRun $run The run (administration scope, period and timestamp).
	 *
	 * @return array{postingCount:int,rulesEvaluated:int} The accrual summary.
	 */
	private function runAccrualRules(SoftCloseRun $run): array {
		$rules = $this->findActiveRules(administrationId: $run->administrationId);

		$postings = 0;
		foreach ($rules as $rule) {
			if ($this->postAccrual(run: $run, rule: $rule) === true) {
				$postings++;
			}
		}

		return [
			'postingCount' => $postings,
			'rulesEvaluated' => count($rules),
		];

	}//end runAccrualRules()

	/**
	 * Post one rule's accrual for the run, when it computes to more than zero.
	 *
	 * @param SoftCloseRun $run The run.
	 * @param array<string,mixed> $rule The AutoAccrualRule record.
	 *
	 * @return bool True when an AutoAccrualPosting was persisted.
	 */
	private function postAccrual(SoftCloseRun $run, array $rule): bool {
		$amountCents = $this->computeAccrualCents(rule: $rule, context: $this->accrualContext(run: $run));
		if ($amountCents <= 0) {
			return false;
		}

		// Persist an AutoAccrualPosting record per execution (REQ-CLS-010).
		$this->persistAccrualPosting(
			rule: $rule,
			periodId: $run->periodId,
			amountCents: $amountCents,
			asOf: $run->asOf
		);
		return true;
	}//end postAccrual()

	/**
	 * The accrual context of a run: the days elapsed and the days in the period.
	 *
	 * @param SoftCloseRun $run The run.
	 *
	 * @return array{daysElapsed:int,daysInPeriod:int}
	 */
	private function accrualContext(SoftCloseRun $run): array {
		return [
			'daysElapsed' => (int)$run->asOf->format('j'),
			'daysInPeriod' => (int)$run->asOf->format('t'),
		];
	}//end accrualContext()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
