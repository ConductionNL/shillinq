<?php

/**
 * Billing Model Engine
 *
 * Pure billing-model calculator (Task 12, issue #111). Given resolved inputs
 * (rate snapshots, expense cents, retainer config) returns the array of
 * BillableInvoiceLine drafts for each of the five supported models —
 * t_and_m, fixed_fee, milestone, retainer, mixed.
 *
 * All arithmetic is integer-cent. No persistence; the caller owns saveObject.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure billing-model logic: takes prepared inputs, returns line-item drafts.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class BillingModelEngine {
	public const DEFAULT_VAT_RATE = 21.0;

	/**
	 * T&M calculation: hours × rate + expenses, grouped by (rate, resourceType).
	 *
	 * @param array<int,array<string,mixed>> $timeEntries Resolved time entries (timeEntryId, resourceType, hours, rateCents, rateApplied, vatRate).
	 * @param array<int,array<string,mixed>> $expenses Expense lines (expenseId, description, costAmountCents, vatRate).
	 * @param float $markupPercent Markup percentage.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-billing-model-logic-tm-fixed-fee-milestone-retainer-mixed
	 */
	public function calculateTAndM(array $timeEntries, array $expenses, float $markupPercent = 0.0): array {
		$grouped = [];
		foreach ($timeEntries as $entry) {
			$key = $entry['resourceType'] . '|' . $entry['rateCents'];
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = [
					'resourceType' => $entry['resourceType'],
					'rateCents' => (int)$entry['rateCents'],
					'rateApplied' => $entry['rateApplied'] ?? null,
					'hours' => 0.0,
					'vatRate' => (float)($entry['vatRate'] ?? self::DEFAULT_VAT_RATE),
					'sourceIds' => [],
				];
			}

			$grouped[$key]['hours'] += (float)$entry['hours'];
			$grouped[$key]['sourceIds'][] = (string)$entry['timeEntryId'];
		}//end foreach

		$lines = [];
		$line = 1;

		foreach ($grouped as $group) {
			$netCents = $this->applyMarkup(
				amountCents: (int)round(($group['hours'] * $group['rateCents'])),
				markupPercent: $markupPercent
			);
			$lines[] = [
				'lineNumber' => ($line++),
				'sourceType' => 'time_entry',
				// `sourceIds` is a non-empty list of strings here, so offset 0
				// always exists — the `?? null` this replaces was unreachable.
				'sourceId' => $group['sourceIds'][0],
				'description' => sprintf(
					'%s — %s hours @ €%.2f/hr',
					ucwords(str_replace('_', ' ', $group['resourceType'])),
					$this->formatHours(hours: $group['hours']),
					($group['rateCents'] / 100)
				),
				'billableUnits' => $group['hours'],
				'rateApplied' => $group['rateApplied'],
				'markup' => $markupPercent,
				'costAmountCents' => $netCents,
				'vatRate' => $group['vatRate'],
				'modelSpecificFields' => ['sourceIds' => $group['sourceIds']],
			];
		}//end foreach

		foreach ($expenses as $exp) {
			$lines[] = $this->expenseLine(expense: $exp, lineNumber: ($line++));
		}

		return $lines;
	}//end calculateTAndM()

	/**
	 * Fixed-fee: flat amount + expenses, time entries shown in audit only.
	 *
	 * @param int $flatFeeCents Flat fee in cents.
	 * @param string $description Customer-facing line description.
	 * @param array<int,array<string,mixed>> $expenses Expense lines.
	 * @param int $timeHourCount Informational hour count (for lineItemsByModel).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function calculateFixedFee(int $flatFeeCents, string $description, array $expenses = [], int $timeHourCount = 0): array {
		$lines = [
			[
				'lineNumber' => 1,
				'sourceType' => 'fixed_fee',
				'sourceId' => null,
				'description' => $description,
				'billableUnits' => 1.0,
				'rateApplied' => null,
				'markup' => 0.0,
				'costAmountCents' => $flatFeeCents,
				'vatRate' => self::DEFAULT_VAT_RATE,
				'modelSpecificFields' => ['informationalHourCount' => $timeHourCount],
			],
		];

		$line = 2;
		foreach ($expenses as $exp) {
			$lines[] = $this->expenseLine(expense: $exp, lineNumber: ($line++));
		}

		return $lines;
	}//end calculateFixedFee()

	/**
	 * Milestone: milestoneAmount + expenses; time entries informational.
	 *
	 * @param array<string,mixed> $milestone Milestone (milestoneId, milestoneName, milestoneCompletedAt, milestoneBudgetCents).
	 * @param array<int,array<string,mixed>> $expenses Expense lines.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function calculateMilestone(array $milestone, array $expenses = []): array {
		$lines = [
			[
				'lineNumber' => 1,
				'sourceType' => 'fixed_fee',
				'sourceId' => (string)$milestone['milestoneId'],
				'description' => (string)$milestone['milestoneName'],
				'billableUnits' => 1.0,
				'rateApplied' => null,
				'markup' => 0.0,
				'costAmountCents' => (int)$milestone['milestoneBudgetCents'],
				'vatRate' => self::DEFAULT_VAT_RATE,
				'modelSpecificFields' => [
					'milestoneId' => (string)$milestone['milestoneId'],
					'milestoneCompletedAt' => (string)$milestone['milestoneCompletedAt'],
					'milestoneName' => (string)$milestone['milestoneName'],
					'milestoneBudget' => (int)$milestone['milestoneBudgetCents'],
				],
			],
		];

		$line = 2;
		foreach ($expenses as $exp) {
			$lines[] = $this->expenseLine(expense: $exp, lineNumber: ($line++));
		}

		return $lines;
	}//end calculateMilestone()

	/**
	 * Retainer: mandatory retainer charge + overage (if applicable) + expenses.
	 *
	 * @param array<string,mixed> $retainer Retainer config (monthlyAmountCents, overageHoursThreshold, etc.).
	 * @param string $retainerMonth ISO YYYY-MM.
	 * @param float $hoursLogged Total hours within retainer period.
	 * @param array<int,array<string,mixed>> $expenses Expense lines.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function calculateRetainer(array $retainer, string $retainerMonth, float $hoursLogged, array $expenses = []): array {
		$lines = [
			[
				'lineNumber' => 1,
				'sourceType' => 'retainer_charge',
				'sourceId' => null,
				'description' => sprintf('%s — %s', $retainer['label'], $retainerMonth),
				'billableUnits' => 1.0,
				'rateApplied' => null,
				'markup' => 0.0,
				'costAmountCents' => (int)$retainer['monthlyAmountCents'],
				'vatRate' => self::DEFAULT_VAT_RATE,
				'modelSpecificFields' => ['retainerMonth' => $retainerMonth],
			],
		];

		$line = 2;

		// Overage T&M if threshold breached.
		$threshold = $retainer['overageHoursThreshold'];
		$overageRate = $retainer['overageHourlyRateCents'];
		if ($threshold !== null && $overageRate !== null && $hoursLogged > $threshold) {
			$overageHours = ($hoursLogged - $threshold);
			$lines[] = [
				'lineNumber' => ($line++),
				'sourceType' => 'time_entry',
				'sourceId' => null,
				'description' => sprintf(
					'Overage — %s hours @ €%.2f/hr',
					$this->formatHours(hours: $overageHours),
					($overageRate / 100)
				),
				'billableUnits' => $overageHours,
				'rateApplied' => [
					'rateCents' => (int)$overageRate,
					'currency' => 'EUR',
					'rateCardVersion' => 'retainer-overage',
					'effectiveDate' => $retainer['effectiveDate'],
				],
				'markup' => 0.0,
				'costAmountCents' => (int)round($overageHours * $overageRate),
				'vatRate' => self::DEFAULT_VAT_RATE,
				'modelSpecificFields' => ['retainerMonth' => $retainerMonth, 'overage' => true],
			];
		}//end if

		foreach ($expenses as $exp) {
			$lines[] = $this->expenseLine(expense: $exp, lineNumber: ($line++));
		}

		return $lines;
	}//end calculateRetainer()

	/**
	 * Mixed: retainer base + overage + optional setup fee + expenses.
	 *
	 * @param array<string,mixed> $retainer Retainer config (monthlyAmountCents, overageHoursThreshold, etc.).
	 * @param string $retainerMonth ISO YYYY-MM.
	 * @param float $hoursLogged Total hours within retainer period.
	 * @param int|null $setupFeeCents One-time fixed fee.
	 * @param string $setupFeeDescription Setup fee description.
	 * @param array<int,array<string,mixed>> $expenses Expense lines.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function calculateMixed(
		array $retainer,
		string $retainerMonth,
		float $hoursLogged,
		?int $setupFeeCents,
		string $setupFeeDescription,
		array $expenses = [],
	): array {
		$lines = $this->calculateRetainer(retainer: $retainer, retainerMonth: $retainerMonth, hoursLogged: $hoursLogged, expenses: []);

		if ($setupFeeCents !== null && $setupFeeCents > 0) {
			$lines[] = [
				'lineNumber' => (count($lines) + 1),
				'sourceType' => 'fixed_fee',
				'sourceId' => null,
				'description' => $setupFeeDescription,
				'billableUnits' => 1.0,
				'rateApplied' => null,
				'markup' => 0.0,
				'costAmountCents' => $setupFeeCents,
				'vatRate' => self::DEFAULT_VAT_RATE,
				'modelSpecificFields' => ['oneTime' => true],
			];
		}

		foreach ($expenses as $exp) {
			$lines[] = $this->expenseLine(expense: $exp, lineNumber: (count($lines) + 1));
		}

		// Renumber sequentially.
		$n = 1;
		foreach ($lines as &$line) {
			$line['lineNumber'] = ($n++);
		}

		unset($line);

		return $lines;
	}//end calculateMixed()

	/**
	 * Usage: one line per rated MeterReading + expenses (REQ-UMB-003). The
	 * readings are pre-rated by UsageRatingCalculator so this method only
	 * formats them into the shared line-draft shape every other billing model
	 * emits — the same shape InvoiceGenerationService totals and persists, so
	 * usage billing reuses the entire existing invoice pipeline (no fork).
	 *
	 * @param array<int,array<string,mixed>> $ratedReadings Rated readings (readingId, resourceType,
	 *                                                      description, billableUnits, unitPriceCents,
	 *                                                      costAmountCents, vatRate, ratePlanId,
	 *                                                      periodStart, periodEnd, meterId).
	 * @param array<int,array<string,mixed>> $expenses Optional expense lines.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 */
	public function calculateUsage(array $ratedReadings, array $expenses = []): array {
		$lines = [];
		$line = 1;

		foreach ($ratedReadings as $reading) {
			if (isset($reading['unitPriceCents']) === true) {
				$unitPriceCents = (int)$reading['unitPriceCents'];
			} else {
				$unitPriceCents = null;
			}

			$lines[] = [
				'lineNumber' => ($line++),
				'sourceType' => 'usage',
				'sourceId' => ($reading['readingId'] ?? null),
				'description' => (string)($reading['description'] ?? 'Metered usage'),
				'billableUnits' => (float)($reading['billableUnits'] ?? 0.0),
				'rateApplied' => [
					'ratePlanId' => (string)($reading['ratePlanId'] ?? ''),
					'ratingMethod' => (string)($reading['ratingMethod'] ?? ''),
					'unitPriceCents' => $unitPriceCents,
				],
				'markup' => 0.0,
				'costAmountCents' => (int)($reading['costAmountCents'] ?? 0),
				'vatRate' => (float)($reading['vatRate'] ?? self::DEFAULT_VAT_RATE),
				'modelSpecificFields' => [
					'meterId' => (string)($reading['meterId'] ?? ''),
					'resourceType' => (string)($reading['resourceType'] ?? ''),
					'unit' => (string)($reading['unit'] ?? ''),
					'periodStart' => (string)($reading['periodStart'] ?? ''),
					'periodEnd' => (string)($reading['periodEnd'] ?? ''),
				],
			];
		}//end foreach

		foreach ($expenses as $exp) {
			$lines[] = $this->expenseLine(expense: $exp, lineNumber: ($line++));
		}

		return $lines;
	}//end calculateUsage()

	/**
	 * Build an expense line.
	 *
	 * @param array<string,mixed> $expense Expense (expenseId, description, costAmountCents, vatRate).
	 * @param int $lineNumber Sequential line number.
	 *
	 * @return array<string,mixed>
	 */
	private function expenseLine(array $expense, int $lineNumber): array {
		return [
			'lineNumber' => $lineNumber,
			'sourceType' => 'expense',
			'sourceId' => (string)$expense['expenseId'],
			'description' => (string)$expense['description'],
			'billableUnits' => 1.0,
			'rateApplied' => null,
			'markup' => 0.0,
			'costAmountCents' => (int)$expense['costAmountCents'],
			'vatRate' => (float)($expense['vatRate'] ?? self::DEFAULT_VAT_RATE),
			'modelSpecificFields' => null,
		];

	}//end expenseLine()

	/**
	 * Apply a markup percentage to a cents amount with bankers' rounding.
	 *
	 * @param int $amountCents Pre-markup cents.
	 * @param float $markupPercent Percentage (e.g. 10 for +10%).
	 *
	 * @return int Cents after markup.
	 */
	private function applyMarkup(int $amountCents, float $markupPercent): int {
		if ($markupPercent <= 0.0) {
			return $amountCents;
		}

		$value = ($amountCents * (1.0 + ($markupPercent / 100.0)));
		return (int)round($value, 0, PHP_ROUND_HALF_EVEN);
	}//end applyMarkup()

	/**
	 * Format a float hours value with trailing-zero cleanup.
	 *
	 * @param float $hours Hours.
	 *
	 * @return string e.g. '40' or '7.5'.
	 */
	private function formatHours(float $hours): string {
		$formatted = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
		if ($formatted === '') {
			return '0';
		}

		return $formatted;
	}//end formatHours()
}//end class
