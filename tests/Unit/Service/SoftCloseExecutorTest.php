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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
}//end class
