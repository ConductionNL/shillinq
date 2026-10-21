<?php

/**
 * Schema authorization for the bookings and widget-key domain.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every bookings schema must close the OpenRegister object API to callers
 * outside its groups.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BookingsAuthorizationTest extends TestCase {
	use ShippedRegisterAuthorization;

	public const SCHEMAS = [
		'Appointment', 'AppointmentSeries', 'AvailabilityRule', 'Booking', 'BookingCancellation',
		'BookingCancellationTemplate', 'BookingConfirmationTemplate', 'BookingConstraint',
		'BookingNotificationTrigger', 'BookingOrder', 'BookingReminderTemplate', 'BookingSmsReminderChannel',
		'Calendar', 'CancellationPolicy', 'ConfirmationToken', 'DepositPayment', 'NotificationDelivery',
		'Resource', 'ResourceBreak', 'Service', 'TimelineDeadLetter', 'TimelinePublishRetryEntry',
		'WidgetAccessKey', 'WidgetAccessKeyAuditEntry',
	];

	/**
	 * Each bookings schema ships an enforceable authorization block.
	 *
	 * @return void
	 */
	public function testBookingsSchemasEnforceAuthorization(): void {
		$this->assertSchemasEnforceAuthorization(self::SCHEMAS);
	}//end testBookingsSchemasEnforceAuthorization()

	/**
	 * Widget keys and their audit trail are written by admins only, so nobody
	 * can mint or revive a public booking key through the object API.
	 *
	 * @return void
	 */
	public function testWidgetKeysAreAdminWritableOnly(): void {
		$this->assertAdminOnly('WidgetAccessKey', ['create', 'update', 'delete']);
		$this->assertAdminOnly('WidgetAccessKeyAuditEntry', ['create', 'update', 'delete']);
	}//end testWidgetKeysAreAdminWritableOnly()

	/**
	 * The delivery log and retry queues are written by the server only.
	 *
	 * @return void
	 */
	public function testServerWrittenLogsAreReadOnly(): void {
		foreach (['NotificationDelivery', 'TimelineDeadLetter', 'TimelinePublishRetryEntry'] as $name) {
			$this->assertAdminOnly($name, ['create', 'update', 'delete']);
		}
	}//end testServerWrittenLogsAreReadOnly()
}//end class
