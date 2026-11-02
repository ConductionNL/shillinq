<?php

/**
 * Golden record of SlotService::getAvailableSlots.
 *
 * Each scenario runs one service, resource and cache world and records the
 * result, every cache read and write, every object-service read and every log
 * line. The clock is fixed. The record is
 * tests/Unit/Service/fixtures/slot-availability-golden.json;
 * SHILLINQ_GOLDEN_RECORD=1 rewrites it.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Slot availability golden tests.
 *
 * @covers \OCA\Shillinq\Service\SlotService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SlotAvailabilityGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/slot-availability-golden.json';

	/**
	 * Every world.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function scenarios(): array {
		$cases = [];
		foreach (array_keys(self::worlds()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;

	}//end scenarios()

	/**
	 * The availability matches the golden record.
	 *
	 * @param string $name The world.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testSlotsMatchGolden(string $name): void {
		$world   = self::worlds()[$name] + ['cache' => 'none', 'or' => true, 'date' => '2026-05-22'];
		$rows    = $world['rows'] ?? self::rows();
		$objects = new GoldenSlotObjects(rows: $rows, fails: ($world['fails'] ?? null));
		$logger  = new GoldenSlotLogger();
		$cacheLog = [];

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$cacheLog, $world): mixed {
				$cacheLog[] = 'get ' . $key;
				return match ($world['cache']) {
					'hit' => ['slots' => [['startTime' => 'cached']], 'etag' => 42],
					'noEtag' => ['slots' => []],
					'scalar' => 'stale',
					default => null,
				};
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value, int $ttl) use (&$cacheLog): bool {
				$cacheLog[] = 'set ' . $key . ' ' . $ttl . ' ' . json_encode($value);
				return true;
			}
		);

		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isLocalCacheAvailable')->willReturnCallback(
			static function () use ($world): bool {
				if ($world['cache'] === 'factoryThrows') {
					throw new RuntimeException('redis gone');
				}

				return $world['cache'] !== 'none';
			}
		);
		$factory->method('createLocal')->willReturn($cache);

		$settings = $this->createStub(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($world['or']);
		$settings->method('getRegisterSlug')->willReturn('books');

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn((int) strtotime('2026-05-22T10:20:00Z'));

		$service = new SlotService(container: $container, settings: $settings, cacheFactory: $factory, time: $time, logger: $logger);
		$result  = $service->getAvailableSlots('svc-1', 'room-1', $world['date']);

		$this->assertGolden(
			key: $name,
			recorded: ['result' => $result, 'cache' => $cacheLog, 'reads' => $objects->reads, 'log' => $logger->lines]
		);

	}//end testSlotsMatchGolden()

	/**
	 * Compare one recording with the golden file (or record it).
	 *
	 * @param string $key The scenario key.
	 * @param array<string, mixed> $recorded What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(string $key, array $recorded): void {
		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		}

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			$golden[$key] = $recorded;
			ksort($golden);
			$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			file_put_contents(self::GOLDEN, json_encode($golden, $flags) . "\n");
		}

		self::assertSame(json_encode($golden[$key] ?? null), json_encode($recorded));

	}//end assertGolden()

	/**
	 * The default rows per schema.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function rows(): array {
		return [
			'Service' => [['serviceId' => 'svc-1', 'duration' => 30]],
			'Resource' => [['resourceId' => 'room-1', 'openingTime' => '10:00', 'closingTime' => '12:00']],
			'Appointment' => [
				['startTime' => '2026-05-22T11:00:00Z', 'endTime' => '2026-05-22T11:30:00Z', 'status' => 'confirmed'],
				['startTime' => '2026-05-22T11:30:00Z', 'endTime' => '2026-05-22T11:45:00Z', 'status' => 'cancelled'],
			],
		];

	}//end rows()

	/**
	 * The worlds: cache, OpenRegister, rows and date.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function worlds(): array {
		$rows = self::rows();
		return [
			'computed without a cache' => [],
			'computed and cached' => ['cache' => 'miss'],
			'served from the cache' => ['cache' => 'hit'],
			'cache entry without etag' => ['cache' => 'noEtag'],
			'cache entry not an array' => ['cache' => 'scalar'],
			'cache factory throws' => ['cache' => 'factoryThrows'],
			'no OpenRegister' => ['or' => false, 'cache' => 'miss'],
			'service unknown' => ['rows' => ['Service' => []] + $rows, 'cache' => 'miss'],
			'resource unknown' => ['rows' => ['Resource' => []] + $rows, 'cache' => 'miss'],
			'zero duration' => ['rows' => ['Service' => [['duration' => 0]]] + $rows, 'cache' => 'miss'],
			'no duration' => ['rows' => ['Service' => [['serviceId' => 'svc-1']]] + $rows],
			'default hours' => ['rows' => ['Resource' => [['resourceId' => 'room-1']]] + $rows, 'date' => '2026-05-23'],
			'empty hours' => ['rows' => ['Resource' => [['openingTime' => '', 'closingTime' => '']]] + $rows, 'date' => '2026-05-23'],
			'overlap allowed' => ['rows' => ['Resource' => [['openingTime' => '11:00', 'closingTime' => '12:00', 'allowOverlap' => true]]] + $rows],
			'bad hours' => ['rows' => ['Resource' => [['openingTime' => 'xx', 'closingTime' => '12:00']]] + $rows],
			'service read fails' => ['fails' => 'Service'],
			'appointment read fails' => ['fails' => 'Appointment', 'cache' => 'miss'],
		];

	}//end worlds()
}//end class

/**
 * Object service double that answers per schema and records reads.
 */
final class GoldenSlotObjects {

	/**
	 * Every read as schema and arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * The current register and schema.
	 *
	 * @var array<string, string>
	 */
	private array $current = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, mixed>> $rows Rows per schema.
	 * @param string|null $fails The schema whose read throws.
	 */
	public function __construct(
		private readonly array $rows,
		private readonly ?string $fails,
	) {
	}//end __construct()

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		$this->current = ['register' => $register];
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
		$this->current['schema'] = $schema;
		return $this;
	}//end setSchema()

	/**
	 * The rows of the current schema.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 * @param bool $_rbac Whether RBAC applies.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = [], bool $_rbac = true): array {
		$this->reads[] = $this->current + ['config' => $config, 'rbac' => $_rbac];
		if ($this->fails === $this->current['schema']) {
			throw new RuntimeException('read failed');
		}

		return ($this->rows[$this->current['schema']] ?? []);
	}//end findAll()
}//end class

/**
 * Logger that records every line as level: message context.
 */
final class GoldenSlotLogger extends AbstractLogger {

	/**
	 * The recorded lines.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * Record one log line.
	 *
	 * @param mixed $level The level.
	 * @param string|Stringable $message The message.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
	}//end log()
}//end class
