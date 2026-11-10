<?php

/**
 * SMS Reminder
 *
 * One booking reminder ready to render and send: the recipient's validated
 * E.164 number and the booking variables for the channel's template.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-31
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

/**
 * A reminder addressed to a validated number.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-31
 */
final class SmsReminder {

	/**
	 * Constructor.
	 *
	 * @param string                $e164      The recipient number in E.164.
	 * @param array<string, scalar> $variables Booking variables for the template.
	 */
	public function __construct(
		public readonly string $e164,
		public readonly array $variables,
	) {

	}//end __construct()
}//end class
