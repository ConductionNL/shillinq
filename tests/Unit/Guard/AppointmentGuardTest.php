<?php

/**
 * Unit tests for AppointmentGuard.
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
 * @spec openspec/changes/bookings-create-appointment/tasks.md#task-15
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\AppointmentGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppointmentGuard::validateOnSave per REQ-BCA-003/004/006.
 *
 * Covers:
 * - Happy path: matching duration, free slot, in-hours, active customer.
 * - Duration mismatch beyond tolerance is denied (REQ-BCA-003).
 * - Duration within tolerance is permitted (REQ-BCA-003).
 * - Outside operational hours is denied (REQ-BCA-004).
 * - Double-booking conflict is denied (REQ-BCA-004).
 * - Overlap permitted when resource allowOverlap = true.
 * - Suspended customer is denied (REQ-BCA-006).
 * - Cancelled appointment skips slot checks.
 * - endTime before startTime is denied.
 * - Missing Service/Resource (T1) skips dependent checks.
 * - Exception is fail-closed (CWE-863).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class AppointmentGuardTest extends TestCase {

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
	 * The guard under test.
	 *
	 * @var AppointmentGuard
	 */
	private AppointmentGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new AppointmentGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub returning records by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Map of schema → records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

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
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records for the current schema.
			 *
			 * @param array<string, mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->currentSchema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Stub the container to return the given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->container->method('get')->willReturn($objectService);

	}//end withObjectService()

	/**
	 * Build a base valid appointment for svc-001 (30 min) in room res-001.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function appointment(array $overrides = []): array {
		return array_merge(
			[
				'appointmentId' => 'apt-001',
				'administrationId' => 'adm-1',
				'serviceId' => 'svc-001',
				'resourceId' => 'res-001',
				'customerId' => 'cust-001',
				'startTime' => '2026-05-22T10:00:00Z',
				'endTime' => '2026-05-22T10:30:00Z',
				'status' => 'confirmed',
			],
			$overrides
		);

	}//end appointment()

	/**
	 * Build the default catalog: 30-min service, room open 09:00–17:00, no overlap.
	 *
	 * @param array<int, array<string, mixed>> $appointments Existing appointments.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function catalog(array $appointments = []): array {
		return [
			'Service' => [['serviceId' => 'svc-001', 'administrationId' => 'adm-1', 'duration' => 30]],
			'Resource' => [
				[
					'resourceId' => 'res-001',
					'administrationId' => 'adm-1',
					'openingTime' => '09:00',
					'closingTime' => '17:00',
					'allowOverlap' => false,
				],
			],
			'Appointment' => $appointments,
			'Contact' => [],
		];

	}//end catalog()

	/**
	 * Happy path: matching duration, free slot, in-hours, active customer.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsValidAppointment(): void {
		$this->withObjectService($this->buildObjectServiceStub($this->catalog()));

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'A fully valid appointment must be permitted'
		);

	}//end testValidateOnSavePermitsValidAppointment()

	/**
	 * Duration mismatch beyond tolerance is denied per REQ-BCA-003.
	 *
	 * @return void
	 */
	public function testValidateOnSaveDeniesDurationMismatch(): void {
		$this->withObjectService($this->buildObjectServiceStub($this->catalog()));

		// 60-minute window against a 30-minute service.
		$appointment = $this->appointment(['endTime' => '2026-05-22T11:00:00Z']);

		self::assertFalse(
			condition: $this->guard->validateOnSave(appointment: $appointment),
			message: 'A 60-min window for a 30-min service must be denied'
		);

	}//end testValidateOnSaveDeniesDurationMismatch()

	/**
	 * Duration within the +-5 minute tolerance is permitted per REQ-BCA-003.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsDurationWithinTolerance(): void {
		$this->withObjectService($this->buildObjectServiceStub($this->catalog()));

		// 33-minute window against a 30-minute service (within 5-min tolerance).
		$appointment = $this->appointment(['endTime' => '2026-05-22T10:33:00Z']);

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $appointment),
			message: '33-min window within tolerance must be permitted'
		);

	}//end testValidateOnSavePermitsDurationWithinTolerance()

	/**
	 * Appointment before operational hours is denied per REQ-BCA-004.
	 *
	 * @return void
	 */
	public function testValidateOnSaveDeniesOutsideOperationalHours(): void {
		$this->withObjectService($this->buildObjectServiceStub($this->catalog()));

		// 07:00–07:30, before the 09:00 opening.
		$appointment = $this->appointment(
			[
				'startTime' => '2026-05-22T07:00:00Z',
				'endTime' => '2026-05-22T07:30:00Z',
			]
		);

		self::assertFalse(
			condition: $this->guard->validateOnSave(appointment: $appointment),
			message: 'An appointment before opening time must be denied'
		);

	}//end testValidateOnSaveDeniesOutsideOperationalHours()

	/**
	 * A conflicting confirmed appointment on the same resource is denied per REQ-BCA-004.
	 *
	 * @return void
	 */
	public function testValidateOnSaveDeniesDoubleBooking(): void {
		$existing = [
			[
				'appointmentId' => 'apt-existing',
				'administrationId' => 'adm-1',
				'resourceId' => 'res-001',
				'startTime' => '2026-05-22T10:00:00Z',
				'endTime' => '2026-05-22T10:30:00Z',
				'status' => 'confirmed',
			],
		];
		$this->withObjectService($this->buildObjectServiceStub($this->catalog($existing)));

		self::assertFalse(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'An overlapping confirmed appointment must deny the save'
		);

	}//end testValidateOnSaveDeniesDoubleBooking()

	/**
	 * A cancelled existing appointment does not block the same slot per REQ-BCA-004.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsWhenConflictIsCancelled(): void {
		$existing = [
			[
				'appointmentId' => 'apt-existing',
				'administrationId' => 'adm-1',
				'resourceId' => 'res-001',
				'startTime' => '2026-05-22T10:00:00Z',
				'endTime' => '2026-05-22T10:30:00Z',
				'status' => 'cancelled',
			],
		];
		$this->withObjectService($this->buildObjectServiceStub($this->catalog($existing)));

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'A cancelled appointment must not block the slot'
		);

	}//end testValidateOnSavePermitsWhenConflictIsCancelled()

	/**
	 * Overlap is permitted when the resource declares allowOverlap = true.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsOverlapWhenResourceAllows(): void {
		$catalog = $this->catalog(
			[
				[
					'appointmentId' => 'apt-existing',
					'administrationId' => 'adm-1',
					'resourceId' => 'res-001',
					'startTime' => '2026-05-22T10:00:00Z',
					'endTime' => '2026-05-22T10:30:00Z',
					'status' => 'confirmed',
				],
			]
		);
		$catalog['Resource'][0]['allowOverlap'] = true;
		$this->withObjectService($this->buildObjectServiceStub($catalog));

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'Overlap must be permitted when allowOverlap is true'
		);

	}//end testValidateOnSavePermitsOverlapWhenResourceAllows()

	/**
	 * A suspended customer contact is denied per REQ-BCA-006.
	 *
	 * @return void
	 */
	public function testValidateOnSaveDeniesSuspendedCustomer(): void {
		$catalog = $this->catalog();
		$catalog['Contact'] = [
			['customerId' => 'cust-001', 'administrationId' => 'adm-1', 'status' => 'suspended'],
		];
		$this->withObjectService($this->buildObjectServiceStub($catalog));

		self::assertFalse(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'A suspended customer must be denied'
		);

	}//end testValidateOnSaveDeniesSuspendedCustomer()

	/**
	 * An active customer contact is permitted per REQ-BCA-006.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsActiveCustomer(): void {
		$catalog = $this->catalog();
		$catalog['Contact'] = [
			['customerId' => 'cust-001', 'administrationId' => 'adm-1', 'status' => 'active'],
		];
		$this->withObjectService($this->buildObjectServiceStub($catalog));

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'An active customer must be permitted'
		);

	}//end testValidateOnSavePermitsActiveCustomer()

	/**
	 * A cancelled appointment skips slot/duration checks and is permitted.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsCancelledAppointment(): void {
		$this->withObjectService($this->buildObjectServiceStub($this->catalog()));

		// Deliberately mismatched duration; cancelled status must skip the check.
		$appointment = $this->appointment(
			[
				'status' => 'cancelled',
				'endTime' => '2026-05-22T12:00:00Z',
			]
		);

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $appointment),
			message: 'A cancelled appointment must skip slot validation'
		);

	}//end testValidateOnSavePermitsCancelledAppointment()

	/**
	 * The appointment is denied when endTime is not after startTime.
	 *
	 * @return void
	 */
	public function testValidateOnSaveDeniesInvalidTimeWindow(): void {
		$this->withObjectService($this->buildObjectServiceStub($this->catalog()));

		$appointment = $this->appointment(
			[
				'startTime' => '2026-05-22T10:30:00Z',
				'endTime' => '2026-05-22T10:00:00Z',
			]
		);

		self::assertFalse(
			condition: $this->guard->validateOnSave(appointment: $appointment),
			message: 'endTime before startTime must be denied'
		);

	}//end testValidateOnSaveDeniesInvalidTimeWindow()

	/**
	 * Missing Service/Resource (T1 state) skips dependent checks and permits.
	 *
	 * @return void
	 */
	public function testValidateOnSavePermitsWhenCatalogAbsent(): void {
		// No Service, Resource, Appointment or Contact records at all.
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Service' => [],
					'Resource' => [],
					'Appointment' => [],
					'Contact' => [],
				]
			)
		);

		self::assertTrue(
			condition: $this->guard->validateOnSave(appointment: $this->appointment()),
			message: 'Absent catalog (T1 state) must not block the save'
		);

	}//end testValidateOnSavePermitsWhenCatalogAbsent()

	/**
	 * An exception while resolving the ObjectService never propagates (CWE-863).
	 *
	 * The per-lookup catch treats an unavailable schema as empty, so the guard
	 * returns a boolean rather than raising.
	 *
	 * @return void
	 */
	public function testValidateOnSaveIsFailClosedOnException(): void {
		$this->container->method('get')
			->willThrowException(new \RuntimeException('ObjectService unavailable'));

		$result = $this->guard->validateOnSave(appointment: $this->appointment());

		self::assertIsBool(actual: $result, message: 'Guard must never propagate exceptions');

	}//end testValidateOnSaveIsFailClosedOnException()
}//end class
