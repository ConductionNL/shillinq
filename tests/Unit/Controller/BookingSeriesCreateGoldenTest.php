<?php

/**
 * Golden record of BookingDepthController::createSeries.
 *
 * Each scenario sends one request body for one acting user and records the
 * response, the administrations the access check was asked about, the
 * series definition handed to the planner, every object-service read and
 * write, and every log line. The access check answers true only for the
 * acting user's own administration. The record is
 * tests/Unit/Controller/fixtures/booking-series-create-golden.json;
 * SHILLINQ_GOLDEN_RECORD=1 rewrites it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\BookingDepthController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\NoShowFeeCaptureService;
use OCA\Shillinq\Service\RecurringSeriesService;
use OCA\Shillinq\Service\SettingsService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Recurring series create golden tests.
 *
 * @covers \OCA\Shillinq\Controller\BookingDepthController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BookingSeriesCreateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/booking-series-create-golden.json';

	private const BODY = [
		'administrationId' => ' adm-1 ',
		'seriesId' => 'ser-1',
		'serviceId' => 'svc-1',
		'resourceId' => 'room-1',
		'startTime' => '2026-06-01T09:00:00Z',
		'durationMinutes' => '30',
		'recurrenceRule' => 'FREQ=WEEKLY;COUNT=3',
		'customerId' => ' cust-1 ',
	];

	/**
	 * Every scenario.
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
	 * The series create matches the golden record.
	 *
	 * @param string $name The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testCreateSeriesMatchesGolden(string $name): void {
		$world = self::worlds()[$name] + ['body' => [], 'user' => 'alice', 'or' => true, 'resource' => 'own', 'plan' => 'ok'];
		$body  = array_merge(self::BODY, $world['body']);

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default)
		);

		$asked   = [];
		$context = $this->createStub(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($world['user']);
		$context->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use (&$asked, $world): bool {
				$asked[] = $world['user'] . ' -> ' . $administrationId;
				return $world['user'] === 'alice' && $administrationId === 'adm-1';
			}
		);

		$settings = $this->createStub(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($world['or']);
		$settings->method('getRegisterSlug')->willReturn('books');

		$objects   = new GoldenSeriesObjects(resource: $world['resource']);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$planned = [];
		$series  = $this->createStub(RecurringSeriesService::class);
		$series->method('planSeries')->willReturnCallback(
			static function (array $seriesDef) use (&$planned, $world): array {
				$planned[] = $seriesDef;
				return match ($world['plan']) {
					'invalid' => throw new InvalidArgumentException('RRULE secret detail'),
					'crash' => throw new RuntimeException('planner crashed'),
					'empty' => ['generated' => [], 'skipped' => [['recurrenceIndex' => 0]]],
					default => [
						'generated' => [
							['recurrenceIndex' => 0, 'startTime' => '2026-06-01T09:00:00Z'],
							['recurrenceIndex' => '2', 'startTime' => '2026-06-15T09:00:00Z'],
						],
						'skipped' => [['recurrenceIndex' => 1, 'reason' => 'conflict']],
					],
				};
			}
		);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'l10n:' . $text);

		$logger     = new GoldenSeriesLogger();
		$controller = new BookingDepthController(
			request: $request,
			container: $container,
			settings: $settings,
			context: $context,
			noShowFee: $this->createStub(NoShowFeeCaptureService::class),
			series: $series,
			logger: $logger,
			l10n: $l10n,
		);
		$response = $controller->createSeries();

		$this->assertGolden(
			key: $name,
			recorded: [
				'status' => $response->getStatus(),
				'data' => $response->getData(),
				'asked' => $asked,
				'planned' => $planned,
				'reads' => $objects->reads,
				'saved' => $objects->saved,
				'log' => $logger->lines,
			]
		);

	}//end testCreateSeriesMatchesGolden()

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
	 * The scenarios: body overrides, acting user, OpenRegister, resource and plan.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function worlds(): array {
		$cases = [
			'created' => [],
			'created with nothing generated' => ['plan' => 'empty'],
			'created without a customer' => ['body' => ['customerId' => null]],
			'resource with its own hours' => ['resource' => 'hours'],
			'anonymous' => ['user' => null],
			'another user forging adm-1' => ['user' => 'mallory'],
			'administration not accessible' => ['body' => ['administrationId' => 'adm-2']],
			'zero duration' => ['body' => ['durationMinutes' => '0']],
			'text duration' => ['body' => ['durationMinutes' => 'abc']],
			'missing administration and forbidden' => ['body' => ['administrationId' => ''], 'user' => 'mallory'],
			'no OpenRegister' => ['or' => false],
			'forbidden and no OpenRegister' => ['user' => 'mallory', 'or' => false],
			'resource missing' => ['resource' => 'none'],
			'resource of another administration' => ['resource' => 'other'],
			'resource without administration' => ['resource' => 'unscoped'],
			'resource read fails' => ['resource' => 'throws'],
			'planner rejects the rule' => ['plan' => 'invalid'],
			'planner crashes' => ['plan' => 'crash'],
			'save fails' => ['resource' => 'saveFails'],
		];
		foreach (['seriesId', 'serviceId', 'resourceId', 'startTime', 'recurrenceRule'] as $field) {
			$cases['missing ' . $field] = ['body' => [$field => '  ']];
		}

		return $cases;

	}//end worlds()
}//end class

/**
 * Object service double: Resource and Appointment reads, recorded saves.
 */
final class GoldenSeriesObjects {

	/**
	 * Every read as schema and filters.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * Every save as schema and object.
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
	 * @param string $resource The resource shape.
	 */
	public function __construct(private readonly string $resource) {
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
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = []): array {
		$this->reads[] = $this->current + ['config' => $config];
		if ($this->current['schema'] === 'Appointment') {
			return [
				['startTime' => '2026-06-08T09:00:00Z', 'endTime' => '2026-06-08T09:30:00Z'],
				['startTime' => '2026-06-08T10:00:00Z', 'endTime' => '2026-06-08T10:30:00Z', 'status' => 'cancelled'],
				['startTime' => '2026-06-08T11:00:00Z'],
			];
		}

		$row = ['resourceId' => 'room-1', 'administrationId' => 'adm-1'];
		return match ($this->resource) {
			'none' => [],
			'throws' => throw new RuntimeException('resource read failed'),
			'other' => [['administrationId' => 'adm-2'] + $row],
			'unscoped' => [['resourceId' => 'room-1']],
			'hours' => [$row + ['openingTime' => '08:00', 'closingTime' => '12:00', 'allowOverlap' => 1]],
			default => [$row],
		};
	}//end findAll()

	/**
	 * Record a save.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
		if ($this->resource === 'saveFails' && $schema === 'Appointment') {
			throw new RuntimeException('disk full');
		}

		return $object;
	}//end saveObject()
}//end class

/**
 * Logger that records every line as level: message context.
 */
final class GoldenSeriesLogger extends AbstractLogger {

	/**
	 * The recorded lines.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * Record one log line; an exception in the context is recorded by class and message.
	 *
	 * @param mixed $level The level.
	 * @param string|Stringable $message The message.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		foreach ($context as $key => $value) {
			if ($value instanceof \Throwable) {
				$context[$key] = get_class($value) . ': ' . $value->getMessage();
			}
		}

		$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
	}//end log()
}//end class
