<?php

/**
 * The edge branches the background jobs' own tests leave out: a failing
 * payment-plan pass is logged rather than thrown, a relationship without
 * revenue is not long-standing, a non-array administration row is skipped,
 * an empty register setting falls back to the app's register, and a
 * non-positive FX rate has no inverse.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\AdministrationBackupSchedulerJob;
use OCA\Shillinq\BackgroundJob\DBAMultiYearRelationship;
use OCA\Shillinq\BackgroundJob\FxRateImportJob;
use OCA\Shillinq\BackgroundJob\PaymentPlanMonitorJob;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Edge branches of four background jobs.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(PaymentPlanMonitorJob::class)]
#[CoversClass(DBAMultiYearRelationship::class)]
#[CoversClass(AdministrationBackupSchedulerJob::class)]
#[CoversClass(FxRateImportJob::class)]
final class JobEdgeBranchesTest extends TestCase {

	/**
	 * Call a private method.
	 *
	 * @param object       $subject The object.
	 * @param string       $method  The method.
	 * @param array<mixed> $args    The arguments.
	 *
	 * @return mixed The result.
	 */
	private static function invoke(object $subject, string $method, array $args = []): mixed {
		return (new ReflectionMethod($subject, $method))->invokeArgs($subject, $args);
	}//end invoke()

	/**
	 * A failing payment-plan pass is logged with its message, and the job does not throw.
	 *
	 * @return void
	 */
	public function testAFailingPaymentPlanPassIsLoggedNotThrown(): void {
		$plans = $this->createMock(PaymentPlanService::class);
		$plans->method('monitor')->willThrowException(new RuntimeException('register unreachable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('error')
			->with('PaymentPlanMonitorJob: daily pass failed', ['exception' => 'register unreachable']);

		$job = new PaymentPlanMonitorJob($this->createMock(ITimeFactory::class), $plans, $logger);
		self::invoke($job, 'run', [null]);
	}//end testAFailingPaymentPlanPassIsLoggedNotThrown()

	/**
	 * A successful pass logs its counts.
	 *
	 * @return void
	 */
	public function testASuccessfulPaymentPlanPassLogsItsCounts(): void {
		$counts = ['due' => 2, 'missed' => 1, 'broken' => 1, 'completed' => 0];
		$plans = $this->createMock(PaymentPlanService::class);
		$plans->method('monitor')->willReturn($counts);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('PaymentPlanMonitorJob: daily pass done', $counts);
		$logger->expects($this->never())->method('error');

		$job = new PaymentPlanMonitorJob($this->createMock(ITimeFactory::class), $plans, $logger);
		self::invoke($job, 'run', [null]);
	}//end testASuccessfulPaymentPlanPassLogsItsCounts()

	/**
	 * Three years without revenue is not a long-standing relationship; three years at 60 % is.
	 *
	 * @return void
	 */
	public function testARelationshipWithoutRevenueIsNotLongStanding(): void {
		$relationship = new DBAMultiYearRelationship(now: new DateTimeImmutable('2026-06-01'), total: 100000);
		$start = new DateTimeImmutable('2023-06-01');

		self::assertNull($relationship->describe('c-0', ['start' => $start, 'revenue' => 0]));
		self::assertSame(
			['customerId' => 'c-1', 'startDate' => '2023-06-01', 'durationYears' => 3.0, 'revenueShare' => 0.6],
			$relationship->describe('c-1', ['start' => $start, 'revenue' => 60000])
		);
	}//end testARelationshipWithoutRevenueIsNotLongStanding()

	/**
	 * A row that is not an array is skipped; the due administration beside it is still returned.
	 *
	 * @return void
	 */
	public function testANonArrayAdministrationRowIsSkipped(): void {
		$job = new AdministrationBackupSchedulerJob(
			time: $this->createMock(ITimeFactory::class),
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		$due = ['id' => 'adm-1', 'backupSchedule' => 'dagelijks', 'status' => 'actief'];

		$result = $job->evaluateDueAdministrations(
			administrations: ['not-a-row', null, $due],
			now: new DateTimeImmutable('2026-06-08T08:00:00+00:00')
		);

		self::assertSame([$due], array_values($result));
	}//end testANonArrayAdministrationRowIsSkipped()

	/**
	 * An empty register setting falls back to the app's own register; a set one is kept.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterSettingFallsBackToTheAppRegister(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnOnConsecutiveCalls('', 'finance');
		$job = new AdministrationBackupSchedulerJob(
			time: $this->createMock(ITimeFactory::class),
			container: $this->createMock(ContainerInterface::class),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame('shillinq', self::invoke($job, 'resolveRegister'));
		self::assertSame('finance', self::invoke($job, 'resolveRegister'));
	}//end testAnEmptyRegisterSettingFallsBackToTheAppRegister()

	/**
	 * A non-positive rate is stored without an inverse rather than dividing by it.
	 *
	 * @return void
	 */
	public function testANonPositiveRateHasNoInverse(): void {
		$job = new FxRateImportJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TreasuryRateAdapterInterface::class),
		);

		$zero = self::invoke($job, 'buildRecord', ['USD', 'EUR', '2026-06-10', 0.0]);
		$negative = self::invoke($job, 'buildRecord', ['USD', 'EUR', '2026-06-10', -1.5]);

		self::assertArrayHasKey('inverseRate', $zero);
		self::assertNull($zero['inverseRate']);
		self::assertNull($negative['inverseRate']);
		self::assertSame(0.0, $zero['rate']);
	}//end testANonPositiveRateHasNoInverse()
}//end class
