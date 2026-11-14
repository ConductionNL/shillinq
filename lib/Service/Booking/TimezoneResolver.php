<?php

/**
 * Customer timezone resolver.
 *
 * Resolves the IANA timezone identifier to use when rendering an appointment
 * in ICS (VTIMEZONE block, DTSTART;TZID, DTEND;TZID per REQ-BCF-009) and in
 * the confirmation email body per REQ-BCF-003. Resolution order:
 *
 *   1. Per-appointment override (Appointment.timezone, future-proof — not
 *      currently in the schema; passed in by the caller).
 *   2. Customer Nextcloud user-config "core/timezone" (set by the Personal
 *      → Locale settings panel).
 *   3. Server default timezone (date_default_timezone_get()).
 *   4. UTC as the ultimate fail-safe.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeZone;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pick the IANA timezone for a customer when rendering an appointment.
 *
 * Pure read-only resolver: never mutates user config, never throws.
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-18
 */
final class TimezoneResolver {
	/**
	 * Construct the resolver with DI dependencies.
	 *
	 * @param IConfig $config Nextcloud config service for per-user
	 *                        "core/timezone" lookup.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the IANA timezone identifier to use for an appointment.
	 *
	 * @param string|null $customerUserId Nextcloud user ID of the customer,
	 *                                    or NULL if the customer is not a
	 *                                    Nextcloud user (e.g. anonymous
	 *                                    self-service booking).
	 * @param string|null $explicitOverride Optional override timezone
	 *                                      (preferred when present and
	 *                                      valid). Allows future Appointment
	 *                                      schema fields to take precedence.
	 *
	 * @return string The chosen IANA timezone identifier — always one that
	 *                PHP's DateTimeZone accepts. Defaults to UTC.
	 */
	public function resolve(string|null $customerUserId, string|null $explicitOverride = null): string {
		if ($explicitOverride !== null && $this->isValidTimezone(tz: $explicitOverride) === true) {
			return $explicitOverride;
		}

		$userTimezone = $this->userTimezone(customerUserId: $customerUserId);
		if ($userTimezone !== null) {
			return $userTimezone;
		}

		return $this->serverTimezone();
	}//end resolve()

	/**
	 * The customer's own valid `core/timezone` setting, or null when there is
	 * no customer user, no valid setting, or it cannot be read (debug-logged).
	 *
	 * @param string|null $customerUserId Nextcloud user ID of the customer, or null.
	 *
	 * @return string|null
	 */
	private function userTimezone(string|null $customerUserId): string|null {
		if ($customerUserId === null || $customerUserId === '') {
			return null;
		}

		$tz = $this->readUserTimezone(customerUserId: $customerUserId);
		if ($tz !== '' && $this->isValidTimezone(tz: $tz) === true) {
			return $tz;
		}

		return null;
	}//end userTimezone()

	/**
	 * The raw `core/timezone` user setting, or '' when it is unset or cannot
	 * be read (debug-logged, fail-soft).
	 *
	 * @param string $customerUserId Nextcloud user ID of the customer.
	 *
	 * @return string
	 */
	private function readUserTimezone(string $customerUserId): string {
		try {
			return $this->config->getUserValue($customerUserId, 'core', 'timezone', '');
		} catch (Throwable $e) {
			$this->logger->debug(
				'TimezoneResolver: unable to read core/timezone for user '
				. $customerUserId . ': ' . $e->getMessage()
			);
		}

		return '';
	}//end readUserTimezone()

	/**
	 * The server's valid default timezone, or UTC.
	 *
	 * @return string
	 */
	private function serverTimezone(): string {
		$serverDefault = date_default_timezone_get();
		if ($serverDefault !== '' && $this->isValidTimezone(tz: $serverDefault) === true) {
			return $serverDefault;
		}

		return 'UTC';
	}//end serverTimezone()

	/**
	 * Whether a string is a valid IANA timezone identifier.
	 *
	 * @param string $tz Candidate timezone identifier.
	 *
	 * @return bool TRUE iff PHP's DateTimeZone accepts the value.
	 */
	private function isValidTimezone(string $tz): bool {
		try {
			new DateTimeZone($tz);
			return true;
		} catch (Throwable $e) {
			return false;
		}

	}//end isValidTimezone()
}//end class
