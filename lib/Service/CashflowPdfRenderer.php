<?php

/**
 * Shillinq Cashflow PDF Renderer
 *
 * Generates a PDF summary of a CashflowForecastHorizon (+ optional scenario)
 * for bank or accountant meetings. Per REQ-CF-016 the renderer is pure
 * data-to-PDF mapping: it does no forecasting and no aggregation — it reads
 * the already-computed horizon + weeks + scenario.resultaat from OR and
 * lays them out.
 *
 * ADR-031 compliance: this service contains no business logic; it is a thin
 * format adapter. OpenRegister's PDF export is preferred when available — this
 * implementation is a fallback that produces a simple text/PDF stream so the
 * "Export PDF" UI button always has something to download even when OR's
 * renderer is offline.
 *
 * ## Two outputs, and why both exist (#865)
 *
 * {@see render()} returns the plain-text report and is unchanged. It is the
 * layout, and it is what every existing caller-facing test asserts on.
 *
 * {@see renderPdf()} is what the "Export PDF" affordance downloads: the same
 * report, materialised as a real `%PDF-1.7` binary through
 * {@see \OCA\Shillinq\Util\PdfDocument}, plus the REQ-CF-016 §2 closing-balance
 * bar chart drawn as vector rectangles. Advertising `application/pdf` over
 * `render()`'s bytes would have produced a file that fails to open in every
 * viewer while every route/status assertion stayed green, which is exactly the
 * shape #865 was filed about.
 *
 * ⚠️ The PDF is NOT PDF/A: no ICC OutputIntent is emitted and the standard-14
 * Courier/Helvetica faces are referenced, not embedded. Nothing here asserts a
 * conformance level.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-27
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Util\PdfDocument;

/**
 * Pure data-to-PDF mapper for cashflow-horizon export.
 *
 * @psalm-api
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-27
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class CashflowPdfRenderer {
	/**
	 * A4 page width in PDF user-space units (points).
	 *
	 * @var integer
	 */
	private const PAGE_WIDTH = 595;

	/**
	 * A4 page height in PDF user-space units (points).
	 *
	 * @var integer
	 */
	private const PAGE_HEIGHT = 842;

	/**
	 * Left/right margin in points.
	 *
	 * @var integer
	 */
	private const MARGIN_X = 40;

	/**
	 * Top margin in points.
	 *
	 * @var integer
	 */
	private const MARGIN_Y = 48;

	/**
	 * Courier body size in points. Chosen so the widest row the text report
	 * produces (the 6-column week table) fits the A4 text column.
	 *
	 * @var integer
	 */
	private const FONT_SIZE = 8;

	/**
	 * Baseline-to-baseline distance in points.
	 *
	 * @var integer
	 */
	private const LEADING = 10;

	/**
	 * Report lines per text page — `(PAGE_HEIGHT - 2*MARGIN_Y) / LEADING`,
	 * rounded down.
	 *
	 * @var integer
	 */
	private const LINES_PER_PAGE = 74;

	/**
	 * Y coordinate of the bar chart's zero line.
	 *
	 * @var integer
	 */
	private const CHART_ZERO_Y = 420;

	/**
	 * Maximum bar length in points, in either direction from the zero line.
	 *
	 * @var integer
	 */
	private const CHART_HALF_HEIGHT = 300;

	/**
	 * Construct the renderer.
	 *
	 * `$pdf` defaults to a fresh {@see PdfDocument} so the existing
	 * `new CashflowPdfRenderer()` call shape — used by the container and by
	 * this class's own tests — keeps working unchanged. It is a collaborator
	 * rather than a static utility because phpmd's CleanCode ruleset reports
	 * static access, and `lib/` currently carries zero phpmd findings.
	 *
	 * @param PdfDocument $pdf The PDF object serialiser.
	 */
	public function __construct(
		private readonly PdfDocument $pdf = new PdfDocument(),
	) {

	}//end __construct()

	/**
	 * Render a cashflow horizon to a textual PDF-compatible summary.
	 *
	 * Returns a string payload suitable for downstream PDF wrapping (e.g.
	 * docudesk renderer, OR PDF export, or a TCPDF integration in a later
	 * cycle). The structure is intentionally text-only; binary PDF wrapping
	 * is out of scope for this skeleton.
	 *
	 * Sections per REQ-CF-016:
	 *  1. Horizon summary table (week-by-week inflows/outflows/net/saldo/buffer).
	 *  2. Bar-chart description (rendered to image by docudesk in production).
	 *  3. Assumptions: customer betalingsgedrag offsets (top 5), recurring breakdown,
	 *     BTW/IB methodology, pipeline deals.
	 *  4. Optional scenario comparison.
	 *  5. Optional stress-test.
	 *
	 * @param array<string,mixed> $horizon CashflowForecastHorizon as array.
	 * @param list<array<string,mixed>> $weeks 13 CashflowWeek records ordered by weeknummer.
	 * @param array<string,mixed>|null $scenario Optional CashflowScenario to overlay.
	 * @param list<array<string,mixed>> $topCustomers Top-5 customers by AR balance with betalingsgedrag offsets.
	 * @param list<array<string,mixed>> $recurringBreakdown CashflowRecurring rows expanded for the horizon.
	 *
	 * @return array{filename:string,mimeType:string,payload:string}
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-27
	 */
	public function render(
		array $horizon,
		array $weeks,
		?array $scenario = null,
		array $topCustomers = [],
		array $recurringBreakdown = [],
	): array {
		$lines = [];
		$lines[] = '13-WEEK CASHFLOW FORECAST';
		$lines[] = '==========================';
		$lines[] = '';
		$lines[] = 'Horizon: ' . ($horizon['horizonStart'] ?? '?') . ' .. ' . ($horizon['horizonEnd'] ?? '?');
		$lines[] = 'Administration: ' . ($horizon['administrationId'] ?? '?');
		$lines[] = 'Model: ' . ($horizon['modelVersion'] ?? '?');
		$lines[] = 'Rolled on: ' . ($horizon['rolledOn'] ?? '?');
		$lines[] = '';

		$lines[] = 'WEEK-BY-WEEK SUMMARY';
		$lines[] = '--------------------';
		$lines[] = sprintf(
			'%-6s %-12s %-12s %-12s %-12s %s',
			'Week',
			'Inflows',
			'Outflows',
			'Net',
			'Eind Saldo',
			'Buffer'
		);

		foreach ($weeks as $week) {
			$lines[] = sprintf(
				'%-6s %-12.2f %-12.2f %-12.2f %-12.2f %s',
				(string)($week['weekNumber'] ?? '?'),
				(float)($week['inflows_total'] ?? 0),
				(float)($week['outflows_total'] ?? 0),
				(float)($week['netMovement'] ?? 0),
				(float)($week['closingBalance'] ?? 0),
				(string)($week['bufferStatus'] ?? '?')
			);
		}

		if (empty($topCustomers) === false) {
			$lines[] = '';
			$lines[] = 'TOP CUSTOMERS (BETALINGSGEDRAG)';
			$lines[] = '--------------------------------';
			foreach ($topCustomers as $cust) {
				$lines[] = sprintf(
					'%-30s avg offset %s, confidence %.2f',
					(string)($cust['customerId'] ?? '?'),
					(string)($cust['gemiddeldeAfwijking'] ?? '?'),
					(float)($cust['reliabilityScore'] ?? 0)
				);
			}
		}

		if (empty($recurringBreakdown) === false) {
			$lines[] = '';
			$lines[] = 'RECURRING COSTS';
			$lines[] = '---------------';
			foreach ($recurringBreakdown as $rec) {
				$lines[] = sprintf(
					'%-30s %-12s %.2f EUR (%s)',
					(string)($rec['label'] ?? '?'),
					(string)($rec['frequency'] ?? '?'),
					(float)($rec['standardAmount'] ?? 0),
					(string)($rec['indexationRule'] ?? 'FIXED')
				);
			}
		}

		if ($scenario !== null) {
			$lines[] = '';
			$lines[] = 'SCENARIO: ' . ($scenario['name'] ?? '?');
			$lines[] = '---------';
			$lines[] = ($scenario['description'] ?? '');
			if (isset($scenario['result']) === true && is_array($scenario['result']) === true) {
				$lines[] = 'Min buffer week: ' . ($scenario['result']['minBufferWeek'] ?? '?');
				$lines[] = 'Min buffer bedrag: ' . ($scenario['result']['minBufferAmount'] ?? '?');
				if (($scenario['result']['onderschrijdingBuffer'] ?? false) === true) {
					$bufferBreached = 'YES';
				} else {
					$bufferBreached = 'NO';
				}

				$lines[] = 'Buffer breached: ' . $bufferBreached;
			}
		}

		$lines[] = '';
		$lines[] = 'METHODOLOGY';
		$lines[] = '-----------';
		$lines[] = 'BTW: Belastingdienst calendar (Q1 due Apr 30, Q2 due Jul 31, Q3 due Oct 31, Q4 due Jan 31).';
		$lines[] = 'IB/VPB: peilmaanden May/Sep/Nov, basis = prior-year aanslag x growth rate.';
		$lines[] = 'AR projection: customer-specific 12-month rolling betalingsgedrag with confidence score.';
		$lines[] = 'Recurring costs: declarative registry with CPI indexing on annual items.';

		$payload = implode("\n", $lines);
		$filename = 'cashflow-' . ($horizon['horizonId'] ?? 'horizon') . '-' . date('Y-m-d') . '.txt';

		return [
			'filename' => $filename,
			'mimeType' => 'text/plain; charset=utf-8',
			'payload' => $payload,
		];

	}//end render()

	/**
	 * Render the same report as a real PDF binary (REQ-CF-016).
	 *
	 * This is the method the "Export PDF" affordance downloads. Layout:
	 *
	 *  - page 1 carries the REQ-CF-016 §2 closing-balance bar chart, drawn as
	 *    vector rectangles coloured by each week's `bufferStatus`, and is
	 *    omitted entirely when there are no weeks (a chart of nothing is a
	 *    misleading empty axis, not an honest blank);
	 *  - the remaining pages carry {@see render()}'s text report in Courier,
	 *    whose fixed advance is what keeps the week table's columns aligned.
	 *
	 * The content streams are uncompressed on purpose: it costs a few KB on a
	 * one-horizon document and it means the report text is greppable in the
	 * artefact, so a test can assert the forecast actually reached the page
	 * rather than only that a PDF was produced.
	 *
	 * @param array<string,mixed> $horizon CashflowForecastHorizon as array.
	 * @param list<array<string,mixed>> $weeks 13 CashflowWeek records ordered by weeknummer.
	 * @param array<string,mixed>|null $scenario Optional CashflowScenario to overlay.
	 * @param list<array<string,mixed>> $topCustomers Top-5 customers by AR balance with betalingsgedrag offsets.
	 * @param list<array<string,mixed>> $recurringBreakdown CashflowRecurring rows expanded for the horizon.
	 *
	 * @return array{filename:string,mimeType:string,payload:string} Filename, `application/pdf`, and the raw PDF bytes.
	 *
	 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
	 */
	public function renderPdf(
		array $horizon,
		array $weeks,
		?array $scenario = null,
		array $topCustomers = [],
		array $recurringBreakdown = [],
	): array {
		$report = $this->render(
			horizon: $horizon,
			weeks: $weeks,
			scenario: $scenario,
			topCustomers: $topCustomers,
			recurringBreakdown: $recurringBreakdown
		);

		$streams = [];
		if ($weeks !== []) {
			$streams[] = $this->chartStream(horizon: $horizon, weeks: $weeks);
		}

		foreach ($this->paginate(lines: explode("\n", $report['payload'])) as $page) {
			$streams[] = $this->textStream(lines: $page);
		}

		$filename = 'cashflow-' . ($horizon['horizonId'] ?? 'horizon') . '-' . date('Y-m-d') . '.pdf';

		return [
			'filename' => $filename,
			'mimeType' => 'application/pdf',
			'payload' => $this->document(streams: $streams),
		];

	}//end renderPdf()

	/**
	 * Split report lines into fixed-height pages.
	 *
	 * @param list<string> $lines The report lines.
	 *
	 * @return list<list<string>> One entry per page.
	 */
	private function paginate(array $lines): array {
		$pages = array_chunk($lines, self::LINES_PER_PAGE);
		if ($pages === []) {
			return [[]];
		}

		return $pages;
	}//end paginate()

	/**
	 * Build the content stream for one page of monospaced report text.
	 *
	 * @param list<string> $lines The page's lines.
	 *
	 * @return string A PDF content stream.
	 */
	private function textStream(array $lines): string {
		$ops = ['BT', '/F1 ' . self::FONT_SIZE . ' Tf', self::LEADING . ' TL'];
		$ops[] = sprintf('%d %d Td', self::MARGIN_X, (self::PAGE_HEIGHT - self::MARGIN_Y));

		foreach ($lines as $line) {
			$ops[] = '(' . $this->pdf->escapeString(rtrim($line)) . ') Tj';
			$ops[] = 'T*';
		}

		$ops[] = 'ET';

		return implode("\n", $ops);
	}//end textStream()

	/**
	 * Build the content stream for the closing-balance bar chart (REQ-CF-016 §2).
	 *
	 * Bars are scaled against the largest absolute closing balance in the
	 * horizon, so a horizon that never goes negative uses the full plot height
	 * and one that does keeps both directions to scale against each other. The
	 * zero line is always drawn, because a bar chart with no baseline cannot be
	 * read.
	 *
	 * @param array<string,mixed> $horizon The horizon (title metadata only).
	 * @param list<array<string,mixed>> $weeks The weeks to plot.
	 *
	 * @return string A PDF content stream.
	 */
	private function chartStream(array $horizon, array $weeks): string {
		$balances = [];
		foreach ($weeks as $week) {
			$balances[] = (float)($week['closingBalance'] ?? 0);
		}

		$scale = 0.0;
		foreach ($balances as $balance) {
			$scale = max($scale, abs($balance));
		}

		if ($scale <= 0.0) {
			$scale = 1.0;
		}

		$plotWidth = (self::PAGE_WIDTH - (2 * self::MARGIN_X));
		$slot = ($plotWidth / max(count($weeks), 1));
		$barWidth = max(($slot * 0.6), 1.0);
		$zeroY = self::CHART_ZERO_Y;

		$ops = [
			'BT',
			'/F2 14 Tf',
			sprintf('%d %d Td', self::MARGIN_X, (self::PAGE_HEIGHT - self::MARGIN_Y)),
			'(13-WEEK CLOSING BALANCE) Tj',
			'ET',
			'BT',
			'/F1 9 Tf',
			sprintf('%d %d Td', self::MARGIN_X, (self::PAGE_HEIGHT - self::MARGIN_Y - 18)),
			'(' . $this->pdf->escapeString(
				'Horizon ' . (string)($horizon['horizonStart'] ?? '?') . ' .. ' . (string)($horizon['horizonEnd'] ?? '?')
				. '   blue = above buffer, amber = pre-alert, red = below buffer'
			) . ') Tj',
			'ET',
			// Zero line.
			'0.4 0.4 0.4 RG',
			'0.8 w',
			sprintf('%d %d m', self::MARGIN_X, $zeroY),
			sprintf('%d %d l', (self::PAGE_WIDTH - self::MARGIN_X), $zeroY),
			'S',
		];

		foreach ($weeks as $index => $week) {
			$balance = (float)($week['closingBalance'] ?? 0);
			$height = (($balance / $scale) * self::CHART_HALF_HEIGHT);
			$barX = (self::MARGIN_X + ($index * $slot) + (($slot - $barWidth) / 2));

			// A negative balance hangs DOWN from the zero line: the rectangle's
			// origin is its bottom-left corner, so the bar's foot moves down by
			// its own (negative) height and the height itself is drawn positive.
			$barY = $zeroY;
			if ($height < 0) {
				$barY = ($zeroY + $height);
			}

			$ops[] = $this->bufferColour(status: (string)($week['bufferStatus'] ?? ''));
			$ops[] = sprintf('%.2f %.2f %.2f %.2f re', $barX, $barY, $barWidth, abs($height));
			$ops[] = 'f';

			$ops[] = 'BT';
			$ops[] = '/F1 7 Tf';
			$ops[] = sprintf('%.2f %.2f Td', $barX, ($zeroY - self::CHART_HALF_HEIGHT - 14));
			$ops[] = '(' . $this->pdf->escapeString('w' . (string)($week['weekNumber'] ?? '?')) . ') Tj';
			$ops[] = 'ET';
		}

		return implode("\n", $ops);
	}//end chartStream()

	/**
	 * Map a week's buffer status onto a PDF fill colour operator.
	 *
	 * @param string $status The CashflowWeek bufferStatus value.
	 *
	 * @return string An `r g b rg` fill-colour operator.
	 */
	private function bufferColour(string $status): string {
		$upper = strtoupper($status);
		if (str_contains($upper, 'BELOW') === true || str_contains($upper, 'CRISIS') === true) {
			return '0.80 0.16 0.16 rg';
		}

		if (str_contains($upper, 'PRE_ALERT') === true || str_contains($upper, 'ALERT') === true) {
			return '0.90 0.62 0.12 rg';
		}

		return '0.13 0.40 0.72 rg';
	}//end bufferColour()

	/**
	 * Assemble the page tree, fonts and content streams into a PDF document.
	 *
	 * Object numbering: 1 Catalog, 2 Pages, 3 Courier, 4 Helvetica-Bold, then
	 * a (Page, Contents) pair per stream — contiguous from 1, which the xref
	 * table depends on.
	 *
	 * @param list<string> $streams One content stream per page.
	 *
	 * @return string The complete PDF document bytes.
	 */
	private function document(array $streams): string {
		$objects = [];
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$next = 5;
		$kids = [];
		foreach ($streams as $stream) {
			$pageNumber = $next;
			$contentNumber = ($next + 1);
			$next += 2;

			$kids[] = $pageNumber . ' 0 R';
			$objects[$pageNumber] = '<< /Type /Page /Parent 2 0 R'
				. ' /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . ']'
				. ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>'
				. ' /Contents ' . $contentNumber . ' 0 R >>';
			$objects[$contentNumber] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
		}

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

		return $this->pdf->assemble(objects: $objects);
	}//end document()
}//end class
