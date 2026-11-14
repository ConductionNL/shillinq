<?php

/**
 * Unit tests for TaskFieldRealisation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\TaskFieldRealisation;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BBV-012: a year's posted lines per BBV task field, and the accounts with
 * postings but no mapping, over real-shaped GL transactions, lines and
 * mappings.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\TaskFieldRealisation
 * @uses   \OCA\Shillinq\Service\LedgerActuals
 */
final class TaskFieldRealisationTest extends TestCase {

	/**
	 * The filters each schema was read with.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The service over these rows.
	 *
	 * @param list<array<string,mixed>> $transactions The GL transactions.
	 * @param list<array<string,mixed>> $lines        The GL lines.
	 * @param list<array<string,mixed>> $mappings     The account mappings.
	 *
	 * @return TaskFieldRealisation
	 */
	private function realisation(array $transactions, array $lines, array $mappings): TaskFieldRealisation {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->method('records')->willReturnCallback(
			function (string $schema, array $filters) use ($transactions, $lines, $mappings): array {
				$this->asked[$schema] = $filters;
				return match ($schema) {
					'GLTransaction' => $transactions,
					'GLLine' => $lines,
					'BbvAccountMapping' => $mappings,
					default => [],
				};
			}
		);

		return new TaskFieldRealisation(records: $records);
	}//end realisation()

	/**
	 * A posted transaction.
	 *
	 * @param string $id   The id.
	 * @param string $day  The posting date.
	 * @param string $adm  The administration.
	 * @param string $state The state.
	 *
	 * @return array<string,mixed>
	 */
	private static function transaction(string $id, string $day, string $adm = 'gem-1', string $state = 'posted'): array {
		return ['id' => $id, 'postingDate' => $day, 'administrationId' => $adm, 'state' => $state];
	}//end transaction()

	/**
	 * A GL line.
	 *
	 * @param string $tx      The transaction id.
	 * @param string $account The account number.
	 * @param float  $amount  The amount.
	 * @param string $side    debit or credit.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(string $tx, string $account, float $amount, string $side = 'debit'): array {
		return ['transactionId' => $tx, 'accountNumber' => $account, 'amount' => $amount, 'side' => $side];
	}//end line()

	public function testTheYearIsSummedPerTaskFieldAndAnUnmappedAccountIsListed(): void {
		$result = $this->realisation(
			transactions: [self::transaction('t1', '2026-03-01'), self::transaction('t2', '2026-06-15T10:00:00+00:00')],
			lines: [
				self::line('t1', '4100', 30000.00),
				self::line('t2', '4100', 10000.00),
				self::line('t1', '1100', 40000.00, 'credit'),
				self::line('t2', '4999', 5000.00),
			],
			mappings: [
				['administrationId' => 'gem-1', 'accountNumber' => '4100', 'taskField' => '0.1'],
				['administrationId' => 'gem-1', 'accountNumber' => '1100', 'taskField' => '0.10'],
			]
		)->forYear(administrationId: 'gem-1', fiscalYear: '2026', startDate: '2026-01-01', endDate: '2027-01-01');

		$this->assertSame([
			'fiscalYear' => '2026',
			'taskFields' => [
				['taskField' => '0.1', 'expenses' => 40000.0, 'income' => 0.0],
				['taskField' => '0.10', 'expenses' => 0.0, 'income' => 40000.0],
			],
			'unmapped' => [
				['accountNumber' => '4999', 'amount' => 5000.0, 'mappingPage' => '/overheid/bbv-mapping'],
			],
		], $result);
		$this->assertSame(['administrationId' => 'gem-1', 'state' => 'posted'], $this->asked['GLTransaction']);
		$this->assertSame(['administrationId' => 'gem-1'], $this->asked['BbvAccountMapping']);
	}//end testTheYearIsSummedPerTaskFieldAndAnUnmappedAccountIsListed()

	public function testOnlyPostedLinesOfThisAdministrationAndYearCount(): void {
		$result = $this->realisation(
			transactions: [
				self::transaction('in', '2026-12-31'),
				self::transaction('before', '2025-12-31'),
				self::transaction('after', '2027-01-01'),
				self::transaction('draft', '2026-05-01', 'gem-1', 'draft'),
				self::transaction('other', '2026-05-01', 'gem-2'),
				self::transaction('undated', ''),
			],
			lines: [
				self::line('in', '4100', 1.01),
				self::line('before', '4100', 100.00),
				self::line('after', '4100', 100.00),
				self::line('draft', '4100', 100.00),
				self::line('other', '4100', 100.00),
				self::line('undated', '4100', 100.00),
				self::line('unknown', '4100', 100.00),
			],
			mappings: [
				['administrationId' => 'gem-1', 'accountNumber' => '4100', 'taskField' => '6.1'],
				['administrationId' => 'gem-2', 'accountNumber' => '4100', 'taskField' => '9.9'],
			]
		)->forYear(administrationId: 'gem-1', fiscalYear: '2026', startDate: '2026-01-01', endDate: '2027-01-01');

		$this->assertSame([['taskField' => '6.1', 'expenses' => 1.01, 'income' => 0.0]], $result['taskFields']);
		$this->assertSame([], $result['unmapped']);
	}//end testOnlyPostedLinesOfThisAdministrationAndYearCount()

	public function testAnAccountWhosePostingsNetToZeroIsStillListedWhenUnmapped(): void {
		$result = $this->realisation(
			transactions: [self::transaction('t1', '2026-02-01')],
			lines: [self::line('t1', '7000', 250.00), self::line('t1', '7000', 250.00, 'credit'), self::line('t1', '', 9.00)],
			mappings: [['administrationId' => 'gem-1', 'accountNumber' => '7000', 'taskField' => ' ']]
		)->forYear(administrationId: 'gem-1', fiscalYear: '2026', startDate: '2026-01-01', endDate: '2027-01-01');

		$this->assertSame([], $result['taskFields']);
		$this->assertSame([['accountNumber' => '7000', 'amount' => 0.0, 'mappingPage' => '/overheid/bbv-mapping']], $result['unmapped']);
	}//end testAnAccountWhosePostingsNetToZeroIsStillListedWhenUnmapped()

	public function testNoAdministrationOrNoPostingsIsAnEmptyYear(): void {
		$empty = ['fiscalYear' => '2026', 'taskFields' => [], 'unmapped' => []];

		$this->assertSame($empty, $this->realisation([], [], [])->forYear(administrationId: 'gem-1', fiscalYear: '2026', startDate: '2026-01-01', endDate: '2027-01-01'));
		$this->asked = [];
		$this->assertSame($empty, $this->realisation([self::transaction('t1', '2026-01-02')], [self::line('t1', '4100', 1.0)], [])->forYear(administrationId: '', fiscalYear: '2026', startDate: '2026-01-01', endDate: '2027-01-01'));
		$this->assertSame([], $this->asked, 'no administration reads nothing');
	}//end testNoAdministrationOrNoPostingsIsAnEmptyYear()
}//end class
