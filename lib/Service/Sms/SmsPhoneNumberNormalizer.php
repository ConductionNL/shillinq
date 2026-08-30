<?php

/**
 * SMS phone-number normalizer and validator.
 *
 * Pure-logic helper (no Nextcloud/OpenRegister dependency) that turns an
 * operator- or contact-supplied phone number into a validated E.164 string,
 * and masks numbers for logging. Phone numbers are personal data (GDPR /
 * ADR-005): every log line that references a recipient MUST use mask().
 *
 * Scope is NL-focused per the proposal (E.164 + Dutch domestic 06… / +31…);
 * international validation is a documented future expansion.
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

/**
 * Side-effect-free phone-number validation, normalization and masking.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-16
 */
final class SmsPhoneNumberNormalizer {
	/**
	 * Strip spaces, dashes, dots, slashes and parentheses from a raw number.
	 *
	 * @param string $raw Raw operator/contact phone input.
	 *
	 * @return string The number with separators removed.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-17
	 */
	public function stripSeparators(string $raw): string {
		return (string)preg_replace('/[\s\-.\/()]+/', '', $raw);
	}//end stripSeparators()

	/**
	 * Whether a (separator-stripped) number is a valid NL/E.164 number.
	 *
	 * Accepts Dutch domestic mobile/landline (06… / 0… of 9-10 digits after
	 * the trunk 0) and E.164 (+31… or any +CC number of 8-15 digits).
	 *
	 * @param string $raw Raw or normalized phone input.
	 *
	 * @return bool True when the number is a valid NL or E.164 number.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-16
	 */
	public function isValid(string $raw): bool {
		$n = $this->stripSeparators(raw: $raw);

		// E.164: '+' then 8-15 digits, first digit non-zero.
		if (preg_match('/^\+[1-9][0-9]{7,14}$/', $n) === 1) {
			return true;
		}

		// NL domestic: trunk 0 then 9 digits (e.g. 06 + 8, or 0xx + 7).
		if (preg_match('/^0[1-9][0-9]{8}$/', $n) === 1) {
			return true;
		}

		return false;
	}//end isValid()

	/**
	 * Normalize a number to E.164, defaulting to the NL country code (+31)
	 * for domestic input.
	 *
	 * @param string $raw Raw phone input.
	 * @param string $defaultCountry Two-letter country whose code to prepend for domestic numbers. Only 'NL' is supported.
	 *
	 * @return string|null E.164 string, or null when the input is not a valid number.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-17
	 */
	public function toE164(string $raw, string $defaultCountry = 'NL'): ?string {
		$n = $this->stripSeparators(raw: $raw);

		if ($this->isValid(raw: $n) === false) {
			return null;
		}

		// Already E.164.
		if (str_starts_with($n, '+') === true) {
			return $n;
		}

		// Dutch domestic: replace the trunk 0 with the country code.
		if ($defaultCountry === 'NL' && str_starts_with($n, '0') === true) {
			return '+31' . substr($n, 1);
		}

		return null;
	}//end toE164()

	/**
	 * Mask a phone number for safe logging — keep the country prefix and the
	 * last two digits, replace the middle with asterisks. Never log the full
	 * number (ADR-005, GDPR).
	 *
	 * @param string $raw Raw or E.164 phone input.
	 *
	 * @return string Masked representation, e.g. "+31******78".
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-50
	 */
	public function mask(string $raw): string {
		$n = $this->stripSeparators(raw: $raw);

		if ($n === '') {
			return '';
		}

		$prefixLen = 0;
		if (str_starts_with($n, '+') === true) {
			// Keep "+" and the first two country digits.
			$prefixLen = min(3, strlen($n));
		}

		$suffixLen = 2;
		$keep = ($prefixLen + $suffixLen);
		if (strlen($n) <= $keep) {
			// Too short to keep both ends meaningfully: mask all but last digit.
			return str_repeat('*', max(0, (strlen($n) - 1))) . substr($n, -1);
		}

		$prefix = substr($n, 0, $prefixLen);
		$suffix = substr($n, -$suffixLen);
		$stars = str_repeat('*', (strlen($n) - $keep));

		return $prefix . $stars . $suffix;
	}//end mask()
}//end class
