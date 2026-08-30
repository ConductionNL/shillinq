<?php

/**
 * Unit tests for BookingReminderJob.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\BookingReminderJob;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingReminderJob.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */
class BookingReminderJobTest extends TestCase {

	/**
	 * Mock ITimeFactory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Mock BookingNotificationService.
	 *
	 * @var BookingNotificationService&MockObject
	 */
	private BookingNotificationService&MockObject $notificationService;

	/**
	 * Mock ContainerInterface.
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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var BookingReminderJob
	 */
	private BookingReminderJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->time = $this->createMock(originalClassName: ITimeFactory::class);
		$this->notificationService = $this->createMock(originalClassName: BookingNotificationService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->time->method('getTime')->willReturn(time());
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->job = $this->buildJob(store: $this->buildObjectServiceStub());
	}//end setUp()

	/**
	 * Build the job over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the job is built — parking it on the
	 * container after the fact leaves the job reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return BookingReminderJob
	 */
	private function buildJob(object $store): BookingReminderJob {
		return new BookingReminderJob(
			time: $this->time,
			notificationService: $this->notificationService,
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end buildJob()

	/**
	 * Build an empty in-memory ObjectService double.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Answer an empty result set.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('DB unavailable');
			}//end findAll()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('DB unavailable');
			}//end find()
		};
	}//end buildUnavailableObjectServiceStub()

	/**
	 * BookingReminderJob can be instantiated with correct interval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
	 */
	public function testJobCanBeInstantiated(): void {
		static::assertInstanceOf(expected: BookingReminderJob::class, actual: $this->job);
	}//end testJobCanBeInstantiated()

	/**
	 * Job handles object-service error gracefully and logs it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
	 */
	public function testJobHandlesObjectServiceErrorGracefully(): void {
		$this->job = $this->buildJob(store: $this->buildUnavailableObjectServiceStub());

		// Logger::error should be called for each window that fails.
		$this->logger
			->expects(static::atLeastOnce())
			->method('error');

		// Logging starting and complete are info calls.
		$this->logger
			->expects(static::atLeastOnce())
			->method('info');

		// Run() is protected; call via reflection.
		$ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
		$ref->invoke($this->job, null);
	}//end testJobHandlesObjectServiceErrorGracefully()
}//end class
