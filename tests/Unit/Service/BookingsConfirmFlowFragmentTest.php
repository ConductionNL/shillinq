<?php

/**
 * Unit tests for the bookings-confirm-flow register fragment.
 *
 * Mirrors BookingsCreateAppointmentFragmentTest — exercises the ADR-037
 * fragment merge contract for the confirmation flow:
 *   - Fragment is valid JSON.
 *   - Declares the ConfirmationToken schema with the REQ-BCF-002 field set.
 *   - Declares the active → redeemed | revoked | expired lifecycle.
 *   - Extends Appointment with confirmationDeadline / confirmedAt /
 *     confirmationTokenId.
 *   - Deep-merge through SettingsService::deepMergeConfig keeps existing
 *     Appointment fields and lifecycle states intact.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-21
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
 * Behavioural tests for the bookings-confirm-flow modular fragment
 * (REQ-BCF-001/002/004/006).
 */
final class BookingsConfirmFlowFragmentTest extends TestCase {

	/**
	 * Absolute path to the confirm-flow fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookings-confirm-flow.json';

	/**
	 * Absolute path to the create-appointment fragment (Appointment owner).
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
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the ConfirmationToken schema.
	 */
	public function testFragmentDeclaresConfirmationTokenSchema(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schemas = $data['components']['schemas'];
		self::assertArrayHasKey('ConfirmationToken', $schemas);

		$schema = $schemas['ConfirmationToken'];
		foreach (
			[
				'tokenId',
				'appointmentId',
				'tokenString',
				'expiresAt',
				'status',
				'redeemedAt',
				'createdAt',
				'createdBy',
			] as $field
		) {
			self::assertArrayHasKey($field, $schema['properties'], "Field $field missing");
		}

	}//end testFragmentDeclaresConfirmationTokenSchema()

	/**
	 * The ConfirmationToken status field declares the four required states.
	 */
	public function testConfirmationTokenStatusEnum(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$status = $data['components']['schemas']['ConfirmationToken']['properties']['status'];
		self::assertSame(
			['active', 'redeemed', 'expired', 'revoked'],
			$status['enum']
		);
		self::assertSame('active', $status['default']);

	}//end testConfirmationTokenStatusEnum()

	/**
	 * The ConfirmationToken lifecycle declares redeem / revoke / expire
	 * transitions per REQ-BCF-004/006.
	 */
	public function testConfirmationTokenLifecycleTransitions(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$lifecycle = $data['components']['schemas']['ConfirmationToken']['x-openregister-lifecycle'];
		self::assertSame('status', $lifecycle['field']);
		self::assertSame('active', $lifecycle['initialState']);
		foreach (['redeem', 'revoke', 'expire'] as $transition) {
			self::assertArrayHasKey($transition, $lifecycle['transitions']);
		}
		self::assertSame('active', $lifecycle['transitions']['redeem']['from']);
		self::assertSame('redeemed', $lifecycle['transitions']['redeem']['to']);

	}//end testConfirmationTokenLifecycleTransitions()

	/**
	 * The fragment adds confirmationDeadline / confirmedAt /
	 * confirmationTokenId to Appointment without dropping existing fields.
	 */
	public function testAppointmentIsExtendedAdditively(): void {
		$base = json_decode((string)file_get_contents($this->appointmentPath), true);
		$overlay = json_decode((string)file_get_contents($this->fragmentPath), true);

		$merged = $this->merge($base, $overlay);
		$appt = $merged['components']['schemas']['Appointment'];

		// New confirmation fields are present.
		self::assertArrayHasKey('confirmationDeadline', $appt['properties']);
		self::assertArrayHasKey('confirmedAt', $appt['properties']);
		self::assertArrayHasKey('confirmationTokenId', $appt['properties']);

		// Existing fields survive.
		foreach (['appointmentId', 'startTime', 'endTime', 'status'] as $field) {
			self::assertArrayHasKey($field, $appt['properties'], "Pre-existing field $field was dropped");
		}

		// New lifecycle transitions are added without dropping the base set.
		$transitions = $appt['x-openregister-lifecycle']['transitions'];
		self::assertArrayHasKey('confirmViaToken', $transitions);
		self::assertArrayHasKey('autoCancelExpired', $transitions);
		foreach (['confirm', 'complete', 'cancel', 'cancelPending'] as $base) {
			self::assertArrayHasKey($base, $transitions, "Base transition $base was dropped");
		}

	}//end testAppointmentIsExtendedAdditively()

	/**
	 * The fragment ships at least one seed ConfirmationToken so OR's
	 * register-import sweep has something to materialise.
	 */
	public function testFragmentSeedsAtLeastOneToken(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertNotEmpty($data['objects'] ?? []);
		$seeded = false;
		foreach (($data['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') === 'ConfirmationToken') {
				$seeded = true;
				break;
			}
		}

		self::assertTrue($seeded, 'No ConfirmationToken seed in fragment');

	}//end testFragmentSeedsAtLeastOneToken()

}//end class
