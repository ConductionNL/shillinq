<?php

/**
 * Shillinq VatReturnBox
 *
 * What a VAT return box means for the lines booked in it: its declaration
 * type, which return total its VAT counts in, and on which side a line in it
 * is booked (REQ-VBTW-004). Kept apart from VATReturnService so the return
 * reads the box rules from one place.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

/**
 * Box semantics of the VAT return.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md#requirement-req-vbtw-004-the-btw-journal-shall-be-derived-from-period-filtered-gl-aggregations
 */
class VatReturnBox {

	/**
	 * The boxes of a reverse-charged purchase.
	 */
	private const REVERSE_CHARGE_BOXES = ['2a', '4a', '4b'];

	/**
	 * The declaration type of a box: input VAT is paid, the boxes of a
	 * reverse-charged purchase are reverse charge, every other box is
	 * collected (owed).
	 *
	 * @param string $box The VAT return box.
	 *
	 * @return string One of collected | paid | reverse-charge.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function type(string $box): string {
		if ($box === VatLineStamper::INPUT_VAT_BOX) {
			return 'paid';
		}

		if (in_array($box, self::REVERSE_CHARGE_BOXES, true) === true) {
			return 'reverse-charge';
		}

		return 'collected';
	}//end type()

	/**
	 * Which return total a box's VAT counts in: 5b is deductible, every
	 * other box is owed.
	 *
	 * @param string $box The VAT return box.
	 *
	 * @return string `paid` or `collected`.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function totalOf(string $box): string {
		if ($box === VatLineStamper::INPUT_VAT_BOX) {
			return 'paid';
		}

		return 'collected';
	}//end totalOf()

	/**
	 * A line's amount in cents, positive when it is booked on its box's own
	 * side and negative when it is booked on the other side (a credit note).
	 *
	 * Input VAT and the base of a reverse-charged purchase are debits; sales,
	 * their VAT and VAT owed on a reverse-charged purchase are credits.
	 *
	 * @param array<string,mixed> $line The GLLine.
	 * @param string              $box  The line's box.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function bookedCents(array $line, string $box): int {
		$ownSide = 'credit';
		if ($box === VatLineStamper::INPUT_VAT_BOX
			|| ($this->type(box: $box) === 'reverse-charge' && ($line['vatAmountKind'] ?? null) === 'base')
		) {
			$ownSide = 'debit';
		}

		$cents = (int)round((float)($line['amount'] ?? 0) * 100);
		if ((string)($line['side'] ?? '') !== $ownSide) {
			return -$cents;
		}

		return $cents;
	}//end bookedCents()
}//end class
