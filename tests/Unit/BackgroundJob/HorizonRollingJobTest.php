<?php

/**
 * Unit tests for HorizonRollingJob.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-29
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\HorizonRollingJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for HorizonRollingJob per REQ-CF-000.
 *
 * Covers the pure roll arithmetic (shiftHorizon), idempotency detection
 * (alreadyRolled / currentIsoMonday), and the register-slug fallback.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class HorizonRollingJobTest extends TestCase {

	/**
	 * Mock ITimeFactory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $timeFactory;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var HorizonRollingJob
	 */
	private HorizonRollingJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(time());
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->job = new HorizonRollingJob(
			$this->timeFactory,
			$this->container,
			$this->appConfig,
			$this->logger
		);

	}//end setUp()

	/**
	 * Invoke a private method on the job under test.
	 *
	 * @param string $name The method name.
	 * @param array<mixed> $args The arguments.
	 *
	 * @return mixed The method result.
	 */
	private function invoke(string $name, array $args): mixed {
		$m = new ReflectionMethod(HorizonRollingJob::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->job, $args);
	}//end invoke()

	/**
	 * shiftHorizon moves the window forward to the given Monday and stamps rolledOp.
	 *
	 * @return void
	 */
	public function testShiftHorizonAdvancesWindowByOneWeek(): void {
		$horizon = [
			'horizonStart' => '2026-05-18',
			'horizonEnd' => '2026-08-16',
			'rolledOn' => '2026-05-18T02:00:00Z',
			'lifecycleState' => 'rolling',
		];

		$rolled = $this->invoke('shiftHorizon', [$horizon, '2026-05-25']);

		self::assertSame('2026-05-25', $rolled['horizonStart']);
		// +90 days from 2026-05-25 is 2026-08-23.
		self::assertSame('2026-08-23', $rolled['horizonEnd']);
		self::assertSame('active', $rolled['lifecycleState']);
		self::assertNotSame('2026-05-18T02:00:00Z', $rolled['rolledOn']);

	}//end testShiftHorizonAdvancesWindowByOneWeek()

	/**
	 * alreadyRolled is true only when horizonStart equals the target Monday.
	 *
	 * @return void
	 */
	public function testAlreadyRolledDetectsCurrentMonday(): void {
		self::assertTrue(
			$this->invoke('alreadyRolled', [['horizonStart' => '2026-05-25'], '2026-05-25'])
		);
		self::assertFalse(
			$this->invoke('alreadyRolled', [['horizonStart' => '2026-05-18'], '2026-05-25'])
		);

	}//end testAlreadyRolledDetectsCurrentMonday()

	/**
	 * currentIsoMonday returns a Monday in YYYY-MM-DD form.
	 *
	 * @return void
	 */
	public function testCurrentIsoMondayIsAMonday(): void {
		$monday = $this->invoke('currentIsoMonday', []);

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $monday);
		$dow = (new \DateTimeImmutable($monday))->format('N');
		self::assertSame('1', $dow, 'currentIsoMonday must return a Monday (ISO day 1)');

	}//end testCurrentIsoMondayIsAMonday()

	/**
	 * getRegisterSlug falls back to 'shillinq' when the config value is empty.
	 *
	 * @return void
	 */
	public function testRegisterSlugFallback(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$job = new HorizonRollingJob($this->timeFactory, $this->container, $appConfig, $this->logger);

		$m = new ReflectionMethod(HorizonRollingJob::class, 'getRegisterSlug');
		$m->setAccessible(true);
		self::assertSame('shillinq', $m->invoke($job));

	}//end testRegisterSlugFallback()

	/**
	 * run() exits gracefully (logs a warning, no throw) when OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

		$m = new ReflectionMethod(HorizonRollingJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($this->job, null);

		// No exception means the fail-safe path held.
		$this->addToAssertionCount(1);

	}//end testRunSkipsWhenOpenRegisterUnavailable()
}//end class
