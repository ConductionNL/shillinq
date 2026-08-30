<?php

/**
 * Report table block builder
 *
 * A minimal, docudesk-agnostic stand-in for PhpWord's `Element\Table` /
 * `Element\Cell` API, kept API-shaped identically (`addRow()`, `addCell()`
 * returning a chainable cell that supports `addText()`) so the two
 * hand-rolled tables in BbvJaarstukkenReportGenerator port with a mechanical
 * find/replace rather than a rewrite. Widths are relative hints (plain
 * numbers, formerly twips from `Converter::cmToTwip()`) a docudesk template
 * may use or ignore — there is no physical page to lay out against once
 * nothing renders to an actual Word document.
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
 * Accumulates rows/cells for one generic table block.
 */
final class ReportTable {

	/**
	 * Accumulated rows; each is a list of cell arrays.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * @param string $styleKey The named style this table was created with (informational).
	 */
	public function __construct(
		private readonly string $styleKey = 'reportTable',
	) {

	}//end __construct()

	/**
	 * Start a new (initially empty) row. Cells are appended to the most
	 * recently started row by addCell().
	 *
	 * @return self
	 */
	public function addRow(): self {
		$this->rows[] = [];
		return $this;
	}//end addRow()

	/**
	 * Append a cell to the current row and return a builder for its content.
	 *
	 * @param float|int $widthCm A relative width hint (plain number, formerly cm-to-twip).
	 * @param array<string, mixed> $cellStyle Cell style hints (e.g. `bgColor`, `gridSpan`).
	 *
	 * @return ReportTableCell
	 */
	public function addCell(float|int $widthCm = 0, array $cellStyle = []): ReportTableCell {
		if ($this->rows === []) {
			$this->addRow();
		}

		$lastIndex = array_key_last($this->rows);
		$cellIndex = count($this->rows[$lastIndex]);
		$this->rows[$lastIndex][$cellIndex] = [
			'widthCm' => $widthCm,
			'cellStyle' => $cellStyle,
			'text' => '',
			'textStyle' => 'value',
			'textOptions' => [],
		];

		return new ReportTableCell($this, $lastIndex, $cellIndex);
	}//end addCell()

	/**
	 * Called by ReportTableCell::addText() to fill in the cell it was handed.
	 *
	 * @param int $rowIndex The row index.
	 * @param int $cellIndex The cell index within the row.
	 * @param string $text The cell text.
	 * @param string $style The text style key.
	 * @param array<string, mixed>|string $options Paragraph/text options (e.g. `['alignment' => 'end']`).
	 *
	 * @return void
	 */
	public function fillCell(int $rowIndex, int $cellIndex, string $text, string $style, array|string $options): void {
		if (isset($this->rows[$rowIndex][$cellIndex]) === false) {
			return;
		}

		$this->rows[$rowIndex][$cellIndex]['text'] = $text;
		$this->rows[$rowIndex][$cellIndex]['textStyle'] = $style;

		$textOptions = ['style' => $options];
		if (is_array($options) === true) {
			$textOptions = $options;
		}

		$this->rows[$rowIndex][$cellIndex]['textOptions'] = $textOptions;

	}//end fillCell()

	/**
	 * Serialise this table to a plain-array block.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'type' => 'table',
			'styleKey' => $this->styleKey,
			'rows' => $this->rows,
		];
	}//end toArray()
}//end class
