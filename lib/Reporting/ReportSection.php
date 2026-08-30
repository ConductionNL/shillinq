<?php

/**
 * Report section block accumulator
 *
 * A docudesk-agnostic, in-memory stand-in for PhpWord's `Element\Section`,
 * kept API-shaped identically (`addText()`, `addTextBreak()`, `addTitle()`,
 * `addTable()`) so the five document report generators' `build()` bodies
 * port with a mechanical find/replace of their type hints rather than a
 * rewrite of their layout calls. Instead of mutating a Word document, each
 * call appends one plain-array "block" to an ordered list; `toArray()`
 * returns that list for serialisation into docudesk's `adHocData.report`
 * (AbstractDocumentReportGenerator::generate(), REQ-RVD-002).
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
 * Accumulates an ordered list of report content blocks.
 */
final class ReportSection {

	/**
	 * Accumulated blocks, in insertion order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $blocks = [];

	/**
	 * Append a paragraph of text.
	 *
	 * @param string $text The text.
	 * @param string $style A style key (e.g. 'value', 'note', 'amountBold', 'coverTitle').
	 * @param array<string, mixed>|string $paragraphStyle A paragraph style key or option array (e.g. 'default', 'tight').
	 *
	 * @return self
	 */
	public function addText(string $text, string $style = 'value', array|string $paragraphStyle = 'default'): self {
		$this->blocks[] = [
			'type' => 'text',
			'text' => $text,
			'style' => $style,
			'paragraphStyle' => $paragraphStyle,
		];

		return $this;
	}//end addText()

	/**
	 * Append a vertical spacer.
	 *
	 * @param int $count Number of line breaks.
	 *
	 * @return self
	 */
	public function addTextBreak(int $count = 1): self {
		$this->blocks[] = ['type' => 'textBreak', 'count' => $count];
		return $this;
	}//end addTextBreak()

	/**
	 * Append a heading.
	 *
	 * @param string $text The heading text.
	 * @param int $depth Heading depth/level (1 = top-level title, 2 = section heading).
	 *
	 * @return self
	 */
	public function addTitle(string $text, int $depth = 2): self {
		$this->blocks[] = ['type' => 'heading', 'text' => $text, 'depth' => $depth];
		return $this;
	}//end addTitle()

	/**
	 * Append a cover block (title + optional subtitle + meta line).
	 *
	 * @param string $title The cover title.
	 * @param string $subtitle The cover subtitle (empty to omit).
	 * @param array<int, string> $meta Meta line parts (already formatted, joined by the renderer).
	 *
	 * @return self
	 */
	public function addCover(string $title, string $subtitle, array $meta): self {
		$this->blocks[] = [
			'type' => 'cover',
			'title' => $title,
			'subtitle' => $subtitle,
			'meta' => $meta,
		];

		return $this;
	}//end addCover()

	/**
	 * Append a note (empty-state or footnote — matches the semantic "note"
	 * style previously rendered in muted, smaller text).
	 *
	 * @param string $text The note text.
	 *
	 * @return self
	 */
	public function addNote(string $text): self {
		$this->blocks[] = ['type' => 'note', 'text' => $text];
		return $this;
	}//end addNote()

	/**
	 * Append a key/value details table.
	 *
	 * @param array<string, string> $rows Label => value pairs, insertion order kept.
	 *
	 * @return self
	 */
	public function addDetailsTable(array $rows): self {
		$this->blocks[] = ['type' => 'detailsTable', 'rows' => $rows];
		return $this;
	}//end addDetailsTable()

	/**
	 * Append a financial amount table.
	 *
	 * @param string $labelHead Header for the label column.
	 * @param string $amountHead Header for the amount column.
	 * @param array<int, array{label: string, amount: float|int|null, bold?: bool}> $lines The body lines.
	 * @param array{label: string, amount: float|int}|null $total Optional total row.
	 * @param string $currency ISO-4217 currency for formatting, or '' for a plain count column.
	 *
	 * @return self
	 */
	public function addAmountTable(
		string $labelHead,
		string $amountHead,
		array $lines,
		?array $total,
		string $currency,
	): self {
		$this->blocks[] = [
			'type' => 'amountTable',
			'labelHead' => $labelHead,
			'amountHead' => $amountHead,
			'lines' => $lines,
			'total' => $total,
			'currency' => $currency,
		];

		return $this;
	}//end addAmountTable()

	/**
	 * Start a new generic table block and return its builder.
	 *
	 * @param string $styleKey A named style key (informational).
	 *
	 * @return ReportTable
	 */
	public function addTable(string $styleKey = 'reportTable'): ReportTable {
		$table = new ReportTable($styleKey);
		$this->blocks[] = $table;
		return $table;
	}//end addTable()

	/**
	 * Serialise every accumulated block to a plain array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		$out = [];
		foreach ($this->blocks as $block) {
			$item = $block;
			if ($block instanceof ReportTable) {
				$item = $block->toArray();
			}

			$out[] = $item;
		}

		return $out;
	}//end toArray()
}//end class
