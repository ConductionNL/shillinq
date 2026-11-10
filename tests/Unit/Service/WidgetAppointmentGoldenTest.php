<?php

/**
 * Golden record of WidgetService::createAppointment.
 *
 * Each scenario feeds one widget payload against one service catalogue, slot
 * answer and save outcome, and records the response, the saved object, every
 * object-service read, every slot read and invalidation, and every log line.
 * Generated appointment ids are recorded as wsw-ID. The record is
 * tests/Unit/Service/fixtures/widget-appointment-golden.json;
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

use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Widget appointment golden tests.
 *
 * @covers \OCA\Shillinq\Service\WidgetService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WidgetAppointmentGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/widget-appointment-golden.json';

	private const VALID = [
		'serviceId' => ' svc-1 ',
		'resourceId' => 'room-1',
		'startTime' => '2026-06-01T09:00:00Z',
		'endTime' => '2026-06-01T09:30:00Z',
		'customerName' => ' Jan Jansen ',
		'customerEmail' => ' Jan@Example.org ',
		'customerPhone' => '+31612345678',
		'notes' => 'Graag bij de ingang',
	];

	/**
	 * Every scenario: payload overrides plus the world it runs in.
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
	 * The appointment outcome matches the golden record.
	 *
	 * @param string $name The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testAppointmentMatchesGolden(string $name): void {
		$world   = self::worlds()[$name] + ['payload' => [], 'admin' => 'adm-1', 'service' => 'public', 'slots' => 'offered', 'save' => 'array'];
		$payload = array_merge(self::VALID, $world['payload']);
		$objects = new GoldenWidgetObjects(service: $world['service'], save: $world['save']);
		$logger  = new GoldenWidgetLogger();
		$slots   = [];

		$slotService = $this->createStub(SlotService::class);
		$slotService->method('getAvailableSlots')->willReturnCallback(
			static function (string $serviceId, string $resourceId, string $date) use (&$slots, $world): array {
				$slots[] = 'read ' . $serviceId . '/' . $resourceId . '/' . $date;
				return match ($world['slots']) {
					'throws' => throw new RuntimeException('cache down'),
					'none' => ['slots' => []],
					'missing' => [],
					'offset' => ['slots' => [['startTime' => '2026-06-01T23:30:00+02:00', 'endTime' => '2026-06-02T00:15:00+02:00']]],
					'endDiffers' => ['slots' => [['startTime' => '2026-06-01T09:00:00Z', 'endTime' => '2026-06-01T10:00:00Z']]],
					default => ['slots' => [['startTime' => '2026-06-01T08:30:00Z'], ['startTime' => '2026-06-01T09:00:00Z', 'endTime' => '2026-06-01T09:30:00Z']]],
				};
			}
		);
		$slotService->method('invalidate')->willReturnCallback(
			static function (string $serviceId, string $resourceId, string $date) use (&$slots): void {
				$slots[] = 'invalidate ' . $serviceId . '/' . $resourceId . '/' . $date;
			}
		);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $world): object {
				if ($world['service'] === 'noContainer') {
					throw new RuntimeException('no ' . $id);
				}

				return $objects;
			}
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('books');

		$service  = new WidgetService(container: $container, appConfig: $appConfig, slotService: $slotService, logger: $logger);
		$response = $service->createAppointment($world['admin'], $payload);

		$recorded = json_decode((string) preg_replace('/wsw-[0-9a-f]{16}/', 'wsw-ID', (string) json_encode([
			'response' => $response,
			'reads' => $objects->reads,
			'saved' => $objects->saved,
			'slots' => $slots,
			'log' => $logger->lines,
		])), true);
		$this->assertGolden(key: $name, recorded: $recorded);

	}//end testAppointmentMatchesGolden()

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
	 * The scenarios: payload overrides, administration, catalogue, slots and save outcome.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function worlds(): array {
		return [
			'created' => [],
			'created without phone and notes' => ['payload' => ['customerPhone' => '', 'notes' => '']],
			'created from an offset time' => [
				'payload' => ['startTime' => '2026-06-01T11:00:00+02:00', 'endTime' => '2026-06-01T11:30:00+02:00'],
				'slots' => 'none',
			],
			'created across midnight in UTC+2' => [
				'payload' => ['startTime' => '2026-06-01T23:30:00+02:00', 'endTime' => '2026-06-02T00:15:00+02:00'],
				'slots' => 'offset',
			],
			'missing service' => ['payload' => ['serviceId' => '  ']],
			'missing resource' => ['payload' => ['resourceId' => '']],
			'missing start' => ['payload' => ['startTime' => '']],
			'missing end and bad email' => ['payload' => ['endTime' => '', 'customerEmail' => 'nope']],
			'bad email' => ['payload' => ['customerEmail' => 'nope']],
			'empty email' => ['payload' => ['customerEmail' => '']],
			'bad phone' => ['payload' => ['customerPhone' => '06-123']],
			'notes keep their spaces' => ['payload' => ['notes' => '  Graag  ']],
			'notes of 500' => ['payload' => ['notes' => str_repeat('é', 500)]],
			'notes of 501' => ['payload' => ['notes' => str_repeat('é', 501)]],
			'bad start' => ['payload' => ['startTime' => 'soon']],
			'bad end and long notes' => ['payload' => ['endTime' => 'later', 'notes' => str_repeat('x', 501)]],
			'bad end' => ['payload' => ['endTime' => 'later']],
			'no object service' => ['service' => 'noContainer'],
			'catalogue read fails' => ['service' => 'throws'],
			'service unknown' => ['service' => 'none'],
			'service not public' => ['service' => 'private'],
			'service inactive' => ['service' => 'inactive'],
			'service of another administration' => ['service' => 'otherAdmin'],
			'no administration' => ['admin' => ''],
			'service row unreadable' => ['service' => 'scalar'],
			'slot taken' => ['slots' => 'none'],
			'slots missing' => ['slots' => 'missing'],
			'slot end differs' => ['slots' => 'endDiffers'],
			'slot read fails' => ['slots' => 'throws'],
			'save fails' => ['save' => 'throws'],
			'save echoes its own ids' => ['save' => 'echo'],
			'save returns an entity' => ['save' => 'entity'],
			'save returns nothing usable' => ['save' => 'scalar'],
		];

	}//end worlds()
}//end class

/**
 * Object service double for the widget: one Service catalogue and a recording save.
 */
final class GoldenWidgetObjects {

	/**
	 * Every read as schema and arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * Every saved object with its register and schema.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * The current register and schema.
	 *
	 * @var array<string, string>
	 */
	private array $current = [];

	/**
	 * Constructor.
	 *
	 * @param string $service The catalogue shape.
	 * @param string $save The save outcome.
	 */
	public function __construct(
		private readonly string $service,
		private readonly string $save,
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
	 * The Service catalogue.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 * @param bool $_rbac Whether RBAC applies.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = [], bool $_rbac = true): array {
		$this->reads[] = $this->current + ['config' => $config, 'rbac' => $_rbac];
		$row = ['serviceId' => 'svc-1', 'isPublic' => true, 'status' => 'active', 'administrationId' => 'adm-1'];
		return match ($this->service) {
			'throws' => throw new RuntimeException('catalogue down'),
			'none' => [],
			'private' => [['isPublic' => false] + $row],
			'inactive' => [['status' => 'draft'] + $row],
			'otherAdmin' => [['administrationId' => 'adm-2'] + $row],
			'scalar' => ['row'],
			default => [$row, ['serviceId' => 'svc-1b']],
		};
	}//end findAll()

	/**
	 * Record a save and answer per the save outcome.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param bool $_rbac Whether RBAC applies.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, ?\OCA\OpenRegister\Service\Object\ObjectSaveOptions $options = null, bool $_rbac = true): mixed {
		$register = $options?->target->register;
		$schema = $options?->target->schema;
		$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac];
		return match ($this->save) {
			'throws' => throw new RuntimeException('disk full'),
			'echo' => ['appointmentId' => 'or-7', 'status' => 'confirmed'],
			'entity' => new GoldenWidgetEntity(['appointmentId' => 'ent-9']),
			'scalar' => 'saved',
			default => $object,
		};
	}//end saveObject()
}//end class

/**
 * A saved entity that serialises to its row.
 */
final class GoldenWidgetEntity {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	public function __construct(private readonly array $row) {
	}//end __construct()

	/**
	 * The row.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}//end jsonSerialize()
}//end class

/**
 * Logger that records every line as level: message context.
 */
final class GoldenWidgetLogger extends AbstractLogger {

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
