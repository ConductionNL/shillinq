<?php

/**
 * Unit tests for CashflowPdfRenderer.
 *
 * Covers REQ-CF-016 (PDF export sections + scenario overlay).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CashflowPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Renderer payload tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowPdfRendererTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var CashflowPdfRenderer
	 */
	private CashflowPdfRenderer $renderer;

	/**
	 * Set up fresh renderer per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->renderer = new CashflowPdfRenderer();

	}//end setUp()

	/**
	 * The renderer returns a filename, mime type, and non-empty payload.
	 *
	 * @return void
	 */
	public function testRenderReturnsExpectedEnvelope(): void {
		$horizon = [
			'horizonId' => 'horizon-test-001',
			'horizonStart' => '2026-05-25',
			'horizonEnd' => '2026-08-23',
			'administrationId' => 'adm-001',
			'modelVersion' => 'v4.1-klantspecifiek-betaalgedrag',
			'rolledOn' => '2026-05-25T02:00:00Z',
		];
		$weeks = [
			[
				'weekNumber' => 22,
				'inflows_total' => 12500.0,
				'outflows_total' => 7200.0,
				'netMovement' => 5300.0,
				'closingBalance' => 20120.0,
				'bufferStatus' => 'ABOVE_BUFFER',
			],
		];

		$result = $this->renderer->render($horizon, $weeks);

		self::assertArrayHasKey('filename', $result);
		self::assertArrayHasKey('mimeType', $result);
		self::assertArrayHasKey('payload', $result);
		self::assertStringContainsString('horizon-test-001', $result['filename']);
		self::assertStringContainsString('text/plain', $result['mimeType']);
		self::assertStringContainsString('13-WEEK CASHFLOW FORECAST', $result['payload']);
		self::assertStringContainsString('WEEK-BY-WEEK SUMMARY', $result['payload']);
		self::assertStringContainsString('METHODOLOGY', $result['payload']);

	}//end testRenderReturnsExpectedEnvelope()

	/**
	 * Weeks render in the summary table with their saldo + buffer status.
	 *
	 * @return void
	 */
	public function testRenderIncludesPerWeekRows(): void {
		$horizon = ['horizonId' => 'h1', 'administrationId' => 'a1'];
		$weeks = [
			['weekNumber' => 22, 'inflows_total' => 100.0, 'outflows_total' => 50.0, 'netMovement' => 50.0, 'closingBalance' => 1050.0, 'bufferStatus' => 'ABOVE_BUFFER'],
			['weekNumber' => 23, 'inflows_total' => 0.0, 'outflows_total' => 200.0, 'netMovement' => -200.0, 'closingBalance' => 850.0, 'bufferStatus' => 'PRE_ALERT'],
		];

		$result = $this->renderer->render($horizon, $weeks);

		self::assertStringContainsString('22', $result['payload']);
		self::assertStringContainsString('23', $result['payload']);
		self::assertStringContainsString('PRE_ALERT', $result['payload']);

	}//end testRenderIncludesPerWeekRows()

	/**
	 * Optional scenario section appears when a scenario is supplied and
	 * carries naam + resultaat onderschrijdingBuffer flag.
	 *
	 * @return void
	 */
	public function testRenderIncludesScenarioWhenSupplied(): void {
		$horizon = ['horizonId' => 'h1'];
		$scenario = [
			'name' => 'Acme pays late',
			'description' => 'Acme delays invoice by 4 weeks',
			'result' => [
				'minBufferWeek' => '2026-w26',
				'minBufferAmount' => 100.0,
				'onderschrijdingBuffer' => true,
			],
		];

		$result = $this->renderer->render($horizon, [], $scenario);

		self::assertStringContainsString('SCENARIO: Acme pays late', $result['payload']);
		self::assertStringContainsString('YES', $result['payload']);
		self::assertStringContainsString('2026-w26', $result['payload']);

	}//end testRenderIncludesScenarioWhenSupplied()

	/**
	 * Top-customer table renders when supplied, including offset + confidence.
	 *
	 * @return void
	 */
	public function testRenderIncludesTopCustomersWhenSupplied(): void {
		$horizon = ['horizonId' => 'h1'];
		$topCustomers = [
			['customerId' => 'klant-municipality-amsterdam', 'gemiddeldeAfwijking' => '+48 days', 'reliabilityScore' => 0.95],
			['customerId' => 'klant-acme-bv', 'gemiddeldeAfwijking' => '+8 days', 'reliabilityScore' => 0.92],
		];

		$result = $this->renderer->render($horizon, [], null, $topCustomers);

		self::assertStringContainsString('TOP CUSTOMERS (BETALINGSGEDRAG)', $result['payload']);
		self::assertStringContainsString('klant-municipality-amsterdam', $result['payload']);
		self::assertStringContainsString('+48 days', $result['payload']);

	}//end testRenderIncludesTopCustomersWhenSupplied()

	/**
	 * Empty input still renders a valid envelope (no fatal errors).
	 *
	 * @return void
	 */
	public function testRenderTolerantOfEmptyInput(): void {
		$result = $this->renderer->render([], []);
		self::assertNotEmpty($result['payload']);
		self::assertStringContainsString('13-WEEK CASHFLOW FORECAST', $result['payload']);

	}//end testRenderTolerantOfEmptyInput()

	/**
	 * renderPdf() emits a real PDF binary, not the text payload with a `.pdf`
	 * name stuck on it (#865).
	 *
	 * The distinction is the whole point of the issue: `render()` returns
	 * `text/plain` and a `.txt` filename, and an export that advertised
	 * `application/pdf` over those bytes would open as garbage in every
	 * viewer while every route/status assertion stayed green.
	 *
	 * @return void
	 */
	public function testRenderPdfEmitsPdfBinary(): void {
		$result = $this->renderer->renderPdf($this->horizonFixture(), $this->weeksFixture());

		self::assertSame('application/pdf', $result['mimeType']);
		self::assertStringEndsWith('.pdf', $result['filename']);
		self::assertStringStartsWith('%PDF-1.7', $result['payload']);
		self::assertStringEndsWith('%%EOF', $result['payload']);

	}//end testRenderPdfEmitsPdfBinary()

	/**
	 * Every xref entry points at the byte offset of the object it claims, and
	 * the trailer's /Size matches the object count.
	 *
	 * A PDF with a wrong xref table still *starts* with `%PDF` and still ends
	 * with `%%EOF`, so the magic-byte assertion above cannot see a broken
	 * document. This one can.
	 *
	 * @return void
	 */
	public function testRenderPdfXrefOffsetsPointAtRealObjects(): void {
		$pdf = $this->renderer->renderPdf($this->horizonFixture(), $this->weeksFixture())['payload'];

		$matched = preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $entries);
		self::assertIsInt($matched);
		self::assertGreaterThan(0, $matched, 'no xref entries were emitted');

		foreach ($entries[1] as $index => $offset) {
			$objectNumber = ($index + 1);
			self::assertSame(
				$objectNumber . ' 0 obj',
				substr($pdf, (int)$offset, strlen($objectNumber . ' 0 obj')),
				sprintf('xref entry %d does not point at object %d', $objectNumber, $objectNumber)
			);
		}

		self::assertStringContainsString('/Size ' . (count($entries[1]) + 1) . ' ', $pdf);

	}//end testRenderPdfXrefOffsetsPointAtRealObjects()

	/**
	 * The week-by-week table reaches the page content stream — the PDF carries
	 * the forecast, not just a title page.
	 *
	 * @return void
	 */
	public function testRenderPdfCarriesTheWeekTable(): void {
		$pdf = $this->renderer->renderPdf($this->horizonFixture(), $this->weeksFixture())['payload'];

		self::assertStringContainsString('WEEK-BY-WEEK SUMMARY', $pdf);
		self::assertStringContainsString('ABOVE_BUFFER', $pdf);
		self::assertStringContainsString('PRE_ALERT', $pdf);
		self::assertStringContainsString('METHODOLOGY', $pdf);

	}//end testRenderPdfCarriesTheWeekTable()

	/**
	 * REQ-CF-016 §2 — the 13-week bar chart is drawn as vector rectangles, and
	 * it is drawn ONLY when there are weeks to chart.
	 *
	 * The negative half is what makes this an assertion rather than a
	 * decoration: without it, a renderer that emitted the chart preamble
	 * unconditionally (or never at all) would satisfy the positive half by
	 * accident.
	 *
	 * @return void
	 */
	public function testRenderPdfDrawsTheBarChartOnlyWhenThereAreWeeks(): void {
		$withWeeks = $this->renderer->renderPdf($this->horizonFixture(), $this->weeksFixture())['payload'];
		$withoutWeeks = $this->renderer->renderPdf($this->horizonFixture(), [])['payload'];

		self::assertStringContainsString('13-WEEK CLOSING BALANCE', $withWeeks);
		self::assertMatchesRegularExpression('/[\d.]+ [\d.]+ [\d.]+ [\d.]+ re\nf/', $withWeeks);

		self::assertStringNotContainsString('13-WEEK CLOSING BALANCE', $withoutWeeks);

	}//end testRenderPdfDrawsTheBarChartOnlyWhenThereAreWeeks()

	/**
	 * A week that breaches the buffer is drawn in the alert colour, and a
	 * negative closing balance is drawn BELOW the zero line rather than as a
	 * positive bar of the same size.
	 *
	 * Without this the chart would be a decoration: three weeks at
	 * -50k, 0 and +50k would all render identically upward in one colour, and
	 * the reader of a bank-meeting document would draw exactly the wrong
	 * conclusion from a chart that "renders correctly".
	 *
	 * @return void
	 */
	public function testRenderPdfDrawsBreachedWeeksInAlertColourAndBelowTheZeroLine(): void {
		$weeks = [
			[
				'weekNumber' => 22,
				'closingBalance' => 20000.0,
				'bufferStatus' => 'ABOVE_BUFFER',
			],
			[
				'weekNumber' => 23,
				'closingBalance' => -20000.0,
				'bufferStatus' => 'BELOW_BUFFER',
			],
		];

		$pdf = $this->renderer->renderPdf($this->horizonFixture(), $weeks)['payload'];

		$matched = preg_match_all(
			'/([\d.]+ [\d.]+ [\d.]+) rg\n([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re\nf/',
			$pdf,
			$bars,
			PREG_SET_ORDER
		);
		self::assertSame(2, $matched, 'expected exactly one bar per week');

		// Above buffer: blue, sitting ON the zero line.
		self::assertSame('0.13 0.40 0.72', $bars[0][1]);
		self::assertSame('420.00', $bars[0][3]);

		// Below buffer: red, and its bottom edge is a full bar BELOW the zero
		// line (420 - 300), i.e. it hangs downward.
		self::assertSame('0.80 0.16 0.16', $bars[1][1]);
		self::assertSame('120.00', $bars[1][3]);

	}//end testRenderPdfDrawsBreachedWeeksInAlertColourAndBelowTheZeroLine()

	/**
	 * A parenthesis in a value cannot break out of the PDF literal-string
	 * token that carries it.
	 *
	 * An unescaped `)` terminates the string early and every following byte is
	 * read as an operator — the document still opens in a lenient viewer, with
	 * the rest of the page silently missing.
	 *
	 * @return void
	 */
	public function testRenderPdfEscapesParenthesesInValues(): void {
		$horizon = $this->horizonFixture();
		$horizon['administrationId'] = 'ADM (hoofd) \\ nevenvestiging';

		$pdf = $this->renderer->renderPdf($horizon, $this->weeksFixture())['payload'];

		self::assertStringContainsString('ADM \\(hoofd\\) \\\\ nevenvestiging', $pdf);
		self::assertStringNotContainsString('(ADM (hoofd)', $pdf);

	}//end testRenderPdfEscapesParenthesesInValues()

	/**
	 * The filename carries the horizon id so two exports in one download
	 * folder do not collide.
	 *
	 * @return void
	 */
	public function testRenderPdfFilenameCarriesTheHorizonId(): void {
		$result = $this->renderer->renderPdf($this->horizonFixture(), $this->weeksFixture());

		self::assertStringContainsString('horizon-test-001', $result['filename']);

	}//end testRenderPdfFilenameCarriesTheHorizonId()

	/**
	 * Empty input still yields a structurally valid PDF rather than a fatal —
	 * the dashboard button must never hand the browser a truncated file.
	 *
	 * @return void
	 */
	public function testRenderPdfTolerantOfEmptyInput(): void {
		$result = $this->renderer->renderPdf([], []);

		self::assertStringStartsWith('%PDF-1.7', $result['payload']);
		self::assertStringEndsWith('%%EOF', $result['payload']);
		self::assertStringContainsString('13-WEEK CASHFLOW FORECAST', $result['payload']);

	}//end testRenderPdfTolerantOfEmptyInput()

	/**
	 * Horizon fixture shared by the renderPdf tests.
	 *
	 * @return array<string,mixed>
	 */
	private function horizonFixture(): array {
		return [
			'horizonId' => 'horizon-test-001',
			'horizonStart' => '2026-05-25',
			'horizonEnd' => '2026-08-23',
			'administrationId' => 'adm-001',
			'modelVersion' => 'v4.1-klantspecifiek-betaalgedrag',
			'rolledOn' => '2026-05-25T02:00:00Z',
		];
	}//end horizonFixture()

	/**
	 * Two-week fixture with one week above and one week under the buffer.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function weeksFixture(): array {
		return [
			[
				'weekNumber' => 22,
				'inflows_total' => 12500.0,
				'outflows_total' => 7200.0,
				'netMovement' => 5300.0,
				'closingBalance' => 20120.0,
				'bufferStatus' => 'ABOVE_BUFFER',
			],
			[
				'weekNumber' => 23,
				'inflows_total' => 0.0,
				'outflows_total' => 9000.0,
				'netMovement' => -9000.0,
				'closingBalance' => 11120.0,
				'bufferStatus' => 'PRE_ALERT',
			],
		];
	}//end weeksFixture()

}//end class
