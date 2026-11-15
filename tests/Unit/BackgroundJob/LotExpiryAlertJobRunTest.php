<?php

/**
 * Unit tests for the alerts LotExpiryAlertJob raises.
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

use DateTime;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\LotExpiryAlertJob;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Run the job over a fake store: which lots raise which alerts, how existing
 * alerts are respected, and how failures are contained. "Today" comes from
 * the mocked time factory, never from the clock.
 *
 * @covers \OCA\Shillinq\BackgroundJob\LotExpiryAlertJob
 * @uses   \OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem
 */
class LotExpiryAlertJobRunTest extends TestCase {

	/**
	 * Rows per schema in the fake store.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every findAll() call, as [register, schema, config].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	public array $finds = [];

	/**
	 * Every saveObject() call, as named arguments.
	 *
	 * @var array<int, array{object: array, register: string, schema: string}>
	 */
	public array $saves = [];

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the logger before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once with today as given.
	 *
	 * @param string       $today        Today, in the time factory's own zone.
	 * @param boolean      $available    Whether OpenRegister is available.
	 * @param object|null  $objects      The object service; the fake store when null.
	 *
	 * @return void
	 */
	private function runJob(string $today='2026-05-16 10:00:00', bool $available=true, ?object $objects=null): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime($today, new DateTimeZone('UTC')));

		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn($available);
		$location->method('getRegisterSlug')->willReturn('voorraad');

		$objects ??= $this->store();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objects);

		$job = new LotExpiryAlertJob(time: $time, registerLocation: $location, container: $container, logger: $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The fake store.
	 *
	 * @return object The object service.
	 */
	private function store(): object {
		return new class($this) {
			/**
			 * The register set last.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param LotExpiryAlertJobRunTest $test The test holding the rows.
			 */
			public function __construct(private readonly LotExpiryAlertJobRunTest $test) {
			}

			/**
			 * @param string $register The register.
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array $config The query.
			 * @return array
			 */
			public function findAll(array $config): array {
				$this->test->finds[] = [$this->register, $this->schema, $config];
				if ($this->schema !== 'ExpiryAlert') {
					return ($this->test->rows[$this->schema] ?? []);
				}

				$filters = $config['filters'];
				return array_values(
					array_filter(
						($this->test->rows['ExpiryAlert'] ?? []),
						static fn (array $alert): bool => array_intersect_assoc($filters, $alert) === $filters
					)
				);
			}

			/**
			 * @param array  $object   The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 * @return array
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->test->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return $object;
			}
		};
	}//end store()

	/**
	 * The saved alerts, as [lotId, alertType, daysBeforeExpiry or null].
	 *
	 * @return array<int, array{0: string, 1: string, 2: int|null}>
	 */
	private function raised(): array {
		return array_map(
			static fn (array $save): array => [$save['object']['lotId'], $save['object']['alertType'], ($save['object']['daysBeforeExpiry'] ?? null)],
			$this->saves
		);
	}//end raised()

	/**
	 * Without OpenRegister the job only says it skipped.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsRead(): void {
		$this->logger->expects($this->once())->method('info')->with('LotExpiryAlertJob: OpenRegister not available, skipping.');

		$this->runJob(available: false);

		$this->assertSame([], $this->finds);
	}//end testWithoutOpenRegisterNothingIsRead()

	/**
	 * Active lots are read from the configured register, and each threshold reached raises one alert.
	 *
	 * @return void
	 */
	public function testEachReachedThresholdRaisesAnAlert(): void {
		$this->rows['InventoryLot'] = [
			['id' => 'far', 'expiryDate' => '2026-07-01'],
			['id' => 'month', 'expiryDate' => '2026-06-15', 'administrationId' => 'adm-1'],
			['id' => 'week', 'expiryDate' => '2026-05-23', 'administrationId' => 'adm-1'],
			['id' => 'today', 'expiryDate' => '2026-05-16'],
		];
		$this->logger->expects($this->once())->method('info')->with('LotExpiryAlertJob: 5 new expiry alert(s) raised for 4 active lot(s).');

		$this->runJob();

		$this->assertSame(['voorraad', 'InventoryLot', ['filters' => ['lotStatus' => 'active'], 'limit' => 5000]], $this->finds[0]);
		$this->assertSame(
			[
				['month', 'approaching_expiry', 30],
				['week', 'approaching_expiry', 30],
				['week', 'approaching_expiry', 7],
				['today', 'approaching_expiry', 30],
				['today', 'approaching_expiry', 7],
			],
			$this->raised()
		);
		$this->assertSame(
			['object' => ['lotId' => 'month', 'alertType' => 'approaching_expiry', 'alertDate' => '2026-05-16', 'status' => 'pending', 'administrationId' => 'adm-1', 'daysBeforeExpiry' => 30], 'register' => 'voorraad', 'schema' => 'ExpiryAlert'],
			$this->saves[0]
		);
	}//end testEachReachedThresholdRaisesAnAlert()

	/**
	 * An expired lot raises one expired alert, without a threshold.
	 *
	 * @return void
	 */
	public function testAnExpiredLotRaisesOneExpiredAlert(): void {
		$this->rows['InventoryLot'] = [['uuid' => 'gone', 'expiryDate' => '2026-05-15']];

		$this->runJob();

		$this->assertSame([['gone', 'expired', null]], $this->raised());
		$this->assertArrayNotHasKey('daysBeforeExpiry', $this->saves[0]['object']);
		$this->assertSame(['lotId' => 'gone', 'alertType' => 'expired'], end($this->finds)[2]['filters']);
	}//end testAnExpiredLotRaisesOneExpiredAlert()

	/**
	 * An alert that already exists is not raised again.
	 *
	 * @return void
	 */
	public function testAnExistingAlertIsNotRaisedAgain(): void {
		$this->rows['InventoryLot'] = [['id' => 'week', 'expiryDate' => '2026-05-20'], ['id' => 'old', 'expiryDate' => '2026-01-01']];
		$this->rows['ExpiryAlert']  = [
			['lotId' => 'week', 'alertType' => 'approaching_expiry', 'daysBeforeExpiry' => 30],
			['lotId' => 'old', 'alertType' => 'expired'],
		];
		$this->logger->expects($this->once())->method('info')->with('LotExpiryAlertJob: 1 new expiry alert(s) raised for 2 active lot(s).');

		$this->runJob();

		$this->assertSame([['week', 'approaching_expiry', 7]], $this->raised());
	}//end testAnExistingAlertIsNotRaisedAgain()

	/**
	 * Lots without an id or an expiry date, or that are not records, are skipped.
	 *
	 * @return void
	 */
	public function testIncompleteLotsAreSkipped(): void {
		$this->rows['InventoryLot'] = [['expiryDate' => '2026-05-16'], ['id' => 'x'], 'not-a-lot', 42];

		$this->runJob();

		$this->assertSame([], $this->saves);
	}//end testIncompleteLotsAreSkipped()

	/**
	 * A lot entity is read through jsonSerialize(), getObject() or its public properties.
	 *
	 * @return void
	 */
	public function testLotEntitiesAreRead(): void {
		$serialisable = new class implements \JsonSerializable {
			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['id' => 'json', 'expiryDate' => '2026-05-01'];
			}
		};
		$entity = new class {
			/**
			 * @return array
			 */
			public function getObject(): array {
				return ['id' => 'entity', 'expiryDate' => '2026-05-01'];
			}
		};
		$plain = new \stdClass();
		$plain->id = 'plain';
		$plain->expiryDate = '2026-05-01';
		$this->rows['InventoryLot'] = [$serialisable, $entity, $plain];

		$this->runJob();

		$this->assertSame(['json', 'entity', 'plain'], array_column($this->raised(), 0));
	}//end testLotEntitiesAreRead()

	/**
	 * Today is taken in UTC, whatever zone the time factory answers in.
	 *
	 * @return void
	 */
	public function testTodayIsTheUtcDate(): void {
		$this->rows['InventoryLot'] = [['id' => 'lot', 'expiryDate' => '2026-05-16']];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-05-17 01:00:00', new DateTimeZone('Europe/Amsterdam')));
		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn(true);
		$location->method('getRegisterSlug')->willReturn('voorraad');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store());
		$job = new LotExpiryAlertJob(time: $time, registerLocation: $location, container: $container, logger: $this->logger);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame('2026-05-16', $this->saves[0]['object']['alertDate']);
		$this->assertSame('approaching_expiry', $this->saves[0]['object']['alertType']);
	}//end testTodayIsTheUtcDate()

	/**
	 * A lot whose expiry cannot be read is skipped with a warning; the others still count.
	 *
	 * @return void
	 */
	public function testABadLotIsSkippedWithAWarning(): void {
		$this->rows['InventoryLot'] = [['id' => 'bad', 'expiryDate' => 'not a date'], ['id' => 'ok', 'expiryDate' => '2026-05-01']];
		$this->logger->expects($this->once())->method('warning')->with($this->stringStartsWith('LotExpiryAlertJob: skip lot: '));

		$this->runJob();

		$this->assertSame([['ok', 'expired', null]], $this->raised());
	}//end testABadLotIsSkippedWithAWarning()

	/**
	 * A failing lot read is logged as an error and raises nothing.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAsAnError(): void {
		$failing = new class {
			/**
			 * @return static
			 */
			public function setRegister(): static {
				return $this;
			}

			/**
			 * @return static
			 */
			public function setSchema(): static {
				return $this;
			}

			/**
			 * @return never
			 */
			public function findAll(): never {
				throw new RuntimeException('index down');
			}
		};
		$this->logger->expects($this->once())->method('error')->with('LotExpiryAlertJob failed: index down', ['exception' => 'index down']);

		$this->runJob(objects: $failing);
	}//end testAFailingReadIsLoggedAsAnError()
}//end class
