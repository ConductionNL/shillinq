<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Statement report generator base
 *
 * Shared plumbing for the financial-statement generators (balance sheet,
 * profit and loss): the StatementCalculator both take their figures from, so
 * a document shows the figures of the on-screen statement
 * (reporting-financial-statements REQ-RFS-004); the date range a generation
 * context names; and the account-table layout both statements use.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\StatementCalculator;
use OCA\Shillinq\Reporting\StatementPeriods;
use Throwable;

/**
 * Base for the generators that render a statement from StatementCalculator figures.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
abstract class AbstractStatementReportGenerator extends AbstractDocumentReportGenerator {

	/**
	 * Header of the amount column in the statement's account tables.
	 *
	 * @return string
	 */
	protected function accountAmountHead(): string {
		return 'Bedrag';
	}//end accountAmountHead()

	/**
	 * The calculator the on-screen statements use, or null when it cannot be resolved.
	 *
	 * @return StatementCalculator|null
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	protected function calculator(): ?StatementCalculator {
		try {
			$calculator = $this->container->get(StatementCalculator::class);
		} catch (Throwable $e) {
			return null;
		}

		return $calculator instanceof StatementCalculator ? $calculator : null;
	}//end calculator()

	/**
	 * The date range of a generation context.
	 *
	 * Explicit `from` and `to` win; else the `period` label (`2026`,
	 * `2026-Q3`, `2026-09`); else the current calendar year.
	 *
	 * @param array<string, mixed> $context The generation context.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	protected function contextRange(array $context): array {
		$from = $this->str($context, 'from');
		$to = $this->str($context, 'to');
		if ($from !== '' && $to !== '') {
			try {
				StatementPeriods::assertRange(from: $from, to: $to);
				return ['from' => $from, 'to' => $to];
			} catch (Throwable $e) {
				// An unreadable explicit range falls back to the period label.
			}
		}

		$range = StatementPeriods::rangeOfLabel(label: $this->str($context, 'period'));
		if ($range !== null) {
			return $range;
		}

		$year = gmdate('Y');

		return ['from' => $year . '-01-01', 'to' => $year . '-12-31'];
	}//end contextRange()

	/**
	 * Sum of the `amount` field across lines.
	 *
	 * @param array<int, array<string, mixed>> $lines The rows to sum.
	 *
	 * @return float
	 */
	protected function total(array $lines): float {
		$sum = 0.0;
		foreach ($lines as $row) {
			$sum += (float)($row['amount'] ?? 0.0);
		}

		return round($sum, 2);
	}//end total()

	/**
	 * Render one account table with its total row, and return the total.
	 *
	 * @param StatementRun  $run    The statement being rendered.
	 * @param AccountBucket $bucket The total-row label and the account lines.
	 *
	 * @return float The table total.
	 */
	protected function addAccountTable(StatementRun $run, AccountBucket $bucket): float {
		$total = $this->total($bucket->lines);
		$this->addAmountTable(
			$run->section,
			'Rekening',
			$this->accountAmountHead(),
			$bucket->lines,
			['label' => $bucket->totalLabel, 'amount' => $total],
			$run->currency
		);

		return $total;
	}//end addAccountTable()
}//end class
