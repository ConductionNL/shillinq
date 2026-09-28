<?php

/**
 * The cash position per bank account adds up to the dashboard's cash position.
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
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Controller\FinancialDashboardController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCA\Shillinq\Service\FinancialSeriesCalculator;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-BCON-004.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashPositionByAccountTest extends TestCase {
	/**
	 * Gemeente Voorbeeld: ING on 1100, Rabobank on 1110, kas 1000 unnamed.
	 *
	 * @return array<string,mixed>
	 */
	private static function data(): array {
		$tx = static fn (string $id): array => ['id' => $id, 'state' => 'posted', 'postingDate' => '2026-09-15'];
		return [
			'accounts' => [
				['accountNumber' => '1000', 'name' => 'Kas', 'accountType' => 'assets'],
				['accountNumber' => '1100', 'name' => 'Bank ING', 'accountType' => 'assets'],
				['accountNumber' => '1110', 'name' => 'Bank Rabobank', 'accountType' => 'assets'],
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue'],
			],
			'transactions' => [$tx('t1'), $tx('t2'), $tx('t3'), ['id' => 't4', 'state' => 'draft', 'postingDate' => '2026-09-15']],
			'lines' => [
				['transactionId' => 't1', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 84300],
				['transactionId' => 't1', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 84300],
				['transactionId' => 't2', 'accountNumber' => '1110', 'side' => 'debit', 'amount' => 250000],
				['transactionId' => 't3', 'accountNumber' => '1000', 'side' => 'debit', 'amount' => 500],
				['transactionId' => 't4', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 999],
			],
		];

	}//end data()

	/**
	 * Scenario: a controller reads the combined cash position; the kas counts on the other line.
	 *
	 * @return void
	 */
	public function testPerAccountAndCombined(): void {
		$calculator = new FinancialSeriesCalculator();
		$bankAccounts = [
			['id' => 'ba-ing', 'accountName' => 'ING', 'iban' => 'NL20INGB0001234567', 'ledgerAccountNumber' => '1100'],
			['id' => 'ba-rabo', 'accountName' => 'Rabobank', 'iban' => 'NL91RABO0123456789', 'ledgerAccountNumber' => '1110'],
		];
		$statements = [
			['bankAccountIban' => 'NL20INGB0001234567', 'statementDate' => '2026-09-27T06:00:00Z', 'closingBalance' => 84000],
			['bankAccountIban' => 'NL20INGB0001234567', 'statementDate' => '2026-09-28T06:00:00Z', 'closingBalance' => 84300],
		];

		$result = $calculator->cashPositionByAccount(data: self::data(), bankAccounts: $bankAccounts, statements: $statements);

		self::assertSame(84300.0, $result['accounts'][0]['ledgerBalance']);
		self::assertSame(84300.0, $result['accounts'][0]['bankBalance']);
		self::assertSame('2026-09-28T06:00:00Z', $result['accounts'][0]['bankBalanceDate']);
		self::assertSame(250000.0, $result['accounts'][1]['ledgerBalance']);
		self::assertNull($result['accounts'][1]['bankBalance']);
		self::assertSame(500.0, $result['other']);
		self::assertSame(334800.0, $result['total']);
		self::assertSame(
			$calculator->computeKpis(data: self::data(), now: new DateTimeImmutable('2026-09-28'))['cashPosition'],
			$result['total'],
			'the per-account total equals the dashboard cash position'
		);

	}//end testPerAccountAndCombined()

	/**
	 * A caller outside the administration gets a masked 404 and nothing is read.
	 *
	 * @return void
	 */
	public function testNonMemberIsMasked(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('adm-other');
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('bookkeeper');
		$context->method('canAccess')->willReturn(false);
		$dashboard = $this->createMock(FinancialDashboardService::class);
		$dashboard->expects(self::never())->method('cashPosition');

		$controller = new FinancialDashboardController(
			request: $request,
			dashboard: $dashboard,
			context: $context,
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame(404, $controller->cashPosition()->getStatus());

	}//end testNonMemberIsMasked()
}//end class
