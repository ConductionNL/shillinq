<?php

/**
 * Report table cell handle
 *
 * Returned by `ReportTable::addCell()`. Mirrors PhpWord's `Element\Cell::
 * addText()` chaining shape (`$table->addCell($w)->addText($text, $style)`)
 * without holding any PhpWord/document state itself — it writes back into
 * its owning `ReportTable` by (row, cell) coordinate.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

/**
 * A handle onto one cell of a ReportTable.
 */
final class ReportTableCell {

	/**
	 * @param ReportTable $table The owning table.
	 * @param int $rowIndex The row index this cell lives in.
	 * @param int $cellIndex The cell index within the row.
	 */
	public function __construct(
		private readonly ReportTable $table,
		private readonly int $rowIndex,
		private readonly int $cellIndex,
	) {

	}//end __construct()

	/**
	 * Set this cell's text content and style.
	 *
	 * @param string $text The cell text.
	 * @param string $style The text style key (e.g. 'value', 'amount', 'amountBold').
	 * @param array<string, mixed>|string $options Paragraph/text options (e.g. `['alignment' => 'end']`).
	 *
	 * @return self
	 */
	public function addText(string $text, string $style = 'value', array|string $options = []): self {
		$this->table->fillCell($this->rowIndex, $this->cellIndex, $text, $style, $options);
		return $this;
	}//end addText()
}//end class
