<?php

/**
 * Golden tests for AppointmentGuard: lookups, check order, refusal lines and fail paths.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-create-appointment/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\AppointmentGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every lookup and every log line of the save precondition, in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class AppointmentGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * The appointment used by most cases: 10:00–10:30 UTC.
	 *
	 * @var array<string,mixed>
	 */
	private const APPOINTMENT = [
		'appointmentId' => 'ap1',
		'serviceId' => 'sv1',
		'resourceId' => 'rs1',
		'customerId' => 'cu1',
		'administrationId' => 'ad1',
		'startTime' => '2026-05-22T10:00:00Z',
		'endTime' => '2026-05-22T10:30:00Z',
	];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed>                         $appointment The appointment.
	 * @param array<string,array<int,mixed>|\Throwable|string> $schemas Rows per schema, a failure, or a non-array answer.
	 *
	 * @return bool
	 */
	private function validate(array $appointment, array $schemas=[]): bool {
		$store     = new class($this, $schemas) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param AppointmentGuardGoldenTest $test    Recorder.
			 * @param array<string,mixed>        $schemas Rows per schema.
			 */
			public function __construct(private AppointmentGuardGoldenTest $test, private array $schemas) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return mixed
			 */
			public function findAll(array $params=[]): mixed {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				$rows = ($this->schemas[$this->schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				if (is_array($rows) === true && isset($params['limit']) === true) {
					return array_slice($rows, 0, $params['limit']);
				}

				return $rows;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store): object {
				$this->calls[] = ['get', $id];
				return $store;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return (new AppointmentGuard($container, $appConfig, $logger))->validateOnSave($appointment);
	}//end validate()

	/**
	 * The calls of the given kinds.
	 *
	 * @param array<int,string> $kinds Call names.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function only(array $kinds): array {
		return array_values(array_filter($this->calls, fn (array $call): bool => in_array($call[0], $kinds, true)));
	}//end only()

	/**
	 * A clean save runs every lookup in order with the exact filters.
	 *
	 * @return void
	 */
	public function testHappyPathLookupOrder(): void {
		$schemas = [
			'Service' => [['duration' => '34'], ['duration' => 1]],
			'Resource' => [['openingTime' => '10:00', 'closingTime' => '10:30']],
			'Appointment' => [
				['appointmentId' => 'ap1', 'startTime' => '2026-05-22T10:00:00Z', 'endTime' => '2026-05-22T10:30:00Z'],
				['appointmentId' => 'x', 'status' => 'cancelled', 'startTime' => '2026-05-22T10:00:00Z', 'endTime' => '2026-05-22T10:30:00Z'],
				['appointmentId' => 'y', 'startTime' => '2026-05-22T10:30:00Z', 'endTime' => '2026-05-22T11:00:00Z'],
				['appointmentId' => 'z', 'startTime' => 'nope', 'endTime' => '2026-05-22T11:00:00Z'],
			],
			'Contact' => [['status' => 'active']],
		];
		self::assertTrue(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas));
		self::assertSame(
			expected: [
				['findAll', 'Service', ['filters' => ['serviceId' => 'sv1', 'administrationId' => 'ad1'], 'limit' => 1]],
				['findAll', 'Resource', ['filters' => ['resourceId' => 'rs1', 'administrationId' => 'ad1'], 'limit' => 1]],
				['findAll', 'Resource', ['filters' => ['resourceId' => 'rs1', 'administrationId' => 'ad1'], 'limit' => 1]],
				['findAll', 'Appointment', ['filters' => ['resourceId' => 'rs1', 'administrationId' => 'ad1']]],
				['findAll', 'Contact', ['filters' => ['customerId' => 'cu1', 'administrationId' => 'ad1'], 'limit' => 1]],
			],
			actual: $this->only(kinds: ['findAll', 'info', 'warning', 'debug', 'error'])
		);
		self::assertSame(expected: ['get', 'OCA\OpenRegister\Service\ObjectService'], actual: $this->calls[0]);
		self::assertSame(expected: ['setRegister', 'shillinq'], actual: $this->calls[1]);
	}//end testHappyPathLookupOrder()

	/**
	 * A cancelled appointment passes with no lookups; bad windows are refused before any lookup.
	 *
	 * @return void
	 */
	public function testWindowRefusals(): void {
		self::assertTrue(condition: $this->validate(appointment: ['status' => 'cancelled']));
		self::assertFalse(condition: $this->validate(appointment: ['endTime' => '2026-05-22T10:00:00Z']));
		$appointment = ['appointmentId' => 'q', 'startTime' => '2026-05-22T10:00:00Z', 'endTime' => '2026-05-22T10:00:00Z'];
		self::assertFalse(condition: $this->validate(appointment: $appointment));
		self::assertSame(
			expected: [
				['info', 'AppointmentGuard: missing or unparseable startTime/endTime — denying save', ['appointmentId' => 'unknown']],
				['info', 'AppointmentGuard: endTime not after startTime — denying save', ['appointmentId' => 'q']],
			],
			actual: $this->calls
		);
	}//end testWindowRefusals()

	/**
	 * Missing service and resource are warned and skipped; a duration off by more than 5 is refused.
	 *
	 * @return void
	 */
	public function testServiceAndResourceSkipsAndDurationRefusal(): void {
		self::assertTrue(condition: $this->validate(appointment: self::APPOINTMENT, schemas: ['Service' => [['name' => 'x']]]));
		self::assertSame(
			expected: [
				[
					'warning',
					'AppointmentGuard: related Service not found or has no duration — skipping duration check',
					['appointmentId' => 'ap1', 'serviceId' => 'sv1'],
				],
				[
					'warning',
					'AppointmentGuard: related Resource not found — skipping operational-hours check',
					['appointmentId' => 'ap1', 'resourceId' => 'rs1'],
				],
			],
			actual: $this->only(kinds: ['info', 'warning', 'error'])
		);
		$this->calls = [];
		self::assertFalse(condition: $this->validate(appointment: self::APPOINTMENT, schemas: ['Service' => [['duration' => 36]]]));
		self::assertSame(
			expected: [[
				'info',
				'AppointmentGuard: duration mismatch — denying save',
				['appointmentId' => 'ap1', 'requested' => 30, 'service' => 36],
			]],
			actual: $this->only(kinds: ['info', 'warning', 'error'])
		);
		self::assertCount(expectedCount: 1, haystack: $this->only(kinds: ['findAll']));
	}//end testServiceAndResourceSkipsAndDurationRefusal()

	/**
	 * Outside the opening hours is refused with all five minute values; malformed hours are unrestricted.
	 *
	 * @return void
	 */
	public function testOperationalHours(): void {
		$schemas = ['Service' => [['duration' => 30]], 'Resource' => [['openingTime' => '10:01', 'closingTime' => '23:59']]];
		self::assertFalse(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas));
		self::assertSame(
			expected: [[
				'info',
				'AppointmentGuard: outside operational hours — denying save',
				['appointmentId' => 'ap1', 'start' => 600, 'end' => 630, 'opening' => 601, 'closing' => 1439],
			]],
			actual: $this->only(kinds: ['info', 'warning', 'error'])
		);
		$this->calls = [];
		$schemas = ['Service' => [['duration' => 30]], 'Resource' => [['openingTime' => '24:00', 'closingTime' => '10:00']]];
		self::assertTrue(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas));
		self::assertSame(expected: [], actual: $this->only(kinds: ['info', 'warning', 'error']));
	}//end testOperationalHours()

	/**
	 * An overlap is refused naming the conflicting record; allowOverlap skips the lookup.
	 *
	 * @return void
	 */
	public function testSlotConflictAndOverlapAllowed(): void {
		$booked = [
			['startTime' => '2026-05-22T09:00:00Z', 'endTime' => '2026-05-22T10:00:00Z'],
			['startTime' => '2026-05-22T10:29:00Z', 'endTime' => '2026-05-22T12:00:00Z'],
		];
		$schemas = ['Service' => [['duration' => 30]], 'Resource' => [['x' => 1]], 'Appointment' => $booked];
		self::assertFalse(condition: $this->validate(appointment: self::APPOINTMENT + ['appointmentId' => ''], schemas: $schemas));
		self::assertSame(
			expected: [[
				'info',
				'AppointmentGuard: resource slot already booked — denying save',
				['appointmentId' => 'ap1', 'resourceId' => 'rs1', 'conflictsWith' => 'unknown'],
			]],
			actual: $this->only(kinds: ['info', 'warning', 'error'])
		);
		$this->calls = [];
		$schemas = ['Service' => [['duration' => 30]], 'Resource' => [['allowOverlap' => 1]], 'Appointment' => $booked];
		self::assertTrue(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas));
		self::assertSame(expected: [], actual: array_filter($this->only(kinds: ['findAll']), fn (array $c): bool => $c[1] === 'Appointment'));
	}//end testSlotConflictAndOverlapAllowed()

	/**
	 * A suspended or banned contact is refused; a failed lookup is logged at debug and read as empty.
	 *
	 * @return void
	 */
	public function testCustomerEligibilityAndLookupFailure(): void {
		$schemas = ['Service' => [['duration' => 30]], 'Resource' => [['x' => 1]], 'Contact' => [['status' => 'banned']]];
		self::assertFalse(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas));
		self::assertSame(
			expected: [[
				'info',
				'AppointmentGuard: customer account is not active — denying save',
				['appointmentId' => 'ap1', 'customerId' => 'cu1', 'status' => 'banned'],
			]],
			actual: $this->only(kinds: ['info', 'warning', 'error'])
		);
		$this->calls = [];
		$schemas = [
			'Service' => new \RuntimeException('no schema'),
			'Resource' => 'scalar',
			'Contact' => [['status' => 'suspended']],
			'Appointment' => 'scalar',
		];
		self::assertTrue(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas) === false);
		self::assertSame(
			expected: [
				'debug',
				'AppointmentGuard: schema lookup unavailable (T1 state) — treating as empty',
				['schema' => 'Service', 'exception' => 'no schema'],
			],
			actual: $this->only(kinds: ['debug'])[0]
		);
	}//end testCustomerEligibilityAndLookupFailure()

	/**
	 * An unexpected failure denies the save with the error line.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureFailsClosed(): void {
		$entity = new \stdClass();
		$schemas = ['Service' => [['duration' => 30]], 'Resource' => [['x' => 1]], 'Appointment' => [$entity]];
		self::assertFalse(condition: $this->validate(appointment: self::APPOINTMENT, schemas: $schemas));
		$error = $this->only(kinds: ['error'])[0];
		self::assertSame(expected: 'AppointmentGuard: validateOnSave failed — denying save (fail-closed)', actual: $error[1]);
		self::assertSame(expected: ['appointmentId', 'exception'], actual: array_keys($error[2]));
		self::assertSame(expected: 'Cannot use object of type stdClass as array', actual: $error[2]['exception']);
	}//end testUnexpectedFailureFailsClosed()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
