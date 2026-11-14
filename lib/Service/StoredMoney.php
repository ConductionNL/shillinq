<?php

/**
 * Stored Money
 *
 * Reads a stored money value as integer cents: a stored number of euros
 * (multipleOf 0.01) is converted, an integer is already cents. Shared by
 * {@see RateCardResolver} and {@see RetainerResolver}.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Stored money as cents.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
class StoredMoney {

	/**
	 * Convert a stored money value to integer cents.
	 *
	 * @param mixed $value Stored value: euros, or already-integer cents.
	 *
	 * @return int Cents.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function cents(mixed $value): int {
		if (is_int($value) === true) {
			return $value;
		}

		return (int)round((float)$value * 100);
	}//end cents()
}//end class
