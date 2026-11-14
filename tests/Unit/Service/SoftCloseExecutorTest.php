<?php

/**
 * Unit tests for SoftCloseExecutor — accrual calculation methods.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Validates the five accrual calculation methods on SoftCloseExecutor (REQ-CLS-003)
 * plus the FX-delegate correctness fix (fx-period-end-revaluation).
 *
 * Calculations are pure functions — no DI required beyond a stub container +
 * stub config — they only consume the rule + run context.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SoftCloseExecutorTest extends TestCase {
	/**
	 * Build an executor with stub DI.
	 *
	 * @return SoftCloseExecutor
	 */
	private function executor(): SoftCloseExecutor {
		$container = $this->createStub(ContainerInterface::class);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		return new SoftCloseExecutor($container, $config, new NullLogger(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end executor()

	/**
	 * Fixed amount accrual: 12K EUR rent = 1,200,000 cents.
	 *
	 * @return void
	 */
	public function testFixedAmountAccrualReturnsConfiguredCents(): void {
		$e = $this->executor();
		$rule = ['calculationMethod' => 'fixed-amount', 'calculationParameters' => ['amountCents' => 1200000]];
		self::assertSame(1200000, $e->computeAccrualCents(rule: $rule, context: []));

	}//end testFixedAmountAccrualReturnsConfiguredCents()

	/**
	 * Percentage-of-revenue: 3% of EUR 450,000 MTD revenue = EUR 13,500.
	 *
	 * @return void
	 */
	public function testPercentageOfRevenueAccrual(): void {
		$e = $this->executor();
		$rule = ['calculationMethod' => 'percentage-of-revenue', 'calculationParameters' => ['rate' => 0.03, 'sourceField' => 'revenue_mtd']];
		$context = ['revenueMtdCents' => 45000000];
		self::assertSame(1350000, $e->computeAccrualCents(rule: $rule, context: $context));

	}//end testPercentageOfRevenueAccrual()

	/**
	 * Straight-line-from-contract: EUR 100K loan × 5% annual / 365 days × 17 days ≈ EUR 232.88.
	 *
	 * @return void
	 */
	public function testStraightLineFromContractAccrual(): void {
		$e = $this->executor();
		$rule = [
			'calculationMethod' => 'straight-line-from-contract',
			'calculationParameters' => ['principalCents' => 10000000, 'annualRate' => 0.05, 'dayCount' => 365],
		];
		$context = ['daysElapsed' => 17];
		// 10000000 × 0.05 × 17 / 365 = 23287.67 -> round = 23288 cents.
		self::assertSame(23288, $e->computeAccrualCents(rule: $rule, context: $context));

	}//end testStraightLineFromContractAccrual()

	/**
	 * Days-elapsed: monthly 12K, 17 days of 31 = 6,580 EUR.
	 *
	 * @return void
	 */
	public function testDaysElapsedOfPeriodAccrual(): void {
		$e = $this->executor();
		$rule = ['calculationMethod' => 'days-elapsed-of-period', 'calculationParameters' => ['monthlyAmountCents' => 1200000]];
		$context = ['daysElapsed' => 17, 'daysInPeriod' => 31];
		// 1200000 × 17 / 31 = 658064.5 → 658065 cents.
		self::assertSame(658065, $e->computeAccrualCents(rule: $rule, context: $context));

	}//end testDaysElapsedOfPeriodAccrual()

	/**
	 * External-lookup: amount injected via run context.
	 *
	 * @return void
	 */
	public function testExternalLookupAccrualReadsContext(): void {
		$e = $this->executor();
		$rule = ['calculationMethod' => 'external-lookup', 'calculationParameters' => ['source' => 'payroll-calendar']];
		$context = ['lookupAmountCents' => 820000];
		self::assertSame(820000, $e->computeAccrualCents(rule: $rule, context: $context));

	}//end testExternalLookupAccrualReadsContext()

	/**
	 * Unknown calculation method returns 0 cents (fail-closed).
	 *
	 * @return void
	 */
	public function testUnknownMethodReturnsZero(): void {
		$e = $this->executor();
		self::assertSame(0, $e->computeAccrualCents(rule: ['calculationMethod' => 'bogus'], context: []));

	}//end testUnknownMethodReturnsZero()

	/**
	 * Negative parameters are clamped to 0 (REQ-CLS-003 — no negative accruals).
	 *
	 * @return void
	 */
	public function testNegativeFixedAmountClampsToZero(): void {
		$e = $this->executor();
		$rule = ['calculationMethod' => 'fixed-amount', 'calculationParameters' => ['amountCents' => -500]];
		self::assertSame(0, $e->computeAccrualCents(rule: $rule, context: []));

	}//end testNegativeFixedAmountClampsToZero()

	/**
	 * Build a fake OpenRegister ObjectService supporting the fluent
	 * setRegister/setSchema/findAll/saveObject shape `SoftCloseExecutor`
	 * consumes for every step (accruals, PeriodStatus, alerts). Every
	 * `findAll()` returns empty (no accrual rules, no prior PeriodStatus)
	 * so `execute()` can run all the way to `status: completed` and
	 * `fxPostings` reflects only the FX delegate's contribution.
	 *
	 * @return object
	 */
	private function fakeObjectService(): object {
		return new class {
			/**
			 * Objects saved during the run, keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Fluent register selector (no-op fake).
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector (no-op fake).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * Always empty — no accrual rules, no prior PeriodStatus.
			 *
			 * @param array<string,mixed> $options Query options (ignored).
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $options): array {
				return [];
			}//end findAll()

			/**
			 * Record the saved object under its schema.
			 *
			 * @param array<string,mixed> $object Object payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->saved[$schema][] = $object;
				return $object;
			}//end saveObject()
		};

	}//end fakeObjectService()

	/**
	 * Correctness fix proof (fx-period-end-revaluation): before this
	 * change, `container->has('OCA\Shillinq\Service\Treasury\FxRevaluationService')`
	 * was unconditionally `false` because the class did not exist, so
	 * `delegateFxRevaluation()` always returned 0 and
	 * `execute()['fxPostings']` was permanently 0. With the delegate now
	 * bound and resolving a material posting, `fxPostings` MUST be > 0.
	 *
	 * @return void
	 */
	public function testExecuteReportsNonZeroFxPostingsWhenDelegatePresent(): void {
		$objectService = $this->fakeObjectService();
		$fxDelegate = new class {
			/**
			 * Fake FxRevaluationService::reval() returning a fixed material posting.
			 *
			 * @param string $administrationId The administration scope.
			 * @param string $periodId The yyyy-mm period.
			 *
			 * @return array<string,mixed>
			 */
			public function reval(string $administrationId, string $periodId): array {
				return [
					'postingCount' => 2,
					'positionsEvaluated' => 2,
					'functionalCurrency' => 'EUR',
					'periodId' => $periodId,
				];
			}//end reval()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => $id === 'OCA\Shillinq\Service\Treasury\FxRevaluationService'
		);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $fxDelegate): object {
				if ($id === 'OCA\Shillinq\Service\Treasury\FxRevaluationService') {
					return $fxDelegate;
				}

				return $objectService;
			}
		);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		$executor = new SoftCloseExecutor($container, $config, new NullLogger(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$report = $executor->execute(
			administrationId: 'adm-holding-nl',
			periodId: '2026-03',
			asOf: new DateTimeImmutable('2026-03-31T23:30:00+02:00')
		);

		self::assertSame('completed', $report['status']);
		self::assertSame(
			2,
			$report['fxPostings'],
			'fxPostings must reflect the delegate postingCount — was unconditionally 0 before FxRevaluationService existed'
		);
		self::assertGreaterThan(0, $report['fxPostings']);
		self::assertGreaterThanOrEqual($report['fxPostings'], $report['postingCount']);

	}//end testExecuteReportsNonZeroFxPostingsWhenDelegatePresent()

	/**
	 * Companion regression check: with no delegate bound (the pre-fix
	 * state — `container->has()` false for every optional delegate),
	 * `fxPostings` stays exactly 0 and the run still completes. Documents
	 * the exact behaviour this change replaces.
	 *
	 * @return void
	 */
	public function testExecuteReportsZeroFxPostingsWhenDelegateAbsent(): void {
		$objectService = $this->fakeObjectService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->method('get')->willReturn($objectService);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		$executor = new SoftCloseExecutor($container, $config, new NullLogger(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$report = $executor->execute(
			administrationId: 'adm-holding-nl',
			periodId: '2026-03',
			asOf: new DateTimeImmutable('2026-03-31T23:30:00+02:00')
		);

		self::assertSame('completed', $report['status']);
		self::assertSame(0, $report['fxPostings']);

	}//end testExecuteReportsZeroFxPostingsWhenDelegateAbsent()

	/**
	 * Build an executor over a store that returns the given accrual rules and
	 * a container that serves the given delegates.
	 *
	 * @param array<int,array<string,mixed>> $rules The active AutoAccrualRule rows.
	 * @param array<string,object> $delegates Delegates keyed by container id.
	 *
	 * @return array{0: SoftCloseExecutor, 1: object, 2: AbstractLogger}
	 */
	private function executorWith(array $rules, array $delegates): array {
		$store = new class($rules) {

			/**
			 * Saved rows keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * The schema being addressed.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Hold the rules.
			 *
			 * @param array<int,array<string,mixed>> $rules The rules.
			 */
			public function __construct(private array $rules) {
			}//end __construct()

			/**
			 * Address a register.
			 *
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister($register): self {
				return $this;
			}//end setRegister()

			/**
			 * Address a schema.
			 *
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema($schema): self {
				$this->schema = (string)$schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows: the rules for AutoAccrualRule, nothing else.
			 *
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				if ($this->schema === 'AutoAccrualRule') {
					return $this->rules;
				}

				if ($this->schema === 'IntercompanyRelation') {
					// Five relations, so the matching step has five to match (ledger-group-close REQ-ICE-011).
					return array_map(static fn (int $n): array => ['relationId' => 'rel-' . $n], range(1, 5));
				}

				return [];
			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param array<string,mixed> $object The row.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				$this->saved[(string)($schema ?? $this->schema)][] = $object;
				return $object;
			}//end saveObject()
		};
		$logger = new class extends AbstractLogger {

			/**
			 * Every logged line as [level, message, context].
			 *
			 * @var array<int,array{0:mixed,1:string,2:array<mixed>}>
			 */
			public array $lines = [];

			/**
			 * Record one log line.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}//end log()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => isset($delegates[$id]));
		$container->method('get')->willReturnCallback(static fn (string $id): object => $delegates[$id]);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		$executor = new SoftCloseExecutor($container, $config, $logger, objectService: new DuckObjectServiceAdapter($store));
		return [$executor, $store, $logger];

	}//end executorWith()

	/**
	 * A delegate whose one method throws.
	 *
	 * @param string $method The delegate method.
	 * @param string $message The failure message.
	 *
	 * @return object
	 */
	private function throwing(string $method, string $message): object {
		$delegates = [
			'reval' => new class($message) {
				/**
				 * Hold the failure message.
				 *
				 * @param string $message The message.
				 */
				public function __construct(private string $message) {
				}//end __construct()

				/**
				 * Fail.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function reval(string $administrationId, string $periodId): array {
					throw new \RuntimeException($this->message);
				}//end reval()
			},
			'cutoff' => new class($message) {
				/**
				 * Hold the failure message.
				 *
				 * @param string $message The message.
				 */
				public function __construct(private string $message) {
				}//end __construct()

				/**
				 * Fail.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function cutoff(string $administrationId, string $periodId): array {
					throw new \RuntimeException($this->message);
				}//end cutoff()
			},
			'post' => new class($message) {
				/**
				 * Hold the failure message.
				 *
				 * @param string $message The message.
				 */
				public function __construct(private string $message) {
				}//end __construct()

				/**
				 * Fail.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function post(string $administrationId, string $periodId): array {
					throw new \RuntimeException($this->message);
				}//end post()
			},
			'matchRelationPeriod' => new class($message) {
				/**
				 * Hold the failure message.
				 *
				 * @param string $message The message.
				 */
				public function __construct(private string $message) {
				}//end __construct()

				/**
				 * Fail.
				 *
				 * @param string $relationId The relation.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function matchRelationPeriod(string $relationId, string $periodId): array {
					throw new \RuntimeException($this->message);
				}//end matchRelationPeriod()
			},
			'generate' => new class($message) {
				/**
				 * Hold the failure message.
				 *
				 * @param string $message The message.
				 */
				public function __construct(private string $message) {
				}//end __construct()

				/**
				 * Fail.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function generate(string $administrationId, string $periodId): array {
					throw new \RuntimeException($this->message);
				}//end generate()
			},
		];

		return $delegates[$method];

	}//end throwing()

	/**
	 * The delegates of a full run keyed by container id.
	 *
	 * @return array<string,object>
	 */
	private function allDelegates(): array {
		return [
			'OCA\Shillinq\Service\Treasury\FxRevaluationService' => new class {
				/**
				 * Revalue.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function reval(string $administrationId, string $periodId): array {
					return ['postingCount' => 2];
				}//end reval()
			},
			'OCA\Shillinq\Service\Ifrs15\RevenueRecognitionService' => new class {
				/**
				 * Cut off.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function cutoff(string $administrationId, string $periodId): array {
					return ['postingCount' => 3];
				}//end cutoff()
			},
			'OCA\Shillinq\Service\Ifrs16\LeasePostingService' => new class {
				/**
				 * Post.
				 *
				 * @param string $administrationId The administration.
				 * @param string $periodId The period.
				 *
				 * @return array<string,int>
				 */
				public function post(string $administrationId, string $periodId): array {
					return ['postingCount' => 4];
				}//end post()
			},
			'OCA\Shillinq\Service\IntercompanyMatchingService' => new class {
				/**
				 * Match one relation, within tolerance.
				 *
				 * @param string $relationId The relation.
				 * @param string $periodId The period.
				 *
				 * @return array<string,string>
				 */
				public function matchRelationPeriod(string $relationId, string $periodId): array {
					return ['relationId' => $relationId, 'matchStatus' => 'perfect-match'];
				}//end matchRelationPeriod()
			},
		];

	}//end allDelegates()

	/**
	 * Pins the whole report, the accrual postings, the period status and the
	 * completion log of a full run.
	 *
	 * @return void
	 */
	public function testACompleteRunReportsEveryStepAndWritesItsRecords(): void {
		$rules = [
			['id' => 'r-1', 'ruleName' => 'Huur', 'calculationMethod' => 'fixed-amount', 'calculationParameters' => ['amountCents' => 1500]],
			['id' => 'r-2', 'ruleName' => 'Nul', 'calculationMethod' => 'fixed-amount', 'calculationParameters' => ['amountCents' => 0]],
			[
				'id' => 'r-3',
				'ruleName' => 'Rente dag',
				'ruleVersion' => 2,
				'calculationMethod' => 'days-elapsed-of-period',
				'calculationParameters' => ['monthlyAmountCents' => 3100],
			],
		];
		[$executor, $store, $logger] = $this->executorWith(rules: $rules, delegates: $this->allDelegates());

		$report = $executor->execute(administrationId: 'adm-1', periodId: '2026-03', asOf: new DateTimeImmutable('2026-03-15T10:00:00+00:00'));

		self::assertSame(
			[
				'status' => 'completed',
				'administrationId' => 'adm-1',
				'periodId' => '2026-03',
				'postingCount' => 11,
				'accrualPostings' => 2,
				'fxPostings' => 2,
				'revenuePostings' => 3,
				'leasePostings' => 4,
				'intercompanyMatches' => 5,
				'trialBalanceComplete' => true,
				'completedAt' => '2026-03-15T10:00:00+00:00',
				'alerts' => [],
			],
			$report
		);
		$posting = static fn (string $ruleId, int $version, string $journalEntryId): array => [
			'ruleId' => $ruleId,
			'ruleVersion' => $version,
			'periodId' => '2026-03',
			'amountCents' => 1500,
			'journalEntryId' => $journalEntryId,
			'postedAt' => '2026-03-15T10:00:00+00:00',
			'postedBy' => 'SYSTEM:SoftCloseExecutor',
			'reversalId' => null,
			'reversalState' => 'posted',
		];
		self::assertSame(
			[
				$posting('r-1', 1, 'je-2026-03-15-huur'),
				$posting('r-3', 2, 'je-2026-03-15-rente-dag'),
			],
			$store->saved['AutoAccrualPosting']
		);
		self::assertSame('soft-closed', $store->saved['PeriodStatus'][0]['stage']);
		self::assertSame(
			[['info', 'SoftCloseExecutor: soft-close completed', ['administrationId' => 'adm-1', 'periodId' => '2026-03', 'postingCount' => 11]]],
			$logger->lines
		);

	}//end testACompleteRunReportsEveryStepAndWritesItsRecords()

	/**
	 * A failing fatal step stops the run with the report as it stood before
	 * that step and one alert; a failing lease step only logs and the run
	 * goes on.
	 *
	 * @return void
	 */
	public function testAFailingStepStopsTheRunAndAFailingLeaseStepDoesNot(): void {
		$cases = [
			'fx-revaluation' => ['OCA\Shillinq\Service\Treasury\FxRevaluationService', 'reval', 0],
			'revenue-cutoff' => ['OCA\Shillinq\Service\Ifrs15\RevenueRecognitionService', 'cutoff', 2],
			'intercompany-matching' => ['OCA\Shillinq\Service\IntercompanyMatchingService', 'matchRelationPeriod', 9],
			'trial-balance' => ['OCA\Shillinq\Service\TrialBalanceService', 'generate', 9],
		];
		foreach ($cases as $step => [$id, $method, $postingCount]) {
			$delegates = $this->allDelegates();
			$delegates[$id] = $this->throwing(method: $method, message: 'boom');
			[$executor, $store, $logger] = $this->executorWith(rules: [], delegates: $delegates);

			$report = $executor->execute(administrationId: 'adm-1', periodId: '2026-03', asOf: new DateTimeImmutable('2026-03-15T10:00:00+00:00'));

			self::assertSame('failed', $report['status'], $step);
			self::assertSame($postingCount, $report['postingCount'], $step);
			self::assertSame('2026-03-15T10:00:00+00:00', $report['completedAt'], $step);
			self::assertSame('Soft-close failed at step "' . $step . '": boom', $report['alerts'][0]['message'], $step);
			self::assertCount(1, $store->saved['ContinuousCloseAlert'], $step);
			self::assertArrayNotHasKey('PeriodStatus', $store->saved, $step);
			self::assertSame(
				[
					'error',
					'SoftCloseExecutor: soft-close failed',
					['administrationId' => 'adm-1', 'periodId' => '2026-03', 'step' => $step, 'exception' => 'boom'],
				],
				$logger->lines[0],
				$step
			);
		}

		$delegates = $this->allDelegates();
		$delegates['OCA\Shillinq\Service\Ifrs16\LeasePostingService'] = $this->throwing(method: 'post', message: 'no ifrs16');
		[$executor, $store, $logger] = $this->executorWith(rules: [], delegates: $delegates);
		$report = $executor->execute(administrationId: 'adm-1', periodId: '2026-03', asOf: new DateTimeImmutable('2026-03-15T10:00:00+00:00'));
		self::assertSame('completed', $report['status']);
		self::assertSame(0, $report['leasePostings']);
		self::assertSame(5, $report['postingCount']);
		self::assertSame(
			['info', 'SoftCloseExecutor: lease postings skipped', ['administrationId' => 'adm-1', 'periodId' => '2026-03', 'reason' => 'no ifrs16']],
			$logger->lines[0]
		);

	}//end testAFailingStepStopsTheRunAndAFailingLeaseStepDoesNot()
}//end class
