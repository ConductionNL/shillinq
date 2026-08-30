<?php

/**
 * Unit tests for the bookings-confirm-flow register fragment (ADR-037).
 *
 * Verifies the fragment declares the Appointment and ConfirmationToken schemas
 * with their lifecycle state machines and demo seeds, and that the fragment
 * unions cleanly onto the base register via SettingsService::deepMergeConfig
 * without colliding with existing schemas.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Structural tests for the confirmation-flow register fragment.
 */
final class BookingsConfirmFlowFragmentTest extends TestCase {
	/**
	 * Decoded fragment data.
	 *
	 * @var array<string,mixed>
	 */
	private array $fragment;

	/**
	 * Load and decode the fragment file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/bookings-confirm-flow.json';
		self::assertFileExists($path, 'Register fragment must ship in lib/Settings/register.d');
		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded, 'Fragment must be valid JSON');
		$this->fragment = $decoded;
	}//end setUp()

	/**
	 * The fragment declares both schemas with the required fields (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresSchemasWithRequiredFields(): void {
		$schemas = ($this->fragment['components']['schemas'] ?? []);
		self::assertArrayHasKey('Appointment', $schemas);
		self::assertArrayHasKey('ConfirmationToken', $schemas);

		$token = $schemas['ConfirmationToken'];
		foreach (['appointmentId', 'tokenString', 'expiresAt', 'status'] as $field) {
			self::assertContains($field, $token['required'], 'ConfirmationToken must require ' . $field);
			self::assertArrayHasKey($field, $token['properties']);
		}

		$appt = $schemas['Appointment'];
		foreach (['confirmationDeadline', 'confirmedAt', 'confirmationTokenId'] as $field) {
			self::assertArrayHasKey($field, $appt['properties'], 'Appointment must declare ' . $field);
		}
	}//end testFragmentDeclaresSchemasWithRequiredFields()

	/**
	 * Both schemas declare an x-openregister-lifecycle transition graph (REQ-BCF-004).
	 *
	 * ConfirmationToken is the canonical full lifecycle (status field +
	 * states + transitions). Appointment ships only the transition graph
	 * because its status field is owned by the bookings-self-service-widget
	 * fragment that declares the full state machine — this fragment unions
	 * the confirmation-specific transitions onto the existing Appointment
	 * lifecycle via OR's key-union deep merge (ADR-037).
	 *
	 * @return void
	 */
	public function testSchemasDeclareLifecycleTransitions(): void {
		$apptLifecycle = $this->fragment['components']['schemas']['Appointment']['x-openregister-lifecycle'];
		self::assertArrayHasKey('transitions', $apptLifecycle);
		foreach (['confirmViaToken', 'autoCancelExpired'] as $transition) {
			self::assertArrayHasKey(
				$transition,
				$apptLifecycle['transitions'],
				"Appointment lifecycle must declare $transition transition"
			);
			self::assertArrayHasKey('from', $apptLifecycle['transitions'][$transition]);
			self::assertArrayHasKey('to', $apptLifecycle['transitions'][$transition]);
		}
		self::assertSame('pending_confirmation', $apptLifecycle['transitions']['confirmViaToken']['from']);
		self::assertSame('confirmed', $apptLifecycle['transitions']['confirmViaToken']['to']);
		self::assertSame('cancelled', $apptLifecycle['transitions']['autoCancelExpired']['to']);

		$token = $this->fragment['components']['schemas']['ConfirmationToken']['x-openregister-lifecycle'];
		self::assertSame('status', $token['field']);
		self::assertSame('active', $token['initialState']);
		foreach (['redeem', 'revoke', 'expire'] as $transition) {
			self::assertArrayHasKey(
				$transition,
				$token['transitions'],
				"ConfirmationToken lifecycle must declare $transition transition"
			);
		}
	}//end testSchemasDeclareLifecycleTransitions()

	/**
	 * The fragment ships demo seed object(s) bound to the shillinq register.
	 *
	 * The fragment carries a single canonical seed for the confirmation flow
	 * (an active ConfirmationToken). Full end-to-end fixtures live in the
	 * sibling bookings-self-service-widget seeds — keeping this fragment's
	 * seed minimal avoids duplicating Appointment seed data already owned
	 * by the canonical bookings fragment (ADR-037 single owner per concept).
	 *
	 * @return void
	 */
	public function testFragmentShipsSeedObjects(): void {
		$objects = ($this->fragment['objects'] ?? []);
		self::assertNotEmpty($objects, 'Fragment must ship at least one seed object');
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertContains($object['@self']['schema'], ['Appointment', 'ConfirmationToken']);
		}
	}//end testFragmentShipsSeedObjects()

	/**
	 * The fragment unions onto a base register without clobbering existing schemas.
	 *
	 * @return void
	 */
	public function testFragmentUnionsOntoBaseRegister(): void {
		$base = [
			'components' => ['schemas' => ['Account' => ['type' => 'object']]],
			'objects' => [['@self' => ['schema' => 'Account']]],
		];

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$merged = $merge->invoke(null, $base, $this->fragment);

		// Existing schema survives; new schemas are added.
		self::assertArrayHasKey('Account', $merged['components']['schemas']);
		self::assertArrayHasKey('Appointment', $merged['components']['schemas']);
		self::assertArrayHasKey('ConfirmationToken', $merged['components']['schemas']);
		// Objects list concatenates: base (1) + fragment seeds (>= 1).
		$baseCount = count($base['objects']);
		$fragmentCount = count(($this->fragment['objects'] ?? []));
		self::assertSame(
			($baseCount + $fragmentCount),
			count($merged['objects']),
			'Object list concatenation must preserve base + fragment seed count'
		);
	}//end testFragmentUnionsOntoBaseRegister()
}//end class
