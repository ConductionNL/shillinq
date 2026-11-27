<?php

/**
 * Keeps the cash flow of the annual accounts as a CashFlowStatement record.
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
 * @spec openspec/changes/archive/2026-11-24-reporting-cash-flow-statement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use OCA\Shillinq\Service\RecordStoreLog;
use OCA\Shillinq\Service\RegisterBinding;
use OCA\Shillinq\Service\SchemaRecordStore;
use Psr\Log\LoggerInterface;

/**
 * Writes one `CashFlowStatement` per annual report and reporting date, with
 * method indirect, from the figures {@see CashFlowCalculator::between()}
 * returned (REQ-RCF-004).
 *
 * The record hangs off the administration's `AnnualReport` whose financial
 * year ends on the statement's last day; without one there is nothing to hang
 * it off and nothing is written. A draft record of that report and date is
 * brought up to date; a final one is left as it is.
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-cash-flow-statement/tasks.md#task-3.2
 */
class CashFlowStatementRecord {

	/**
	 * The record's rule category per statement section; the schema knows no
	 * unclassified category, so those rows are not kept as rules.
	 */
	private const RULE_CATEGORY = [
		'operating' => 'operationeel',
		'investing' => 'investment',
		'financing' => 'financiering',
	];

	/**
	 * The shillinq register.
	 *
	 * @var SchemaRecordStore
	 */
	private readonly SchemaRecordStore $records;

	/**
	 * Constructor.
	 *
	 * @param RegisterBinding $binding The shillinq register binding.
	 * @param LoggerInterface $logger  Logs a refused save.
	 */
	public function __construct(RegisterBinding $binding, LoggerInterface $logger) {
		$this->records = new SchemaRecordStore(
			binding: $binding,
			log: new RecordStoreLog(logger: $logger, source: 'CashFlowStatementRecord')
		);
	}//end __construct()

	/**
	 * Keep the cash flow of an annual report.
	 *
	 * @param string               $administrationId The administration.
	 * @param array<string, mixed> $statement        The cash flow from CashFlowCalculator::between().
	 * @param string               $currency         The presentation currency.
	 *
	 * @return array<string, mixed>|null The stored record, the final one left as it was, or null without an annual report.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-cash-flow-statement/tasks.md#task-3.2
	 */
	public function store(string $administrationId, array $statement, string $currency): ?array {
		$date = substr((string)($statement['to'] ?? ''), 0, 10);
		$reportId = $this->annualReportId(administrationId: $administrationId, yearEnd: $date);
		if ($reportId === null) {
			return null;
		}

		$existing = $this->records->findOne(schema: 'CashFlowStatement', filters: ['reportId' => $reportId, 'cashFlowDate' => $date]);
		if ($existing !== null && ($existing['status'] ?? '') === 'final') {
			return $existing;
		}

		$totals = (array)($statement['totals'] ?? []);
		$record = array_merge(
			($existing ?? []),
			[
				'reportId' => $reportId,
				'cashFlowDate' => $date,
				'method' => 'indirect',
				'currency' => $currency,
				'status' => 'draft',
				'operationalCashFlow' => (float)($totals['operating'] ?? 0.0),
				'investmentCashFlow' => (float)($totals['investing'] ?? 0.0),
				'financingCashFlow' => (float)($totals['financing'] ?? 0.0),
				'netMovementCashEquivalents' => (float)($totals['cashChange'] ?? 0.0),
				'rules' => self::rules(statement: $statement),
			]
		);

		return $this->records->saveObject(schema: 'CashFlowStatement', object: $record);
	}//end store()

	/**
	 * The id of the administration's annual report for the year ending on a date.
	 *
	 * @param string $administrationId The administration.
	 * @param string $yearEnd          The last day of the year, YYYY-MM-DD.
	 *
	 * @return string|null The id, or null when there is none.
	 */
	private function annualReportId(string $administrationId, string $yearEnd): ?string {
		if ($yearEnd === '') {
			return null;
		}

		foreach ($this->records->findAll(schema: 'AnnualReport', filters: ['administrationId' => $administrationId]) as $report) {
			$id = (string)($report['@self']['id'] ?? $report['id'] ?? '');
			if ($id !== '' && substr((string)($report['financialYearEnd'] ?? ''), 0, 10) === $yearEnd) {
				return $id;
			}
		}

		return null;
	}//end annualReportId()

	/**
	 * The record's rules: every row of the operating, investing and financing sections.
	 *
	 * @param array<string, mixed> $statement The cash flow.
	 *
	 * @return array<int, array{category: string, label: string, amount: float}>
	 */
	private static function rules(array $statement): array {
		$rules = [];
		foreach (self::RULE_CATEGORY as $section => $category) {
			foreach ((array)($statement[$section]['rows'] ?? []) as $row) {
				$rules[] = ['category' => $category, 'label' => (string)($row['label'] ?? ''), 'amount' => (float)($row['amount'] ?? 0.0)];
			}
		}

		return $rules;
	}//end rules()
}//end class
