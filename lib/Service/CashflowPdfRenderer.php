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
}//end class
