<?php

/**
 * Ledger Group Members
 *
 * Resolves one LedgerGroup's member account numbers: every account number
 * in an `accountRanges` pair, PLUS every `includedAccountNumbers` entry,
 * MINUS every `excludedAccountNumbers` entry (budget-core-schema §3a).
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Member resolution of one LedgerGroup row.
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 */
final class LedgerGroupMembers {

	/**
	 * The `accountRanges` pairs.
	 *
	 * @var array<int|string,mixed>
	 */
	private readonly array $ranges;

	/**
	 * The `includedAccountNumbers` entries.
	 *
	 * @var array<int|string,mixed>
	 */
	private readonly array $included;

	/**
	 * The `excludedAccountNumbers` entries, as a string-keyed lookup.
	 *
	 * @var array<string,int>
	 */
	private readonly array $excluded;

	/**
	 * Read the membership fields of one LedgerGroup row; a field that is not
	 * a list counts as empty.
	 *
	 * @param array<string,mixed> $ledgerGroup The LedgerGroup row.
	 */
	public function __construct(array $ledgerGroup) {
		$this->ranges = self::listField(ledgerGroup: $ledgerGroup, field: 'accountRanges');
		$this->included = self::listField(ledgerGroup: $ledgerGroup, field: 'includedAccountNumbers');
		$this->excluded = array_flip(
			array_map('strval', self::listField(ledgerGroup: $ledgerGroup, field: 'excludedAccountNumbers'))
		);
	}//end __construct()

	/**
	 * The resolved, deduplicated member account numbers: in-range numbers in
	 * the given order, then the explicit includes.
	 *
	 * @param list<string> $accountNumbers The account numbers to match ranges against.
	 *
	 * @return list<string> The member account numbers.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 */
	public function resolve(array $accountNumbers): array {
		$members = $this->rangeMembers(accountNumbers: $accountNumbers);
		foreach ($this->included as $number) {
			if ($this->isAllowed(number: (string)$number) === true) {
				$members[(string)$number] = true;
			}
		}

		// PHP casts a purely-numeric string array key to an int key, so
		// array_keys() would silently hand back integers here (e.g. account
		// "1000" -> key 1000 -> int(1000)) — cast back to string explicitly,
		// since every caller compares account numbers as strings.
		return array_values(array_map('strval', array_keys($members)));
	}//end resolve()

	/**
	 * Whether an account number falls inside any of the range pairs.
	 * Compared numerically (not lexicographically) so a 5-digit account
	 * number cannot fall inside a 4-digit range by string-sort accident.
	 *
	 * @param string $accountNumber The account number to test.
	 *
	 * @return bool True when the account number is inside at least one range.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 */
	public function inAnyRange(string $accountNumber): bool {
		if (is_numeric($accountNumber) === false) {
			return false;
		}

		foreach ($this->ranges as $range) {
			if (self::rangeContains(range: $range, value: (int)$accountNumber) === true) {
				return true;
			}
		}

		return false;
	}//end inAnyRange()

	/**
	 * The allowed account numbers that fall in a range, in the given order.
	 *
	 * @param list<string> $accountNumbers The account numbers to match.
	 *
	 * @return array<string,bool> Member number => true.
	 */
	private function rangeMembers(array $accountNumbers): array {
		$members = [];
		foreach ($accountNumbers as $number) {
			if ($this->isAllowed(number: $number) === true && $this->inAnyRange(accountNumber: $number) === true) {
				$members[$number] = true;
			}
		}

		return $members;
	}//end rangeMembers()

	/**
	 * A non-empty number that is not excluded.
	 *
	 * @param string $number The account number.
	 *
	 * @return bool True when the number may be a member.
	 */
	private function isAllowed(string $number): bool {
		return $number !== '' && isset($this->excluded[$number]) === false;
	}//end isAllowed()

	/**
	 * Whether a numeric `from`/`to` pair contains the value (inclusive). A
	 * pair with a missing or non-numeric bound contains nothing.
	 *
	 * @param mixed $range One `{from, to}` pair.
	 * @param int   $value The account number as an integer.
	 *
	 * @return bool True when the value is inside the pair.
	 */
	private static function rangeContains(mixed $range, int $value): bool {
		$from = (string)($range['from'] ?? '');
		$to = (string)($range['to'] ?? '');
		if (is_numeric($from) === false || is_numeric($to) === false) {
			return false;
		}

		return $value >= (int)$from && $value <= (int)$to;
	}//end rangeContains()

	/**
	 * One list field of the row, or an empty list when it is not an array.
	 *
	 * @param array<string,mixed> $ledgerGroup The LedgerGroup row.
	 * @param string              $field       The field name.
	 *
	 * @return array<int|string,mixed> The field value.
	 */
	private static function listField(array $ledgerGroup, string $field): array {
		if (is_array($ledgerGroup[$field] ?? null) === true) {
			return $ledgerGroup[$field];
		}

		return [];
	}//end listField()
}//end class
