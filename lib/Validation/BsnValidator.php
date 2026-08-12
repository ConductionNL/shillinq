<?php

/**
 * BSN Validator
 *
 * ADR-031 exception: single-method guard implementing the Dutch BSN
 * 11-proef (eleven-test) checksum and display masking for the Employee
 * register (REQ-PAY-001 / REQ-PAY-012). The 11-proef weighted checksum is
 * not expressible in the declarative schema layer, and BSN is
 * special-category PII (ADR-005) that must never be logged or rendered in
 * full, so a small dedicated helper is the correct seam.
 *
 * @category Validation
 * @package  OCA\Shillinq\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Validation;

/**
 * Dutch BSN 11-proef validation and PII masking.
 *
 * Referenced from the Employee schema on write (BSN validity, REQ-PAY-001)
 * and on display (masking, REQ-PAY-012). All methods are pure and static so
 * the helper carries no state and never persists or logs a raw BSN.
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 */
final class BsnValidator {
	/**
	 * The number of visible trailing digits when masking a BSN for display.
	 */
	private const VISIBLE_TRAILING_DIGITS = 4;

	/**
	 * Validate a BSN against the Dutch 11-proef checksum.
	 *
	 * A BSN is exactly 9 digits. The weighted sum uses descending weights
	 * 9..2 for the first eight digits and weight -1 for the ninth; the sum
	 * must be a non-zero multiple of 11 (REQ-PAY-001). A null or empty BSN is
	 * treated as "not provided" and is considered valid for B2B contractors
	 * who legitimately have no BSN — callers requiring a BSN must check
	 * presence separately.
	 *
	 * @param string|null $bsn The candidate BSN, or null when absent.
	 *
	 * @return bool True when the BSN is absent or passes the 11-proef.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public static function isValid(?string $bsn): bool {
		if ($bsn === null) {
			return true;
		}

		$bsn = trim($bsn);
		if ($bsn === '') {
			return true;
		}

		if (preg_match('/^[0-9]{9}$/', $bsn) !== 1) {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 9; $i++) {
			$digit = (int)$bsn[$i];
			$weight = (9 - $i);
			if ($i === 8) {
				$weight = -1;
			}

			$sum += ($digit * $weight);
		}

		if ($sum === 0) {
			return false;
		}

		return ($sum % 11) === 0;
	}//end isValid()

	/**
	 * Mask a BSN for display, revealing only the trailing digits.
	 *
	 * Renders the canonical `***<last4>` form (REQ-PAY-012). A null or empty
	 * BSN renders as an empty string; short or non-numeric values are fully
	 * masked so a malformed value can never leak.
	 *
	 * @param string|null $bsn The BSN to mask, or null when absent.
	 *
	 * @return string The masked representation, e.g. `*****6782`.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public static function mask(?string $bsn): string {
		if ($bsn === null) {
			return '';
		}

		$bsn = trim($bsn);
		if ($bsn === '') {
			return '';
		}

		$length = strlen($bsn);
		if ($length <= self::VISIBLE_TRAILING_DIGITS) {
			return str_repeat('*', $length);
		}

		$visible = substr($bsn, -self::VISIBLE_TRAILING_DIGITS);
		return str_repeat('*', ($length - self::VISIBLE_TRAILING_DIGITS)) . $visible;
	}//end mask()
}//end class
