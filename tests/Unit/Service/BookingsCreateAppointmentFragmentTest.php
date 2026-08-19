<?php

/**
 * Unit tests for the bookings-create-appointment register fragment.
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
 * @spec openspec/changes/bookings-create-appointment/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the bookings-create-appointment fragment is valid JSON, declares the
 * Appointment/Service/Resource schemas with their lifecycle, and merges additively
 * onto the monolith without dropping existing schemas or objects (ADR-037).
 */
final class BookingsCreateAppointmentFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/10-bookings-create-appointment.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * The fragment file is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the Appointment, Service, and Resource schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresBookingSchemas(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schemas = $data['components']['schemas'];

		foreach (['Appointment', 'Service', 'Resource'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}

	}//end testFragmentDeclaresBookingSchemas()

	/**
	 * The Appointment schema declares its status lifecycle with the AppointmentGuard
	 * save precondition and the four canonical statuses.
	 *
	 * @return void
	 */
	public function testAppointmentDeclaresLifecycleWithGuard(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$appointment = $data['components']['schemas']['Appointment'];

		self::assertArrayHasKey('x-openregister-lifecycle', $appointment);
		$lifecycle = $appointment['x-openregister-lifecycle'];
		self::assertSame('status', $lifecycle['field']);
		self::assertSame(
			'OCA\\Shillinq\\Guard\\AppointmentGuard::validateOnSave',
			$lifecycle['preconditions']['save'],
			'Appointment save must be guarded by AppointmentGuard'
		);

		foreach (['pending_confirmation', 'confirmed', 'completed', 'cancelled'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Lifecycle must declare $state");
		}

	}//end testAppointmentDeclaresLifecycleWithGuard()

	/**
	 * The Appointment schema declares a required field set covering the booking
	 * time window and both foreign keys.
	 *
	 * WITHDRAWN ASSERTIONS — the `service` and `resource` relations. Both were
	 * declared in the per-schema `x-openregister-relations` block, which
	 * ADR-062 rule 7 retired on 2026-07-08 in favour of a property-level
	 * `$ref`. Neither is expressible in the canonical dialect, so both were
	 * removed rather than migrated: their `relatedField` was the TARGET'S
	 * OPERATOR-ASSIGNED BUSINESS KEY, not its object identity —
	 * `Service.serviceId` is documented as "Operator-assigned unique service
	 * identifier within the administration" (example `svc-001`), and
	 * `Resource.resourceId` likewise. A `$ref` is resolved by OpenRegister
	 * against the target's object id, so declaring one on a property that holds
	 * `svc-001` would name a target the relation engine can never reach. What
	 * the register still guarantees — and what this test now pins — is that
	 * both foreign-key properties are declared and required.
	 *
	 * @return void
	 */
	public function testAppointmentDeclaresRequiredFields(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$appointment = $data['components']['schemas']['Appointment'];

		foreach (['serviceId', 'resourceId'] as $foreignKey) {
			self::assertArrayHasKey(
				key: $foreignKey,
				array: $appointment['properties'],
				message: "Appointment must declare the $foreignKey foreign key"
			);
		}

		foreach (['startTime', 'endTime', 'serviceId', 'resourceId', 'customerId', 'status'] as $field) {
			self::assertContains($field, $appointment['required'], "$field must be required");
		}

	}//end testAppointmentDeclaresRequiredFields()

	/**
	 * Merging the fragment onto the monolith adds the booking schemas and seed
	 * objects without dropping any pre-existing schema or object (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = json_decode((string)file_get_contents($this->fragmentPath), true);

		$schemaCountBefore = count($base['components']['schemas']);
		$objectCountBefore = count($base['objects']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// New schemas present.
		foreach (['Appointment', 'Service', 'Resource'] as $name) {
			self::assertArrayHasKey($name, $schemas);
		}

		// Schema set grew by exactly three, none dropped.
		self::assertCount($schemaCountBefore + 3, $schemas, 'Exactly three schemas must be added');

		// Pre-existing schemas survive the merge.
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must survive merge");
		}

		// Seed objects were concatenated, not replaced.
		self::assertCount($objectCountBefore + 2, $merged['objects'], 'Two seed objects must be appended');

	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
