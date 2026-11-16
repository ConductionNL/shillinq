<?php

/**
 * Receipt input
 *
 * Normalises the raw request input of goods and service receipts: purchase
 * order id lists, string lists and quantities rounded to thousandths
 * (multipleOf 0.001).
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Input normalisation shared by the goods and service receipt services.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceiptInput {

	/**
	 * Trim each purchase order id, dropping empty entries and duplicates.
	 *
	 * @param mixed $rawIds Raw input (array or scalar).
	 *
	 * @return array<int,string> The distinct ids, reindexed.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public static function poIds(mixed $rawIds): array {
		return array_values(array_unique(self::strings(input: $rawIds)));

	}//end poIds()

	/**
	 * Coerce a string-array input to its trimmed, non-empty entries; any
	 * non-array input is an empty list.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array<int,string> The trimmed entries.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public static function strings(mixed $input): array {
		if (is_array($input) === false) {
			return [];
		}

		$out = [];
		foreach ($input as $entry) {
			$value = trim((string)$entry);
			if ($value !== '') {
				$out[] = $value;
			}
		}

		return $out;

	}//end strings()

	/**
	 * Coerce a quantity input to a non-negative float rounded to thousandths,
	 * so multipleOf 0.001 enforcement is exact.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return float The quantity.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public static function quantity(mixed $value): float {
		$float = (float)$value;
		if ($float < 0.0) {
			return 0.0;
		}

		return (((float)round($float * 1000.0)) / 1000.0);

	}//end quantity()
}//end class
