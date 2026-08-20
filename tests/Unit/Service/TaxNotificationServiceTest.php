<?php

/**
 * Unit tests for TaxNotificationService.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TaxNotificationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests Vpb deadline reminder dispatch (REQ-VPB-013).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxNotificationServiceTest extends TestCase {

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
	 * Mock IManager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $notificationMgr;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var TaxNotificationService
	 */
	private TaxNotificationService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notificationMgr = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->service = $this->buildService($this->buildObjectServiceStub([]));

	}//end setUp()

	/**
	 * Build the subject around a seeded in-memory ObjectService store.
	 *
	 * The store used to reach the subject through the container; ADR-084 injects
	 * it as a contract-typed constructor argument instead, so each test rebuilds
	 * the subject with its own store.
	 *
	 * @param object $store The seeded in-memory ObjectService double.
	 *
	 * @return TaxNotificationService
	 */
	private function buildService(object $store): TaxNotificationService {
		return new TaxNotificationService(
			appConfig: $this->appConfig,
			notificationMgr: $this->notificationMgr,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildService()

	/**
	 * The reminder window matches exactly 7 and 1 day(s) before the deadline (REQ-VPB-013).
	 *
	 * @return void
	 */
	public function testReminderWindowMatchesSevenAndOneDay(): void {
		$today = new \DateTimeImmutable('2025-04-13');

		// 7 days before 2025-04-20.
		self::assertSame(7, $this->service->reminderWindow(['deadlineDate' => '2025-04-20'], $today));

		$today = new \DateTimeImmutable('2025-04-19');
		// 1 day before 2025-04-20.
		self::assertSame(1, $this->service->reminderWindow(['deadlineDate' => '2025-04-20'], $today));

		$today = new \DateTimeImmutable('2025-04-17');
		// 3 days before — no reminder window.
		self::assertNull($this->service->reminderWindow(['deadlineDate' => '2025-04-20'], $today));

	}//end testReminderWindowMatchesSevenAndOneDay()

	/**
	 * A deadline in a reminder window dispatches exactly one notification (REQ-VPB-013).
	 *
	 * @return void
	 */
	public function testDispatchesReminderForDueDeadline(): void {
		$deadlines = [
			[
				'@self' => ['slug' => '2025-provisional-q1-payment'],
				'deadlineDate' => '2025-04-20',
				'deadlineType' => 'provisional-payment',
				'status' => 'pending',
			],
			// Filed deadline — must be skipped.
			[
				'@self' => ['slug' => 'filed-one'],
				'deadlineDate' => '2025-04-20',
				'status' => 'filed',
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($deadlines));

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();

		$this->notificationMgr->method('createNotification')->willReturn($notification);
		// Exactly one notify() for the single eligible pending deadline.
		$this->notificationMgr->expects($this->once())->method('notify')->with($notification);

		$count = $this->service->dispatchDueReminders(new \DateTimeImmutable('2025-04-13'));

		self::assertSame(1, $count);

	}//end testDispatchesReminderForDueDeadline()

	/**
	 * No eligible deadline dispatches nothing.
	 *
	 * @return void
	 */
	public function testNoReminderWhenOutsideWindow(): void {
		$deadlines = [
			[
				'@self' => ['slug' => 'far-away'],
				'deadlineDate' => '2025-12-31',
				'status' => 'pending',
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($deadlines));
		$this->notificationMgr->expects($this->never())->method('notify');

		$count = $this->service->dispatchDueReminders(new \DateTimeImmutable('2025-04-13'));

		self::assertSame(0, $count);

	}//end testNoReminderWhenOutsideWindow()

	/**
	 * Build a fluent ObjectService stub returning the given TaxDeadline records.
	 *
	 * @param array<int,array<string,mixed>> $deadlines Deadline records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $deadlines): object {
		return new class($deadlines) {
			/**
			 * Deadline records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $deadlines;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $deadlines Deadline records.
			 */
			public function __construct(array $deadlines) {
				$this->deadlines = $deadlines;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema (unused).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return the stubbed deadlines.
			 *
			 * @param array<string,mixed> $params Query params (unused).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				return $this->deadlines;
			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
