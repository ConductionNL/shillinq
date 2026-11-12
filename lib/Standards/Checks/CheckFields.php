<?php

/**
 * Check Fields
 *
 * The field readers the rule checks judge an object by: whether a field is
 * filled, whether it holds a boolean and which one, its numeric value and its
 * date. The AML / banking, ledger-integrity, financial-statement and
 * IFRS / US GAAP checks each carried their own copy of these; this is the one
 * reading they share.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

use DateTimeImmutable;

/**
 * Reads one field of a checked object.
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class CheckFields {
	/**
	 * True when the field is present and not blank.
	 *
	 * @param array<string, mixed> $o   The object.
	 * @param string               $key The field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function present(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * True when the field holds a boolean or a boolean-ish value (0 / 1 / "0" / "1" / "true" / "false").
	 *
	 * @param array<string, mixed> $o   The object.
	 * @param string               $key The field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function isBool(array $o, string $key): bool {
		return array_key_exists($key, $o) === true
			&& (is_bool($o[$key]) === true || in_array($o[$key], [0, 1, '0', '1', 'true', 'false'], true) === true);

	}//end isBool()

	/**
	 * True when the field is strictly truthy (true / 1 / "1" / "true").
	 *
	 * @param array<string, mixed> $o   The object.
	 * @param string               $key The field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function isTrue(array $o, string $key): bool {
		return self::isBool(o: $o, key: $key) === true
			&& in_array($o[$key], [true, 1, '1', 'true'], true) === true;

	}//end isTrue()

	/**
	 * True when the field is strictly falsy (false / 0 / "0" / "false").
	 *
	 * @param array<string, mixed> $o   The object.
	 * @param string               $key The field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function isFalse(array $o, string $key): bool {
		return self::isBool(o: $o, key: $key) === true
			&& in_array($o[$key], [false, 0, '0', 'false'], true) === true;

	}//end isFalse()

	/**
	 * The field as a float, 0.0 when it is absent or not numeric.
	 *
	 * @param array<string, mixed> $o   The object.
	 * @param string               $key The field.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function numericVal(array $o, string $key): float {
		if (is_numeric($o[$key] ?? null) === false) {
			return 0.0;
		}

		return (float)$o[$key];
	}//end numericVal()

	/**
	 * The field's date (its first ten characters), or null when blank or unparseable.
	 *
	 * @param array<string, mixed> $o   The object.
	 * @param string               $key The field.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function date(array $o, string $key): ?DateTimeImmutable {
		if (self::present(o: $o, key: $key) === false) {
			return null;
		}

		try {
			return new DateTimeImmutable(substr(trim((string)$o[$key]), 0, 10));
		} catch (\Exception $e) {
			return null;
		}

	}//end date()

	/**
	 * Parse a Y-m-d (or ISO 8601) date string to an immutable date, or null when
	 * empty / unparseable.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function parseDate(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}

	}//end parseDate()
}//end class
