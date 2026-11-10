<?php

/**
 * Characterisation tests for the GL actuals and ledger-group membership the budget readers share.
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
 * @spec openspec/specs/budget-projection-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Service\LedgerActuals;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Pins the private posting-month index, the per-account monthly cents and
 * the ledger-group member resolution of the budget readers, so merging the
 * copies cannot change an outcome.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetReaderLedgerActualsTest extends TestCase {

	/**
	 * Build a reader over an object service answering rows per schema, and
	 * record the reads made.
	 *
	 * @param class-string                            $class The reader class.
	 * @param array<string,list<array<string,mixed>>> $rows  Rows per schema.
	 * @param list<string>                            $reads Receives "schema filters" per read.
	 *
	 * @return object The reader.
	 */
	private function reader(string $class, array $rows, array &$reads = []): object {
		$schema = '';
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $name) use (&$schema, $objectService) {
				$schema = $name;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema, &$reads, $rows) {
				$reads[] = $schema . ' ' . json_encode($config['filters']);
				return $rows[$schema] ?? [];
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new $class(appConfig: $config, logger: $this->createMock(LoggerInterface::class), objectService: $objectService);

	}//end reader()

	/**
	 * Invoke a private method.
	 *
	 * @param object      $reader The reader.
	 * @param string      $method The method name.
	 * @param list<mixed> $args   The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(object $reader, string $method, array $args): mixed {
		$reflection = (new ReflectionClass($reader))->getMethod($method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($reader, $args);

	}//end call()

	/**
	 * Posted GL transactions, mixed shapes.
	 *
	 * @return list<array<string,mixed>> The transactions.
	 */
	private static function transactions(): array {
		return [
			['@self' => ['id' => 'uuid-1'], 'id' => 'ignored', 'transactionNumber' => 'T-1', 'postingDate' => '2026-03-15'],
			['id' => 'uuid-2', 'postingDate' => '2026-04-01T10:00:00Z'],
			['transactionNumber' => 'T-3', 'postingDate' => '2026-05-31'],
			['id' => 'uuid-4', 'transactionNumber' => 'T-4', 'postingDate' => ''],
			['postingDate' => '2026-06-01'],
			['id' => 'uuid-1', 'postingDate' => '2026-07-01'],
		];

	}//end transactions()

	/**
	 * GL lines across the transactions.
	 *
	 * @return list<array<string,mixed>> The lines.
	 */
	private static function lines(): array {
		return [
			['transactionId' => 'uuid-1', 'accountNumber' => '4000', 'amount' => 10.005, 'side' => 'debit'],
			['transactionId' => 'T-1', 'accountNumber' => '4000', 'amount' => '2.50', 'side' => 'credit'],
			['transactionId' => 'uuid-2', 'accountNumber' => '4000', 'amount' => 1],
			['transactionId' => 'T-3', 'accountNumber' => '8000', 'amount' => -3.333],
			['transactionId' => 'unknown', 'accountNumber' => '4000', 'amount' => 99],
			['transactionId' => '', 'accountNumber' => '4000', 'amount' => 99],
			['transactionId' => 'uuid-2', 'accountNumber' => '', 'amount' => 99],
			['transactionId' => 'uuid-2', 'amount' => 99],
		];

	}//end lines()

	/**
	 * The two readers with the same actuals pipeline.
	 *
	 * @return array<string,array{0:class-string,1:string}> Cases.
	 */
	public static function actualsReaders(): array {
		return [
			'projection'       => [BudgetProjectionReader::class, 'bucketByAccountMonth'],
			'budget vs actual' => [BudgetVsActualsReader::class, 'actualsByAccountMonth'],
		];

	}//end actualsReaders()

	/**
	 * Posting months are keyed by object id and number; lines bucket to
	 * signed cents; nothing is read for lines when no month is known.
	 *
	 * @param class-string $class  The reader class.
	 * @param string       $bucket The bucket method name.
	 *
	 * @return void
	 */
	#[DataProvider('actualsReaders')]
	public function testPostingMonthsAndCents(string $class, string $bucket): void {
		$reads = [];
		$reader = $this->reader($class, ['GLTransaction' => self::transactions(), 'GLLine' => self::lines()], $reads);

		$months = $this->call($reader, 'postedTransactionMonths', ['a-1']);
		self::assertSame(
			['uuid-1' => '2026-07', 'T-1' => '2026-03', 'uuid-2' => '2026-04', 'T-3' => '2026-05'],
			$months
		);

		self::assertSame(
			['4000' => ['2026-03' => 751, '2026-04' => 100], '8000' => ['2026-05' => -333]],
			$this->call($reader, $bucket, [['uuid-1' => '2026-03', 'T-1' => '2026-03', 'uuid-2' => '2026-04', 'T-3' => '2026-05']])
		);
		self::assertSame([], $this->call($reader, $bucket, [[]]));
		self::assertSame(
			['GLTransaction {"administrationId":"a-1","state":"posted"}', 'GLLine []'],
			$reads
		);

	}//end testPostingMonthsAndCents()

	/**
	 * The transaction references the BBV reader uses: object id first, then number.
	 *
	 * @return void
	 */
	public function testBbvTransactionRefs(): void {
		self::assertSame(['uuid-1', 'T-1'], (new LedgerActuals())->transactionRefs(self::transactions()[0]));
		self::assertSame(['uuid-2'], (new LedgerActuals())->transactionRefs(self::transactions()[1]));
		self::assertSame(['T-3'], (new LedgerActuals())->transactionRefs(self::transactions()[2]));
		self::assertSame([], (new LedgerActuals())->transactionRefs(self::transactions()[4]));

	}//end testBbvTransactionRefs()

	/**
	 * A ledger group with ranges, includes and excludes.
	 *
	 * @return array<string,mixed> The group.
	 */
	private static function ledgerGroup(): array {
		return [
			'accountRanges' => [
				['from' => '4000', 'to' => '4099'],
				['from' => '', 'to' => '9999'],
				['from' => 'abc', 'to' => '9999'],
				['from' => '8000', 'to' => '8000'],
				['to' => '7000'],
			],
			'includedAccountNumbers' => [1200, '', '4050', '1300'],
			'excludedAccountNumbers' => [4010, '1300'],
		];

	}//end ledgerGroup()

	/**
	 * Members: in-range numbers, then includes, minus excludes, as strings.
	 *
	 * @return void
	 */
	public function testMembersFromAccountNumbers(): void {
		$reader = $this->reader(BudgetProjectionReader::class, []);
		$numbers = ['4000', '4010', '4099', '40100', '8000', '7000', 'x', '4050', ''];

		self::assertSame(
			['4000', '4099', '8000', '4050', '1200'],
			$this->call($reader, 'resolveMembers', [self::ledgerGroup(), $numbers])
		);
		self::assertSame([], $this->call($reader, 'resolveMembers', [['accountRanges' => 'x'], $numbers]));
		self::assertSame(['7'], $this->call($reader, 'resolveMembers', [['includedAccountNumbers' => [7, 7]], []]));

	}//end testMembersFromAccountNumbers()

	/**
	 * Members: the same resolution from Account rows.
	 *
	 * @return void
	 */
	public function testMembersFromAccountRows(): void {
		$reader = $this->reader(BudgetVsActualsReader::class, []);
		$accounts = [
			['accountNumber' => '4000'], ['accountNumber' => '4010'], ['accountNumber' => '4099'],
			['accountNumber' => '40100'], ['accountNumber' => 8000], ['accountNumber' => '7000'],
			['accountNumber' => 'x'], ['accountNumber' => '4050'], ['accountNumber' => ''], ['name' => 'no number'],
		];

		self::assertSame(
			['4000', '4099', '8000', '4050', '1200'],
			$this->call($reader, 'resolveMembers', [self::ledgerGroup(), $accounts])
		);
		self::assertSame([], $this->call($reader, 'resolveMembers', [['excludedAccountNumbers' => 'x'], $accounts]));

	}//end testMembersFromAccountRows()

	/**
	 * Range edge cases: inclusive bounds, numeric comparison, a pair with a
	 * missing bound contains nothing, a non-numeric number is never in range.
	 *
	 * @return void
	 */
	public function testRangeEdgeCases(): void {
		$reader = $this->reader(BudgetProjectionReader::class, []);
		$group = ['accountRanges' => [['from' => '100', 'to' => '200'], ['from' => '300']]];

		self::assertSame(
			['100', '200.0', '150'],
			$this->call($reader, 'resolveMembers', [$group, ['100', '200.0', '300', '1e2x', '150', '99']])
		);
		self::assertSame([], $this->call($reader, 'resolveMembers', [['accountRanges' => []], ['150']]));

	}//end testRangeEdgeCases()
}//end class
