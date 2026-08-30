<?php

/**
 * Unit tests for ObligationTaskBridge.
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
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\ObligationTaskBridge;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObligationTaskBridge fail-closed behaviour (REQ-CLM-003).
 *
 * Covers:
 * - No backend available → taskLinkStatus 'failed', no throw.
 * - Container throwing during resolution → 'failed', no throw.
 * - Malformed / empty input → 'failed', no throw.
 */
class ObligationTaskBridgeTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The bridge under test.
	 *
	 * @var ObligationTaskBridge
	 */
	private ObligationTaskBridge $bridge;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->bridge = new ObligationTaskBridge(
			container: $this->container,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end setUp()

	/**
	 * With no NC Tasks/Deck backend available, the bridge returns 'failed'
	 * without throwing (REQ-CLM-003 fail-closed degrade).
	 *
	 * @return void
	 */
	public function testNoBackendReturnsFailedWithoutThrowing(): void {
		$this->container->method('has')->willReturn(false);

		$result = $this->bridge->createTaskForObligation(
			[
				'title' => 'Provide annual insurance certificate',
				'dueDate' => '2026-09-01',
				'responsible' => 'bob',
			]
		);

		self::assertSame('failed', $result['taskLinkStatus']);
		self::assertNull($result['taskUri']);
	}//end testNoBackendReturnsFailedWithoutThrowing()

	/**
	 * A throwing container during resolution degrades fail-closed, not throws.
	 *
	 * @return void
	 */
	public function testThrowingContainerDegradesFailClosed(): void {
		$this->container->method('has')
			->willThrowException(new \RuntimeException('container exploded'));

		$result = $this->bridge->createTaskForObligation(
			[
				'title' => 'Quarterly SLA review',
				'dueDate' => '2026-07-15',
			]
		);

		self::assertSame('failed', $result['taskLinkStatus']);
		self::assertNull($result['taskUri']);
	}//end testThrowingContainerDegradesFailClosed()

	/**
	 * Malformed / empty input does not throw and returns 'failed'.
	 *
	 * @return void
	 */
	public function testMalformedInputDoesNotThrow(): void {
		$this->container->method('has')->willReturn(false);

		// Empty obligation.
		$empty = $this->bridge->createTaskForObligation([]);
		self::assertSame('failed', $empty['taskLinkStatus']);
		self::assertNull($empty['taskUri']);

		// Missing dueDate.
		$noDue = $this->bridge->createTaskForObligation(['title' => 'X']);
		self::assertSame('failed', $noDue['taskLinkStatus']);

		// Non-string field values must not fatal.
		$weird = $this->bridge->createTaskForObligation(
			[
				'title' => 'Valid title',
				'dueDate' => '2026-09-01',
				'responsible' => 'carol',
			]
		);
		self::assertSame('failed', $weird['taskLinkStatus']);
	}//end testMalformedInputDoesNotThrow()

	/**
	 * The result always has the documented shape (taskUri + taskLinkStatus
	 * keys, plus the additive REQ-CDC-005 eventUri + eventLinkStatus keys).
	 *
	 * @return void
	 */
	public function testResultShapeIsStable(): void {
		$this->container->method('has')->willReturn(false);

		$result = $this->bridge->createTaskForObligation(['title' => 'A', 'dueDate' => '2026-01-01']);

		self::assertArrayHasKey('taskUri', $result);
		self::assertArrayHasKey('taskLinkStatus', $result);
		self::assertArrayHasKey('eventUri', $result);
		self::assertArrayHasKey('eventLinkStatus', $result);
	}//end testResultShapeIsStable()

	/**
	 * REQ-CDC-005 scenario 2 — with no calendar backend BOTH the VTODO
	 * and the VEVENT surfaces return 'failed' without throwing, so
	 * obligation CRUD proceeds.
	 *
	 * @return void
	 */
	public function testNoBackendFailsBothVtodoAndVeventSoftly(): void {
		$this->container->method('has')->willReturn(false);

		$result = $this->bridge->createTaskForObligation(
			[
				'title' => 'Opzegtermijn office lease',
				'dueDate' => '2026-06-01',
			]
		);

		self::assertSame('failed', $result['taskLinkStatus']);
		self::assertSame('failed', $result['eventLinkStatus']);
		self::assertNull($result['taskUri']);
		self::assertNull($result['eventUri']);

		$event = $this->bridge->publishDeadlineEvent(
			obligation: [
				'title' => 'Opzegtermijn office lease',
				'dueDate' => '2026-06-01',
			]
		);
		self::assertSame('failed', $event['eventLinkStatus']);
		self::assertNull($event['eventUri']);
	}//end testNoBackendFailsBothVtodoAndVeventSoftly()

	/**
	 * REQ-CDC-005 scenario 1 — with a resolvable backend the bridge
	 * publishes the deadline VEVENT IN ADDITION to the existing VTODO,
	 * with the stable `contract:{objectId}` UID.
	 *
	 * @return void
	 */
	public function testCreateTaskAlsoPublishesDeadlineVevent(): void {
		$backend = new class {

			/**
			 * Every createFromString() call as [name, calendarData].
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public array $writes = [];

			/**
			 * Record a write.
			 *
			 * @param string $name Object name.
			 * @param string $calendarData Payload.
			 *
			 * @return void
			 */
			public function createFromString(string $name, string $calendarData): void {
				$this->writes[] = [$name, $calendarData];

			}//end createFromString()
		};

		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willReturn($backend);

		$result = $this->bridge->createTaskForObligation(
			[
				'@self' => ['slug' => 'obligation-lease-notice'],
				'title' => 'Opzegtermijn office lease',
				'dueDate' => '2026-06-01',
			]
		);

		self::assertSame('linked', $result['taskLinkStatus']);
		self::assertSame('linked', $result['eventLinkStatus']);
		self::assertNotNull($result['eventUri']);

		// Two writes: the VTODO and the additive VEVENT.
		self::assertCount(2, $backend->writes);
		$vtodo = $backend->writes[0][1];
		$vevent = $backend->writes[1][1];
		self::assertStringContainsString('BEGIN:VTODO', $vtodo);
		self::assertStringContainsString('BEGIN:VEVENT', $vevent);
		self::assertStringContainsString('UID:contract:obligation-lease-notice', $vevent);
		self::assertStringContainsString('DTSTART;VALUE=DATE:20260601', $vevent);

		// Idempotent UID: publishing again targets the same object name.
		$again = $this->bridge->publishDeadlineEvent(
			obligation: [
				'@self' => ['slug' => 'obligation-lease-notice'],
				'title' => 'Opzegtermijn office lease',
				'dueDate' => '2026-06-01',
			]
		);
		self::assertSame('linked', $again['eventLinkStatus']);
		self::assertSame($backend->writes[1][0], $backend->writes[2][0]);
	}//end testCreateTaskAlsoPublishesDeadlineVevent()

	/**
	 * REQ-CDC-005 — listOpenObligationDeadlines() reads the
	 * ContractObligation rows in the bridge (single home), keeping only
	 * open/in-progress/overdue rows with a dueDate; an unavailable
	 * OpenRegister yields [] fail-soft.
	 *
	 * @return void
	 */
	public function testListOpenObligationDeadlines(): void {
		$objectService = new class {

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return the stub ContractObligation rows.
			 *
			 * @param array<string, mixed> $params Query parameters (unused).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return [
					[
						'@self' => ['slug' => 'obligation-crm-sla-review'],
						'title' => 'Quarterly SLA review',
						'dueDate' => '2026-06-01',
						'status' => 'open',
					],
					[
						'@self' => ['slug' => 'obligation-done'],
						'title' => 'Completed obligation',
						'dueDate' => '2026-02-01',
						'status' => 'done',
					],
					[
						'@self' => ['slug' => 'obligation-no-due'],
						'title' => 'No deadline',
						'status' => 'open',
					],
				];

			}//end findAll()
		};

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		// Since ADR-084 the bridge reads OpenRegister through the injected
		// ObjectServiceInterface rather than through the container, so the
		// seeded store above has to be handed to the constructor. Rebuild the
		// bridge with it; the container mock stays for the register lookup.
		$this->bridge = new ObligationTaskBridge(
			container: $this->container,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter(inner: $objectService),
		);

		$deadlines = $this->bridge->listOpenObligationDeadlines();

		self::assertCount(1, $deadlines);
		self::assertSame('contract:obligation-crm-sla-review', $deadlines[0]['uid']);
		self::assertSame('contract', $deadlines[0]['category']);
		self::assertSame('Quarterly SLA review', $deadlines[0]['summary']);
		self::assertSame('2026-06-01', $deadlines[0]['dueDate']);
	}//end testListOpenObligationDeadlines()

	/**
	 * listOpenObligationDeadlines() degrades to [] when OpenRegister is
	 * unavailable (fail-soft, no throw).
	 *
	 * @return void
	 */
	public function testListOpenObligationDeadlinesFailsSoft(): void {
		$this->container->method('get')
			->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		self::assertSame([], $this->bridge->listOpenObligationDeadlines());
	}//end testListOpenObligationDeadlinesFailsSoft()
}//end class
