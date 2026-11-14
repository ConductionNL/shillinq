<?php

/**
 * Golden test for FinancialSeriesCalculator over one mixed dataset.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\FinancialSeriesCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every public series, table and KPI output, value for value.
 */
final class FinancialSeriesGoldenTest extends TestCase {

	/**
	 * The dataset.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		return [
			'accounts' => [
				['accountNumber' => '8000', 'accountType' => 'revenue'],
				['accountNumber' => '4000', 'accountType' => 'expenses'],
				['accountNumber' => '1000', 'accountType' => 'assets', 'name' => 'Kas'],
				['accountNumber' => '1100', 'accountType' => 'assets', 'name' => 'Bank ING'],
				['accountNumber' => '2000', 'accountType' => 'assets', 'name' => 'Debiteuren'],
				['accountNumber' => '', 'accountType' => 'revenue'],
			],
			'transactions' => [
				['id' => 't1', 'transactionNumber' => 'GL-1', 'state' => 'posted', 'postingDate' => '2026-01-15'],
				['@self' => ['id' => 't2'], 'state' => 'posted', 'postingDate' => '2026-02-03T10:00:00Z'],
				['id' => 't3', 'state' => 'draft', 'postingDate' => '2026-02-04'],
				['id' => 't4', 'state' => 'posted', 'postingDate' => 'garbage'],
				['id' => 't5', 'transactionNumber' => 'GL-5', 'state' => 'posted', 'postingDate' => '2026-03-01'],
			],
			'lines' => [
				['transactionId' => 't1', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 1000],
				['transactionId' => 't1', 'accountNumber' => '1000', 'side' => 'debit', 'amount' => '1000'],
				['transactionId' => 't2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 300.333],
				['transactionId' => 't2', 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 300.333],
				['transactionId' => 't3', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 999],
				['transactionId' => 't4', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 999],
				['transactionId' => 'GL-5', 'accountNumber' => '8000', 'side' => 'debit', 'amount' => 50],
				['transactionId' => 'GL-5', 'accountNumber' => '2000', 'side' => 'debit', 'amount' => 50],
				['transactionId' => 'nope', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 7],
			],
			'arInvoices' => [
				['id' => 'i1', 'invoiceNumber' => 'F1', 'customerId' => 'c1', 'lifecycleState' => 'issued', 'dueDate' => '2026-03-10', 'grossAmount' => 121, 'invoiceDate' => '2026-02-10'],
				['@self' => ['id' => 'i2'], 'invoiceNumber' => 'F2', 'customerId' => 'c9', 'lifecycleState' => 'overdue', 'dueDate' => '2026-04-30', 'netAmount' => '50.5'],
				['id' => 'i3', 'customerId' => 'c1', 'lifecycleState' => 'paid', 'dueDate' => '2026-01-01', 'grossAmount' => 10],
				['id' => 'i4', 'customerId' => 'c2', 'lifecycleState' => 'issued', 'dueDate' => '2026-01-20'],
			],
			'apTransactions' => [
				['id' => 'a1', 'invoiceNumber' => 'V1', 'vendorId' => 'v1', 'state' => 'partially-paid', 'dueDate' => '2026-03-20', 'totalAmount' => 80],
				['id' => 'a2', 'vendorId' => 'v2', 'state' => 'received', 'dueDate' => '', 'totalAmount' => '19.99'],
				['id' => 'a3', 'vendorId' => 'v1', 'state' => 'paid', 'totalAmount' => 5],
			],
			'hourEntries' => [
				['date' => '2026-03-02', 'hours' => 6, 'recognisedRate' => 90],
				['date' => '2026-03-03', 'hours' => 2, 'recognisedRate' => 0],
				['date' => '2026-01-03', 'hours' => 4, 'recognisedRate' => '80'],
				['date' => 'x', 'hours' => 9, 'recognisedRate' => 80],
			],
		];
	}//end data()

	/**
	 * Every public output.
	 *
	 * @return array<string, mixed>
	 */
	private function outputs(): array {
		$calc = new FinancialSeriesCalculator();
		$data = $this->data();
		$now  = new DateTimeImmutable('2026-03-15T12:00:00', new DateTimeZone('UTC'));
		$customers = [['customerId' => 'c1', 'legalName' => 'Acme'], ['@self' => ['id' => 'c2'], 'tradeName' => 'Beta'], ['customerId' => 'c3']];
		$vendors   = [['vendorNumber' => 'v1', 'name' => ''], ['@self' => ['id' => 'v2'], 'tradingName' => 'Gamma'], ['vendorNumber' => 'v1', 'tradingName' => 'Delta']];
		$months    = ['2026-01', '2026-02', '2026-03', '2026-04'];

		return [
			'months range' => $calc->monthsInRange('2025-11-30T00:00:00Z', '2026-02-01'),
			'months bad' => [$calc->monthsInRange('', '2026-01-01'), $calc->monthsInRange('2026-13-45', '2026-01-01'), $calc->monthsInRange('2026-05-01', '2026-01-01')],
			'classes' => $calc->classifyAccounts($data['accounts']),
			'series' => $calc->monthlyFinancialSeries($data + ['months' => $months]),
			'series empty' => $calc->monthlyFinancialSeries([]),
			'billable' => $calc->billableSeries($data['hourEntries'], $months),
			'ar' => $calc->openArRows($data['arInvoices'], $customers, $now),
			'ap' => $calc->openApRows($data['apTransactions'], $vendors, $now),
			'kpis' => $calc->computeKpis($data, $now),
			'kpis empty' => $calc->computeKpis([], $now),
			'range kpis' => $calc->computeRangeKpis($data, $months),
			'range kpis empty' => $calc->computeRangeKpis([], []),
			'cash by account' => $calc->cashPositionByAccount(
				$data,
				[
					['id' => 'b1', 'accountName' => 'ING', 'iban' => 'nl01 ing 0001', 'ledgerAccountNumber' => '1100'],
					['@self' => ['id' => 'b2'], 'accountName' => 'Spaar', 'iban' => 'NL02RABO0002', 'ledgerAccountNumber' => '2000'],
				],
				[
					['bankAccountIban' => 'NL01ING0001', 'statementDate' => '2026-02-01', 'closingBalance' => '10'],
					['bankAccountIban' => 'nl01ing0001', 'statementDate' => '2026-03-01', 'closingBalance' => 20],
					['bankAccountIban' => 'NL01ING0001', 'statementDate' => '2026-01-01', 'closingBalance' => 5],
					['bankAccountIban' => 'NL02RABO0002', 'statementDate' => '2026-03-01', 'closingBalance' => 'n/a'],
					['bankAccountIban' => '', 'statementDate' => '2026-03-01', 'closingBalance' => 1],
				]
			),
		];
	}//end outputs()

	/**
	 * The outputs match the snapshot taken before the calculator was split.
	 *
	 * @return void
	 */
	public function testOutputsMatchTheSnapshot(): void {
		$snapshot = json_decode((string)file_get_contents(__DIR__ . '/fixtures/financial-series-golden.json'), true);
		self::assertSame($snapshot, json_decode((string)json_encode($this->outputs(), JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testOutputsMatchTheSnapshot()
}//end class
