<?php

/**
 * Golden characterisation of the period-close assistant.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins every detector, the queries behind them and every flag text.
 */
final class PeriodCloseAssistantGoldenTest extends TestCase {

	/**
	 * Recorded queries.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the subject over rows per schema.
	 *
	 * @param array<string,mixed> $suspense What the suspense ageing answers.
	 *
	 * @return PeriodCloseAssistantService
	 */
	private function subject(array $suspense): PeriodCloseAssistantService {
		$events = &$this->events;
		$rows = self::rows();
		$state = ['schema' => ''];
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$state, $store): ObjectServiceInterface {
				$state['schema'] = $schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$state, &$events, $rows): array {
				$events[] = [$state['schema'], $config['filters']];
				return array_values(
					array_filter(
						($rows[$state['schema']] ?? []),
						static fn (array $row): bool => array_intersect_assoc($config['filters'], $row) === $config['filters']
					)
				);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('agedUnmatchedItems')->willReturnCallback(
			function (string $administrationId, string $asOf) use (&$events, $suspense): array {
				$events[] = ['suspense', $administrationId, $asOf];
				return $suspense;
			}
		);

		return new PeriodCloseAssistantService($config, $ageing, $store);

	}//end subject()

	/**
	 * Rows across schemas, with ids top-level, under @self and absent.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function rows(): array {
		$tx = static fn (array $row): array => $row + ['administrationId' => 'adm', 'periodId' => 'p1'];
		return [
			'GLTransaction' => [
				$tx(['id' => 't1', 'state' => 'draft']),
				$tx(['@self' => ['id' => 't2'], 'state' => 'draft']),
				$tx(['state' => 'draft']),
				$tx(['id' => 't4', 'state' => 'posted', 'sourceReference' => 's1']),
				$tx(['id' => 't5', 'state' => 'posted', 'sourceReference' => '']),
				$tx(['id' => 't6', 'state' => 'posted']),
			],
			'GLLine' => [
				['periodId' => 'p1', 'subLedgerType' => 'ap', 'transactionId' => 't1', 'amount' => 100.005],
				['periodId' => 'p1', 'subLedgerType' => 'ap', 'transactionId' => 't1', 'amount' => '50'],
				['periodId' => 'p1', 'subLedgerType' => 'ap', 'transactionId' => 't4', 'amount' => 999],
				['periodId' => 'p1', 'subLedgerType' => 'ar', 'transactionId' => 't2', 'amount' => 1234567.8],
				['periodId' => 'p1', 'subLedgerType' => 'ar'],
			],
			'BankStatement' => [
				['administrationId' => 'adm', 'id' => 's1', 'transactionCount' => 3, 'statementDate' => '2026-01-31'],
				['administrationId' => 'adm', 'id' => 's2', 'transactionCount' => 1, 'statementDate' => '2026-01-15'],
				['administrationId' => 'adm', '@self' => ['id' => 's3'], 'transactionCount' => '2', 'statementDate' => '2026-02-01'],
				['administrationId' => 'adm', 'transactionCount' => 5],
				['administrationId' => 'adm', 'id' => 's5', 'transactionCount' => 0, 'statementDate' => '2026-01-01'],
			],
			'ExpenseClaimEntry' => [
				['administrationId' => 'adm', 'approvalState' => 'pending', 'totalAmount' => 12.345],
				['administrationId' => 'adm', 'approvalState' => 'pending'],
				['administrationId' => 'adm', 'approvalState' => 'approved', 'totalAmount' => 99],
			],
		];

	}//end rows()

	/**
	 * Flags with the clock-dependent detectedAt checked and removed.
	 *
	 * @param array<int,array<string,mixed>> $flags The flags.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function withoutClock(array $flags): array {
		foreach ($flags as $index => $flag) {
			self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $flag['detectedAt']);
			self::assertSame(['id', 'severity', 'category', 'message', 'detectedAt'], array_keys($flag));
			unset($flags[$index]['detectedAt']);
		}

		return $flags;

	}//end withoutClock()

	/**
	 * Pin analyse end to end: queries, detections and every flag.
	 *
	 * @return void
	 */
	public function testAnalyseIsExact(): void {
		$suspense = ['count' => 2, 'totalAmountCents' => 123456, 'oldestDaysOutstanding' => 40];
		$service = $this->subject($suspense);
		$this->events = [];
		$flags = $this->withoutClock($service->analyse('adm', 'p1', '2026-01-31'));

		$drafts = ['administrationId' => 'adm', 'periodId' => 'p1', 'state' => 'draft'];
		self::assertSame(
			[
				['GLTransaction', $drafts], ['GLLine', ['periodId' => 'p1', 'subLedgerType' => 'ap']],
				['GLTransaction', $drafts], ['GLLine', ['periodId' => 'p1', 'subLedgerType' => 'ar']],
				['BankStatement', ['administrationId' => 'adm']],
				['GLTransaction', ['administrationId' => 'adm', 'periodId' => 'p1', 'state' => 'posted']],
				['ExpenseClaimEntry', ['administrationId' => 'adm', 'approvalState' => 'pending']],
				['suspense', 'adm', '2026-01-31'],
			],
			$this->events
		);
		self::assertSame(
			[
				['id' => 'flag-ap', 'severity' => 'warning', 'category' => 'ap', 'message' => '1 outstanding AP transaction(s) totalling €150.01 require payment or accrual before period close'],
				['id' => 'flag-ar', 'severity' => 'warning', 'category' => 'ar', 'message' => '1 outstanding AR transaction(s) totalling €1,234,567.80 are not yet collected or provisioned'],
				['id' => 'flag-bank', 'severity' => 'info', 'category' => 'bank', 'message' => '2 bank statement(s) in this period have no matching GL posting'],
				['id' => 'flag-expense-claims', 'severity' => 'warning', 'category' => 'expense-claims', 'message' => '2 expense claim(s) totalling €12.35 are awaiting approval or reimbursement'],
				[
					'id' => 'flag-suspense',
					'severity' => 'error',
					'category' => 'suspense',
					'message' => '2 unmatched bank/suspense item(s) totalling €1,234.56 remain (oldest 40 day(s) outstanding) — the period cannot be closed until they are resolved',
				],
			],
			$flags
		);
		self::assertSame(['count' => 3, 'total' => 3.0], $service->detectUnreconciledBankReceipts('adm', 'p1'));
		self::assertSame(['count' => 0, 'total' => 0.0], $service->detectOpenSubLedger('adm', 'p9', 'ap'));
		self::assertSame(['count' => 0, 'total' => 0.0], $service->detectOpenSubLedger('adm', 'p1', 'other'));

	}//end testAnalyseIsExact()

	/**
	 * Pin flag generation over missing, zero and partial detections.
	 *
	 * @return void
	 */
	public function testGenerateFlagsOverEdgeDetections(): void {
		$service = $this->subject([]);
		self::assertSame([], $service->generateFlags([]));
		self::assertSame([], $service->generateFlags(['ap' => ['count' => 0, 'total' => 5.0], 'bank' => ['count' => -1, 'total' => 0.0]]));
		self::assertSame(
			[
				['id' => 'flag-bank', 'severity' => 'info', 'category' => 'bank', 'message' => '1 bank statement(s) in this period have no matching GL posting'],
				[
					'id' => 'flag-suspense',
					'severity' => 'error',
					'category' => 'suspense',
					'message' => '3 unmatched bank/suspense item(s) totalling €0.50 remain (oldest 0 day(s) outstanding) — the period cannot be closed until they are resolved',
				],
			],
			$this->withoutClock($service->generateFlags(['suspense' => ['count' => 3, 'total' => 0.5], 'bank' => ['count' => 1, 'total' => 1.0], 'other' => ['count' => 9, 'total' => 1.0]]))
		);

	}//end testGenerateFlagsOverEdgeDetections()
}//end class
