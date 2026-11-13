<?php

/**
 * Unit tests for InterestAllocationService::post(): one balanced journal entry
 * per calculated run, a realised reserve addition per reserve that earns
 * interest, and a run that is posted once.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Service\KapitaallastenCalculator;
use OCA\Shillinq\Service\PublicSector\InterestAllocationService;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Posting an interest allocation run.
 */
#[CoversClass(InterestAllocationService::class)]
final class InterestAllocationPostTest extends TestCase {

	/**
	 * Mock record access.
	 *
	 * @var PublicSectorRecords&MockObject
	 */
	private PublicSectorRecords&MockObject $records;

	/**
	 * Build the service.
	 *
	 * @return InterestAllocationService
	 */
	private function service(): InterestAllocationService {
		$this->records = $this->createMock(PublicSectorRecords::class);

		return new InterestAllocationService(
			$this->records,
			$this->createMock(ReserveBalances::class),
			$this->createMock(KapitaallastenCalculator::class)
		);

	}//end service()

	/**
	 * A calculated run with one task field and two reserves, one without interest.
	 *
	 * @return array<string,mixed>
	 */
	private static function calculatedRun(): array {
		return [
			'id'                        => 'run-1',
			'administrationId'          => 'adm-1',
			'year'                      => 2026,
			'omslagrentePercentage'     => '2.5',
			'interestCostAccountNumber' => '4810',
			'treasuryAccountNumber'     => '4890',
			'lines'                     => [['taskFieldCode' => '0.1', 'interest' => 25.0]],
			'reserveLines'              => [
				['reserveId' => 'res-1', 'name' => 'Algemene reserve', 'interest' => 12.5],
				['reserveId' => 'res-2', 'name' => 'Lege reserve', 'interest' => 0],
			],
		];

	}//end calculatedRun()

	/**
	 * A run that carries its journal entry is returned untouched and nothing is written.
	 *
	 * @return void
	 */
	public function testAPostedRunIsNotPostedAgain(): void {
		$service = $this->service();
		$this->records->expects($this->never())->method('postJournal');
		$this->records->expects($this->never())->method('create');
		$this->records->expects($this->never())->method('find');

		$run = (self::calculatedRun() + ['journalEntryId' => 'je-0']);
		$this->assertSame($run, $service->post($run));

	}//end testAPostedRunIsNotPostedAgain()

	/**
	 * One journal entry, then one realised addition for the reserve that earns
	 * interest; the reserve without interest is neither booked nor mutated.
	 *
	 * @return void
	 */
	public function testPostingBooksOneJournalAndAnAdditionPerEarningReserve(): void {
		$service = $this->service();
		$this->records->expects($this->once())->method('find')
			->with('Reserve', 'res-1')
			->willReturn(['balanceAccountNumber' => '0510', 'resultAccountNumber' => '9810']);

		$journal = null;
		$this->records->expects($this->once())->method('postJournal')->willReturnCallback(
			static function (array $posted) use (&$journal): string {
				$journal = $posted;
				return 'je-1';
			}
		);
		$this->records->expects($this->once())->method('create')->with(
			'ReserveMutation',
			[
				'administrationId' => 'adm-1',
				'reserveId'        => 'res-1',
				'reserveName'      => 'Algemene reserve',
				'year'             => 2026,
				'kind'             => 'addition',
				'amount'           => 12.5,
				'description'      => 'Toegerekende rente 2026',
				'status'           => 'realised',
				'journalEntryId'   => 'je-1',
				'interestRunId'    => 'run-1',
			]
		)->willReturn('mut-1');

		$posted = $service->post(self::calculatedRun());

		$this->assertSame('je-1', $posted['journalEntryId']);
		$this->assertSame('2026-12-31', $journal['entryDate']);
		$this->assertSame('draft', $journal['state']);
		$this->assertSame(
			[
				['4810', 'debit', 25],
				['4890', 'credit', 25],
				['9810', 'debit', 12.5],
				['0510', 'credit', 12.5],
			],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], $line['amount']], $journal['lines'])
		);
		$this->assertSame('Rente 2026 toegevoegd aan Algemene reserve', $journal['lines'][3]['description']);

	}//end testPostingBooksOneJournalAndAnAdditionPerEarningReserve()

	/**
	 * A reserve without its accounts refuses the post before anything is written.
	 *
	 * @return void
	 */
	public function testAReserveWithoutItsAccountsRefusesThePost(): void {
		$service = $this->service();
		$this->records->method('find')->willReturn(['balanceAccountNumber' => '0510', 'resultAccountNumber' => ' ']);
		$this->records->expects($this->never())->method('postJournal');
		$this->records->expects($this->never())->method('create');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Enter the reserve account and the result account on reserve Algemene reserve first.');
		$service->post(self::calculatedRun());

	}//end testAReserveWithoutItsAccountsRefusesThePost()
}//end class
