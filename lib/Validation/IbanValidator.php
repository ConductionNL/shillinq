<?php

/**
 * IBAN Validator
 *
 * Pure ISO 13616 IBAN structural + mod-97 checksum validator used by the
 * pain.008 generation gate to reject malformed debtor IBANs before a batch
 * is submitted (REQ-SDD-005). No external dependency.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Validation;

/**
 * ISO 13616 IBAN validator with mod-97 checksum (REQ-SDD-005).
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class IbanValidator {

	/**
	 * Per-country IBAN total length (subset relevant to SEPA debtors).
	 *
	 * @var array<string,int>
	 */
	private const COUNTRY_LENGTHS = [
		'NL' => 18,
		'BE' => 16,
		'DE' => 22,
		'FR' => 27,
		'ES' => 24,
		'IT' => 27,
		'LU' => 20,
		'AT' => 20,
		'PT' => 25,
		'IE' => 22,
		'FI' => 18,
	];

	/**
	 * True iff the given IBAN is structurally valid and passes mod-97.
	 *
	 * Whitespace is stripped and the value upper-cased before validation. A
	 * known country length, an alphanumeric body, and a mod-97 remainder of 1
	 * are all required (REQ-SDD-005).
	 *
	 * @param string $iban The candidate IBAN (spaces tolerated).
	 *
	 * @return bool True when the IBAN is valid.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public static function isValid(string $iban): bool {
		$normalised = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

		if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $normalised) !== 1) {
			return false;
		}

		$country = substr($normalised, 0, 2);
		if (isset(self::COUNTRY_LENGTHS[$country]) === true
			&& strlen($normalised) !== self::COUNTRY_LENGTHS[$country]
		) {
			return false;
		}

		return self::mod97(normalised: $normalised) === 1;
	}//end isValid()

	/**
	 * Compute the ISO 7064 mod-97 remainder for a normalised IBAN.
	 *
	 * Moves the first four characters to the end, expands letters to their
	 * A=10..Z=35 numeric form, and computes the remainder mod 97 in chunks to
	 * avoid big-integer overflow.
	 *
	 * @param string $normalised The upper-cased, whitespace-free IBAN.
	 *
	 * @return int The mod-97 remainder (1 for a valid IBAN).
	 */
	private static function mod97(string $normalised): int {
		$rearranged = substr($normalised, 4) . substr($normalised, 0, 4);

		$numeric = '';
		$length = strlen($rearranged);
		for ($i = 0; $i < $length; $i++) {
			$char = $rearranged[$i];
			if (ctype_alpha($char) === true) {
				$numeric .= (string)(ord($char) - 55);
				continue;
			}

			$numeric .= $char;
		}

		$remainder = 0;
		$chunkLen = strlen($numeric);
		for ($i = 0; $i < $chunkLen; $i += 7) {
			$part = $remainder . substr($numeric, $i, 7);
			$remainder = ((int)$part % 97);
		}

		return $remainder;
	}//end mod97()
}//end class
