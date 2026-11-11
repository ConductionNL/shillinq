<?php

/**
 * Invoice Parts
 *
 * The repeating parts of a checked object as the rule checks read them: a named
 * array-of-objects property, the invoice lines (BG-25) and the VAT breakdown
 * groups (BG-23), each normalised to a list of arrays, and whether a breakdown
 * group carries a VAT category. The compliance-tail and invoicing-extra checks
 * both read invoices this way; this is the one reading they share.
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

/**
 * Reads the lists inside a checked object.
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoiceParts {
	/**
	 * A named array-of-objects property normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o   The host object.
	 * @param string               $key The property.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function items(array $o, string $key): array {
		$items = ($o[$key] ?? []);
		if (is_array($items) === false) {
			return [];
		}

		return array_values(array_filter($items, 'is_array'));
	}//end items()

	/**
	 * The invoice lines (BG-25) normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function lines(array $o): array {
		return self::items(o: $o, key: 'invoiceLines');
	}//end lines()

	/**
	 * The VAT breakdown groups (BG-23) normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function vatBreakdown(array $o): array {
		return self::items(o: $o, key: 'vatBreakdown');
	}//end vatBreakdown()

	/**
	 * True when at least one VAT breakdown group carries the given category code.
	 *
	 * @param array<string, mixed> $o   The ARInvoice.
	 * @param string               $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public static function breakdownHasCategory(array $o, string $cat): bool {
		foreach (self::vatBreakdown(o: $o) as $group) {
			if ((string)($group['category'] ?? '') === $cat) {
				return true;
			}
		}

		return false;
	}//end breakdownHasCategory()
}//end class
