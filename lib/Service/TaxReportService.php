<?php

/**
 * Tax Report Service
 *
 * Tier-2 read-only Vpb quarterly/annual tax-statement computation (REQ-VPB-003,
 * REQ-VPB-009, REQ-VPB-010, REQ-VPB-012). Computes the quarterly income
 * statement for a fiscal period from existing GLTransaction + GLLine + Account
 * data using the real OpenRegister ObjectService API (find / findAll) — there is
 * NO QuarterlyTaxStatement record authored by operators; the statement is
 * materialised on demand (design.md D3).
 *
 * Per ADR-031 the equivalent declarative aggregation shape is documented on the
 * QuarterlyTaxStatement schema (x-openregister-aggregations.quarterlyTaxStatement);
 * this service is the engine-side fallback for the GLLine→Account join and the
 * tax-treatment grouping the declarative aggregation engine cannot yet express.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes a period-scoped Vpb quarterly tax statement from the general ledger.
 *
 * Reads are scoped to a single administration + fiscal period (REQ-VPB-003):
 * callers pass the administrationId resolved from the authenticated user's
 * context, never a client-supplied trust boundary. Postings come from GLLine
 * rows whose parent GLTransaction belongs to the administration and period; the
 * accountType is joined from Account; the taxTreatment tag classifies the
 * posting. The annual roll-up (REQ-VPB-012) sums the four quarters and estimates
 * the Vpb liability.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-22
 */
class TaxReportService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param TaxReportCalculator $calculator Pure-logic aggregation helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TaxReportCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the quarterly tax statement for an administration + fiscal period.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-VPB-003).
	 * @param int $fiscalYear Fiscal year.
	 * @param int $quarter Quarter (1-4).
	 *
	 * @return array<string,mixed> The quarterly statement: administrationId,
	 *                             fiscalYear, quarter, periodId, revenue,
	 *                             operatingExpenses, nonOperating,
	 *                             specialDeductions, netTaxableIncome,
	 *                             untaggedCount and breakdown.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-22
	 */
	public function computeQuarter(string $administrationId, int $fiscalYear, int $quarter): array {
		$periodId = $fiscalYear . '-Q' . $quarter;
		$rows = $this->fetchTaxRows(administrationId: $administrationId, periodId: $periodId);
		$agg = $this->calculator->aggregate(rows: $rows);

		return [
			'administrationId' => $administrationId,
			'fiscalYear' => $fiscalYear,
			'quarter' => $quarter,
			'periodId' => $periodId,
			'revenue' => $agg['revenue'],
			'operatingExpenses' => $agg['operatingExpenses'],
			'nonOperating' => $agg['nonOperating'],
			'specialDeductions' => $agg['specialDeductions'],
			'netTaxableIncome' => $agg['netTaxableIncome'],
			'untaggedCount' => $agg['untaggedCount'],
			'breakdown' => $agg['breakdown'],
		];

	}//end computeQuarter()

	/**
	 * Roll the four quarters of a fiscal year into an annual summary (REQ-VPB-012).
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $fiscalYear Fiscal year.
	 *
	 * @return array<string,mixed> The annual summary: administrationId,
	 *                             fiscalYear, quarters (4 statements), revenue,
	 *                             operatingExpenses, nonOperating,
	 *                             specialDeductions, netTaxableIncome,
	 *                             untaggedCount and estimatedLiability.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-25
	 */
	public function computeAnnual(string $administrationId, int $fiscalYear): array {
		$quarters = [];
		$revenue = 0.0;
		$operating = 0.0;
		$nonOperating = 0.0;
		$special = 0.0;
		$untagged = 0;
		for ($quarter = 1; $quarter <= 4; $quarter++) {
			$statement = $this->computeQuarter(
				administrationId: $administrationId,
				fiscalYear: $fiscalYear,
				quarter: $quarter
			);
			$quarters[] = $statement;
			$revenue += $statement['revenue'];
			$operating += $statement['operatingExpenses'];
			$nonOperating += $statement['nonOperating'];
			$special += $statement['specialDeductions'];
			$untagged += $statement['untaggedCount'];
		}

		$net = ($revenue - $operating + $nonOperating - $special);

		return [
			'administrationId' => $administrationId,
			'fiscalYear' => $fiscalYear,
			'quarters' => $quarters,
			'revenue' => $revenue,
			'operatingExpenses' => $operating,
			'nonOperating' => $nonOperating,
			'specialDeductions' => $special,
			'netTaxableIncome' => $net,
			'untaggedCount' => $untagged,
			'estimatedLiability' => $this->calculator->estimateLiability(netTaxableIncome: $net),
		];

	}//end computeAnnual()

	/**
	 * Fetch the GLLine rows for a period, joined with their Account type.
	 *
	 * Resolves the administration's GLTransactions for the period, then collects
	 * the non-eliminated GLLine children, joining each to its Account for the
	 * accountType / accountName used to classify revenue vs. expense.
	 *
	 * @param string $administrationId Administration scope (REQ-VPB-003).
	 * @param string $periodId Fiscal period identifier (e.g. 2025-Q1).
	 *
	 * @return array<int,array<string,mixed>> Rows with accountNumber, accountName,
	 *                                        accountType, taxTreatment, amount, side.
	 */
	private function fetchTaxRows(string $administrationId, string $periodId): array {
		$register = $this->register();

		$transactions = $this->objectService
			->setRegister($register)
			->setSchema('GLTransaction')
			->findAll(
				['filters' => ['administrationId' => $administrationId, 'periodId' => $periodId]]
			);

		$transactionIds = [];
		foreach ($transactions as $transaction) {
			$id = ($transaction['id'] ?? ($transaction['@self']['id'] ?? null));
			if ($id !== null) {
				$transactionIds[(string)$id] = true;
			}
		}

		$accounts = $this->fetchAccounts(administrationId: $administrationId);

		$lines = $this->objectService
			->setRegister($register)
			->setSchema('GLLine')
			->findAll(['filters' => ['periodId' => $periodId]]);

		$rows = [];
		foreach ($lines as $line) {
			if (($line['eliminationFlag'] ?? false) === true) {
				continue;
			}

			$transactionId = (string)($line['transactionId'] ?? '');
			if ($transactionIds !== [] && isset($transactionIds[$transactionId]) === false) {
				continue;
			}

			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($accountNumber === '') {
				continue;
			}

			$account = ($accounts[$accountNumber] ?? []);
			$rows[] = [
				'accountNumber' => $accountNumber,
				'accountName' => ($account['name'] ?? null),
				'accountType' => (string)($account['accountType'] ?? ''),
				'taxTreatment' => (string)($line['taxTreatment'] ?? ''),
				'amount' => ($line['amount'] ?? 0),
				'side' => (string)($line['side'] ?? ''),
			];
		}//end foreach

		return $rows;
	}//end fetchTaxRows()

	/**
	 * Fetch the administration's chart-of-accounts keyed by accountNumber.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> accountNumber => Account object.
	 */
	private function fetchAccounts(string $administrationId): array {
		$accounts = $this->objectService
			->setRegister($this->register())
			->setSchema('Account')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byNumber = [];
		foreach ($accounts as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $account;
			}
		}

		return $byNumber;
	}//end fetchAccounts()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
