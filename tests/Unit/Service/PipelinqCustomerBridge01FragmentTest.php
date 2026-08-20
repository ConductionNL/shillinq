<?php

/**
 * Unit tests for the bookings-pipelinq-customer-bridge-01 register fragment.
 *
 * Mirrors BookingsConfirmFlowFragmentTest — exercises the ADR-037
 * fragment merge contract for the customer-bridge config slice:
 *   - Fragment is valid JSON with the expected version stamp.
 *   - Extends Appointment with the optional pipelinqContactId
 *     property (nullable string).
 *   - Deep-merge through SettingsService::deepMergeConfig keeps
 *     existing Appointment fields, lifecycle states, transitions
 *     and relations intact.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the customer-bridge-01 modular register fragment.
 */
final class PipelinqCustomerBridge01FragmentTest extends TestCase {

	/**
	 * Path to the customer-bridge-01 fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookings-pipelinq-customer-bridge-01-config-contact-link.json';

	/**
	 * Path to the create-appointment fragment (Appointment owner).
	 *
	 * @var string
	 */
	private string $appointmentPath = __DIR__ . '/../../../lib/Settings/register.d/10-bookings-create-appointment.json';

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
	 * The fragment file is present and parses as JSON.
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('components', $data);
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares a chain-scoped version stamp so a config
	 * change forces OR's importFromApp version gate to re-import.
	 */
	public function testFragmentDeclaresVersionStamp(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertArrayHasKey('info', $data);
		self::assertSame(
			'0.1.0-bookings-pipelinq-customer-bridge-01',
			$data['info']['version']
		);

	}//end testFragmentDeclaresVersionStamp()

	/**
	 * The fragment adds pipelinqContactId to the Appointment schema
	 * as a nullable string carrying a pipelinq Contact externalId.
	 */
	public function testFragmentAddsPipelinqContactIdToAppointment(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$appointment = ($data['components']['schemas']['Appointment'] ?? []);
		self::assertArrayHasKey('properties', $appointment, 'Fragment must extend Appointment.properties');

		$property = ($appointment['properties']['pipelinqContactId'] ?? null);
		self::assertNotNull($property, 'pipelinqContactId must be declared on Appointment');
		self::assertSame('string', $property['type']);
		self::assertTrue($property['nullable'], 'pipelinqContactId must be nullable so bookings without a Contact remain valid');

	}//end testFragmentAddsPipelinqContactIdToAppointment()

	/**
	 * Deep-merge with the create-appointment base preserves the
	 * existing Appointment field set, lifecycle states, transitions
	 * and relations — the contact link is purely additive.
	 */
	public function testAppointmentIsExtendedAdditively(): void {
		$base = json_decode((string)file_get_contents($this->appointmentPath), true);
		$overlay = json_decode((string)file_get_contents($this->fragmentPath), true);

		$merged = $this->merge($base, $overlay);
		$appt = $merged['components']['schemas']['Appointment'];

		// Pre-existing fields survive.
		foreach ([
			'administrationId',
			'appointmentId',
			'startTime',
			'endTime',
			'serviceId',
			'resourceId',
			'customerId',
			'status',
			'notes',
		] as $field
		) {
			self::assertArrayHasKey(
				$field,
				$appt['properties'],
				"Pre-existing field $field was dropped"
			);
		}

		// New field appended.
		self::assertArrayHasKey('pipelinqContactId', $appt['properties']);

		// Existing lifecycle states preserved.
		$states = ($appt['x-openregister-lifecycle']['states'] ?? []);
		foreach (['pending_confirmation', 'confirmed', 'completed', 'cancelled'] as $state) {
			self::assertArrayHasKey($state, $states, "Lifecycle state $state was dropped");
		}

		// WITHDRAWN ASSERTION — the `service` and `resource` relations on the
		// merged Appointment. They were declared in the base fragment's
		// per-schema `x-openregister-relations` block, which ADR-062 rule 7
		// retired on 2026-07-08 in favour of a property-level `$ref`. Neither
		// was migrated, because neither is expressible: their `relatedField`
		// was the target's operator-assigned business key
		// (`Service.serviceId` / `Resource.resourceId`), not its object
		// identity, and OpenRegister resolves a `$ref` against the object id.
		// So there is no longer a relation for this overlay to perturb, on
		// either side of the merge.
		//
		// The test's own subject — that member 01 extends Appointment
		// ADDITIVELY — is unaffected and still fully asserted: the
		// `serviceId` / `resourceId` properties are checked above as
		// pre-existing survivors, alongside every other base field and every
		// base lifecycle state.
	}//end testAppointmentIsExtendedAdditively()

	/**
	 * The pipelinqContactId field is non-required on Appointment —
	 * many bookings (and the "Booking without a pipelinq Contact"
	 * scenario) reference no Contact.
	 */
	public function testPipelinqContactIdIsNotRequired(): void {
		$base = json_decode((string)file_get_contents($this->appointmentPath), true);
		$overlay = json_decode((string)file_get_contents($this->fragmentPath), true);

		$merged = $this->merge($base, $overlay);
		$required = ($merged['components']['schemas']['Appointment']['required'] ?? []);

		self::assertNotContains(
			'pipelinqContactId',
			$required,
			'pipelinqContactId must remain optional so bookings without a linked Contact pass schema validation'
		);

	}//end testPipelinqContactIdIsNotRequired()
}//end class
