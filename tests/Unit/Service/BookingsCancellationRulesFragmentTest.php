<?php

/**
 * Unit tests for the bookings-cancellation-rules register fragment.
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
 * @spec openspec/changes/bookings-cancellation-rules/specs/bookings-cancellation-rules/spec.md
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
 * Verifies the bookings-cancellation-rules fragment is valid JSON, declares the
 * CancellationPolicy + BookingCancellation schemas and seed policies, and — the
 * load-bearing assertion — that its partial `Appointment` overlay composes
 * additively onto the create-appointment Appointment schema via
 * SettingsService::deepMergeConfig (ADR-037), yielding a single Appointment
 * schema that carries all six cancellation fields without dropping the base
 * fields or polluting the base `required` list.
 *
 * @spec openspec/changes/bookings-cancellation-rules/specs/bookings-cancellation-rules/spec.md
 */
final class BookingsCancellationRulesFragmentTest extends TestCase {

	/**
	 * Absolute path to the cancellation fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/40-bookings-cancellation-rules.json';

	/**
	 * Absolute path to the create-appointment fragment (owns Appointment).
	 *
	 * @var string
	 */
	private string $appointmentFragmentPath = __DIR__ . '/../../../lib/Settings/register.d/10-bookings-create-appointment.json';

	/**
	 * Decode a JSON fragment file into an array.
	 *
	 * @param string $path Absolute path to the fragment.
	 *
	 * @return array<mixed> Decoded fragment.
	 */
	private function load(string $path): array {
		$data = json_decode((string)file_get_contents($path), true);
		self::assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: json_last_error_msg());
		self::assertIsArray(actual: $data);
		return $data;
	}//end load()

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
	 * The fragment is present and valid JSON exposing components.schemas.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists(filename: $this->fragmentPath);
		$data = $this->load(path: $this->fragmentPath);
		self::assertArrayHasKey(key: 'schemas', array: $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares CancellationPolicy with the spec's required fields.
	 *
	 * @return void
	 */
	public function testDeclaresCancellationPolicySchema(): void {
		$schemas = $this->load(path: $this->fragmentPath)['components']['schemas'];
		self::assertArrayHasKey(key: 'CancellationPolicy', array: $schemas);

		$props = $schemas['CancellationPolicy']['properties'];
		$fields = [
			'policyId',
			'name',
			'minNoticeDays',
			'rescheduleWindowDays',
			'lateFeeBrackets',
			'noShowFee',
			'refundPolicy',
			'lifecycleState',
			'administrationId',
		];
		foreach ($fields as $field) {
			self::assertArrayHasKey(key: $field, array: $props, message: 'CancellationPolicy missing field ' . $field);
		}

		self::assertContains(needle: 'card_reversal', haystack: $schemas['CancellationPolicy']['properties']['refundPolicy']['enum']);

	}//end testDeclaresCancellationPolicySchema()

	/**
	 * BookingCancellation is immutable: operator role has no delete permission,
	 * and it links to Appointment (REQ-BCR-008).
	 *
	 * WITHDRAWN ASSERTIONS — the `relatedSchema` / `cardinality` of the
	 * `appointment` relation. It was declared in the per-schema
	 * `x-openregister-relations` block, which ADR-062 rule 7 retired on
	 * 2026-07-08 in favour of a property-level `$ref`. This relation is NOT
	 * expressible in the canonical dialect and was removed rather than
	 * migrated: its `relatedField` was `Appointment.appointmentId`, the
	 * target's operator-assigned business key (example `apt-12345`), not its
	 * object identity. OpenRegister resolves a `$ref` against the target's
	 * object id, so a `$ref` here would name a target it could never reach.
	 *
	 * The LINK half of this test's name stays true and is still asserted, at
	 * the level the register still declares it: `appointmentId` is a declared,
	 * required property, which is what makes exactly one cancellation record
	 * per appointment enforceable.
	 *
	 * @return void
	 */
	public function testBookingCancellationIsImmutableAndLinked(): void {
		$schema = $this->load(path: $this->fragmentPath)['components']['schemas']['BookingCancellation'];
		$operator = $schema['x-openregister-rbac']['roles']['operator']['permissions'];
		self::assertNotContains(needle: 'delete', haystack: $operator, message: 'BookingCancellation must be immutable (no delete)');

		self::assertArrayHasKey(
			key: 'appointmentId',
			array: $schema['properties'],
			message: 'BookingCancellation must declare the appointmentId foreign key to Appointment'
		);
		self::assertContains(
			needle: 'appointmentId',
			haystack: $schema['required'],
			message: 'appointmentId must be required — a cancellation without its appointment is unattributable (REQ-BCR-008)'
		);

	}//end testBookingCancellationIsImmutableAndLinked()

	/**
	 * The Appointment overlay composes additively onto the create-appointment
	 * Appointment schema: the merged schema keeps the base fields (appointmentId,
	 * cancelledAt, cancelledReason) AND gains the cancellation fields, and the
	 * base `required` list is untouched by the (optional) overlay fields.
	 *
	 * @return void
	 */
	public function testAppointmentOverlayComposesAdditively(): void {
		$base = $this->load(path: $this->appointmentFragmentPath);
		$overlay = $this->load(path: $this->fragmentPath);

		$merged = $this->merge(base: $base, overlay: $overlay);
		$appt = $merged['components']['schemas']['Appointment'];
		$props = $appt['properties'];

		// Base fields survive the merge.
		foreach (['appointmentId', 'startTime', 'cancelledAt', 'cancelledReason'] as $baseField) {
			self::assertArrayHasKey(key: $baseField, array: $props, message: 'merge dropped base Appointment field ' . $baseField);
		}

		// Overlay cancellation fields are present.
		foreach (['appliedPolicy', 'appointmentCost', 'refundAmount', 'refundStatus', 'refundedAt'] as $newField) {
			self::assertArrayHasKey(key: $newField, array: $props, message: 'overlay did not add Appointment field ' . $newField);
		}

		// RefundStatus carries the lifecycle enum.
		self::assertSame(
			expected: ['pending', 'processed', 'failed', 'cancelled'],
			actual: $props['refundStatus']['enum']
		);

		// Optional cancellation fields MUST NOT have been appended to the base
		// required list (the deep-merge concatenates list arrays).
		foreach (['appliedPolicy', 'refundAmount', 'refundStatus', 'refundedAt'] as $optional) {
			self::assertNotContains(
				needle: $optional,
				haystack: $appt['required'],
				message: 'optional cancellation field ' . $optional . ' must not be required on Appointment'
			);
		}

	}//end testAppointmentOverlayComposesAdditively()

	/**
	 * The fragment seeds the three example CancellationPolicy objects with valid
	 * bracket structures (Yoga 20%, Coaching 50%, Consultation fixed €50).
	 *
	 * @return void
	 */
	public function testSeedsExamplePolicies(): void {
		$objects = $this->load(path: $this->fragmentPath)['objects'];
		$slugs = array_map(static fn (array $o): string => (string)($o['@self']['slug'] ?? ''), $objects);

		foreach (['policy-yoga-standard', 'policy-coaching-premium', 'policy-consult-standard'] as $slug) {
			self::assertContains(needle: $slug, haystack: $slugs, message: 'missing seed policy ' . $slug);
		}

		// The fixed-fee consultation policy snapshots a €50 fixed late fee.
		$consult = null;
		foreach ($objects as $obj) {
			if (($obj['@self']['slug'] ?? '') === 'policy-consult-standard') {
				$consult = $obj;
				break;
			}
		}

		self::assertNotNull(actual: $consult);
		$lateBracket = $consult['lateFeeBrackets'][1];
		self::assertSame(expected: 'fixed', actual: $lateBracket['feeType']);
		self::assertSame(expected: 5000, actual: $lateBracket['feeAmount']);

	}//end testSeedsExamplePolicies()
}//end class
