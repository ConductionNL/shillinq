<?php

/**
 * Unit tests for CommitmentLedger: what entering, invoicing and closing a
 * commitment writes to its lines, movements and budgets, the allocation of an
 * invoice over the open lines, and that each step is written once.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Commitment;

use DateTime;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(CommitmentLedger::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CommitmentLedgerTest extends TestCase {
	/**
	 * What the store saved: schema and object, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The store behind the ledger.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The ledger over a store seeded with the given records.
	 *
	 * @param array<string,list<array<string,mixed>>> $data Records by schema.
	 *
	 * @return CommitmentLedger
	 */
	private function ledger(array $data = []): CommitmentLedger {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub($data, $this->saved);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-03-15T09:00:00+00:00'));

		return new CommitmentLedger($this->store, $location, $time);

	}//end ledger()

	/**
	 * A commitment line of commitment V-1 on programme P1 in 2026.
	 *
	 * @param string              $id     The line id.
	 * @param array<string,mixed> $fields Further fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(string $id, array $fields = []): array {
		return array_merge(
			[
				'id'               => $id,
				'commitment'       => 'V-1',
				'administrationId' => 'adm-1',
				'programme'        => 'P1',
				'financialYear'    => 2026,
			],
			$fields
		);

	}//end line()

	/**
	 * The 2026 budget of programme P1.
	 *
	 * @param array<string,mixed> $fields Further fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function budgetRow(array $fields = []): array {
		return array_merge(
			[
				'id'                      => 'bud-1',
				'administrationId'        => 'adm-1',
				'programmeCode'           => 'P1',
				'financialYear'           => 2026,
				'authorised_amount'       => 100000,
				'realised_amount'         => 0,
				'outstanding_commitments' => 0,
			],
			$fields
		);

	}//end budgetRow()

	/**
	 * The saved objects of a schema, in order.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function savedOf(string $schema): array {
		return array_values(
			array_map(
				static fn (array $entry): array => $entry['object'],
				array_filter($this->saved, static fn (array $entry): bool => $entry['schema'] === $schema)
			)
		);

	}//end savedOf()

	/**
	 * The current record of a schema by id.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function current(string $schema, string $id): ?array {
		return $this->store->setSchema($schema)->find(id: $id)?->getObject();

	}//end current()

	/**
	 * Entering a commitment sets each line's remaining amount, raises the
	 * budget and writes one committed movement of the lines' total.
	 *
	 * @return void
	 */
	public function testCommittingRecordsTheLinesTheBudgetAndOneMovement(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine'   => [
					self::line('l-1', ['amount_excl_vat' => 30000, 'invoiced_amount' => 5000]),
					self::line('l-2', ['amount_excl_vat' => 20000]),
				],
				'CommitmentBudget' => [self::budgetRow(['realised_amount' => 10000])],
			]
		);

		$total = $ledger->committed(['commitmentNumber' => 'V-1', 'administrationId' => 'adm-1'], 'anna');

		self::assertSame(50000, $total);
		self::assertSame(25000, $this->current('CommitmentLine', 'l-1')['remaining_committed']);
		self::assertSame(20000, $this->current('CommitmentLine', 'l-2')['remaining_committed']);
		$budget = $this->current('CommitmentBudget', 'bud-1');
		self::assertSame(45000, $budget['outstanding_commitments']);
		self::assertSame(10000, $budget['realised_amount']);
		self::assertSame(45000, $budget['free_capacity']);
		$movements = $this->savedOf('CommitmentMovement');
		self::assertCount(1, $movements);
		self::assertSame(
			[
				'administrationId' => 'adm-1',
				'commitment'       => 'V-1',
				'date'             => '2026-03-15',
				'kind'             => 'committed',
				'amount'           => 50000,
				'currency'         => 'EUR',
				'user'             => 'anna',
			],
			array_diff_key($movements[0], ['id' => true])
		);

	}//end testCommittingRecordsTheLinesTheBudgetAndOneMovement()

	/**
	 * A commitment entered before is not recorded again.
	 *
	 * @return void
	 */
	public function testCommittingTwiceWritesNothingTheSecondTime(): void {
		$ledger = $this->ledger(['CommitmentLine' => [self::line('l-1', ['amount_excl_vat' => 100])]]);
		self::assertSame(100, $ledger->committed(['commitmentNumber' => 'V-1'], 'anna'));
		$count = count($this->saved);

		self::assertSame(0, $ledger->committed(['commitmentNumber' => 'V-1'], 'anna'));
		self::assertCount($count, $this->saved);

	}//end testCommittingTwiceWritesNothingTheSecondTime()

	/**
	 * Without lines the movement carries the commitment's own total, and a
	 * commitment without a number is not recorded at all.
	 *
	 * @return void
	 */
	public function testCommittingWithoutLinesUsesTheCommitmentTotal(): void {
		$ledger = $this->ledger();
		self::assertSame(0, $ledger->committed(['total_amount_excl_vat' => 900], 'anna'));
		self::assertSame([], $this->saved);

		$total = $ledger->committed(['commitmentNumber' => 'V-9', 'total_amount_excl_vat' => 7500, 'currency' => 'USD'], 'anna');

		self::assertSame(7500, $total);
		$movement = $this->savedOf('CommitmentMovement')[0];
		self::assertSame(7500, $movement['amount']);
		self::assertSame('USD', $movement['currency']);
		self::assertSame('V-9', $movement['commitment']);

	}//end testCommittingWithoutLinesUsesTheCommitmentTotal()

	/**
	 * An invoice on the cost centre of exactly one open line goes to that line,
	 * lowering the budget's outstanding by what the line still had and raising
	 * its realised amount by the full share.
	 *
	 * @return void
	 */
	public function testAnInvoiceOnOneCostCentreGoesToThatLine(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine'   => [
					self::line('l-1', ['costCentre' => 'KP-1', 'remaining_committed' => 4000, 'invoiced_amount' => 0]),
					self::line('l-2', ['costCentre' => 'KP-2', 'remaining_committed' => 6000]),
				],
				'CommitmentBudget' => [self::budgetRow(['outstanding_commitments' => 10000])],
			]
		);

		$remaining = $ledger->invoiced(
			['commitmentNumber' => 'V-1'],
			['id' => 'inv-1', 'totalExclVat' => 5000, 'costCenter' => 'KP-1', 'invoiceNumber' => 'F-77']
		);

		self::assertSame(6000, $remaining);
		$line = $this->current('CommitmentLine', 'l-1');
		self::assertSame(5000, $line['invoiced_amount']);
		self::assertSame(0, $line['remaining_committed']);
		self::assertSame(6000, $this->current('CommitmentLine', 'l-2')['remaining_committed']);
		$budget = $this->current('CommitmentBudget', 'bud-1');
		self::assertSame(6000, $budget['outstanding_commitments']);
		self::assertSame(5000, $budget['realised_amount']);
		self::assertSame(89000, $budget['free_capacity']);
		$movements = $this->savedOf('CommitmentMovement');
		self::assertCount(1, $movements);
		self::assertSame('invoiced', $movements[0]['kind']);
		self::assertSame(5000, $movements[0]['amount']);
		self::assertSame('l-1', $movements[0]['commitmentRule']);
		self::assertSame('inv-1', $movements[0]['related_invoice']);
		self::assertSame('F-77', $movements[0]['notes']);

	}//end testAnInvoiceOnOneCostCentreGoesToThatLine()

	/**
	 * Without one matching cost centre the invoice is shared in proportion to
	 * what the lines still have, rounded down, the last line taking the rest.
	 *
	 * @return void
	 */
	public function testAnInvoiceIsSharedInProportionToWhatRemains(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine' => [
					self::line('l-1', ['remaining_committed' => 1000]),
					self::line('l-2', ['remaining_committed' => 1000]),
					self::line('l-3', ['remaining_committed' => 1000]),
				],
			]
		);

		$remaining = $ledger->invoiced(['commitmentNumber' => 'V-1'], ['id' => 'inv-1', 'totalExclVat' => 1000]);

		self::assertSame(2000, $remaining);
		self::assertSame([333, 333, 334], array_column($this->savedOf('CommitmentMovement'), 'amount'));
		self::assertSame(667, $this->current('CommitmentLine', 'l-1')['remaining_committed']);
		self::assertSame(666, $this->current('CommitmentLine', 'l-3')['remaining_committed']);

	}//end testAnInvoiceIsSharedInProportionToWhatRemains()

	/**
	 * A line whose share rounds to nothing gets no movement, and the amount
	 * released never exceeds what the line still had.
	 *
	 * @return void
	 */
	public function testALineWithoutAShareIsLeftAlone(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine' => [
					self::line('l-1', ['remaining_committed' => 100]),
					self::line('l-2', ['remaining_committed' => 0]),
				],
			]
		);

		$remaining = $ledger->invoiced(['commitmentNumber' => 'V-1'], ['id' => 'inv-1', 'totalExclVat' => 150]);

		self::assertSame(0, $remaining);
		$movements = $this->savedOf('CommitmentMovement');
		self::assertCount(1, $movements);
		self::assertSame('l-1', $movements[0]['commitmentRule']);
		self::assertSame(150, $movements[0]['amount']);
		$line = $this->current('CommitmentLine', 'l-1');
		self::assertSame(150, $line['invoiced_amount']);
		self::assertSame(0, $line['remaining_committed']);
		self::assertArrayNotHasKey('invoiced_amount', $this->current('CommitmentLine', 'l-2'));

	}//end testALineWithoutAShareIsLeftAlone()

	/**
	 * When no open line has anything remaining, the first line takes the invoice.
	 *
	 * @return void
	 */
	public function testAnInvoiceOnSpentLinesGoesToTheFirst(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine' => [
					self::line('l-1', ['remaining_committed' => 0, 'invoiced_amount' => 500]),
					self::line('l-2', ['remaining_committed' => -20]),
				],
			]
		);

		$remaining = $ledger->invoiced(['commitmentNumber' => 'V-1'], ['id' => 'inv-1', 'totalExclVat' => 300]);

		self::assertSame(-20, $remaining);
		$movements = $this->savedOf('CommitmentMovement');
		self::assertCount(1, $movements);
		self::assertSame('l-1', $movements[0]['commitmentRule']);
		self::assertSame(800, $this->current('CommitmentLine', 'l-1')['invoiced_amount']);

	}//end testAnInvoiceOnSpentLinesGoesToTheFirst()

	/**
	 * An invoice recorded before, or one without an id, changes nothing and
	 * answers what remains.
	 *
	 * @return void
	 */
	public function testAnInvoiceIsRecordedOnce(): void {
		$ledger = $this->ledger(['CommitmentLine' => [self::line('l-1', ['remaining_committed' => 1000])]]);
		self::assertSame(600, $ledger->invoiced(['commitmentNumber' => 'V-1'], ['id' => 'inv-1', 'totalExclVat' => 400]));
		$count = count($this->saved);

		self::assertSame(600, $ledger->invoiced(['commitmentNumber' => 'V-1'], ['id' => 'inv-1', 'totalExclVat' => 400]));
		self::assertSame(600, $ledger->invoiced(['commitmentNumber' => 'V-1'], ['totalExclVat' => 400]));
		self::assertCount($count, $this->saved);

	}//end testAnInvoiceIsRecordedOnce()

	/**
	 * An invoice on a commitment without open lines writes nothing.
	 *
	 * @return void
	 */
	public function testAnInvoiceWithoutOpenLinesWritesNothing(): void {
		$ledger = $this->ledger(['CommitmentLine' => [self::line('l-1', ['remaining_committed' => 50, 'afgesloten' => true])]]);

		self::assertSame(0, $ledger->invoiced(['commitmentNumber' => 'V-1'], ['id' => 'inv-1', 'totalExclVat' => 400]));
		self::assertSame([], $this->savedOf('CommitmentMovement'));

	}//end testAnInvoiceWithoutOpenLinesWritesNothing()

	/**
	 * Closing releases what the open lines still had, closes them, lowers the
	 * budget and writes one closed movement of minus that amount, once.
	 *
	 * @return void
	 */
	public function testClosingReleasesTheRemainderOnce(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine'   => [
					self::line('l-1', ['remaining_committed' => 700]),
					self::line('l-2', ['remaining_committed' => 300]),
					self::line('l-3', ['remaining_committed' => 999, 'afgesloten' => true]),
				],
				'CommitmentBudget' => [self::budgetRow(['outstanding_commitments' => 1000])],
			]
		);

		self::assertSame(1000, $ledger->closed(['commitmentNumber' => 'V-1'], 'anna'));

		$line = $this->current('CommitmentLine', 'l-1');
		self::assertSame(0, $line['remaining_committed']);
		self::assertTrue($line['afgesloten']);
		self::assertSame(999, $this->current('CommitmentLine', 'l-3')['remaining_committed']);
		self::assertSame(0, $this->current('CommitmentBudget', 'bud-1')['outstanding_commitments']);
		$movements = $this->savedOf('CommitmentMovement');
		self::assertCount(1, $movements);
		self::assertSame('closed', $movements[0]['kind']);
		self::assertSame(-1000, $movements[0]['amount']);
		self::assertSame('anna', $movements[0]['user']);
		self::assertSame([], $ledger->openLines('V-1'));

		$count = count($this->saved);
		self::assertSame(0, $ledger->closed(['commitmentNumber' => 'V-1'], 'anna'));
		self::assertSame(0, $ledger->closed([], 'anna'));
		self::assertCount($count, $this->saved);

	}//end testClosingReleasesTheRemainderOnce()

	/**
	 * What remains is summed over the open lines only; an empty number has none.
	 *
	 * @return void
	 */
	public function testRemainingCountsOpenLinesOnly(): void {
		$ledger = $this->ledger(
			[
				'CommitmentLine' => [
					self::line('l-1', ['remaining_committed' => 250]),
					self::line('l-2', ['remaining_committed' => 50, 'afgesloten' => true]),
					self::line('l-3', ['commitment' => 'V-2', 'remaining_committed' => 1]),
				],
			]
		);

		self::assertSame(250, $ledger->remainingOf('V-1'));
		self::assertSame(['l-1'], array_column($ledger->openLines('V-1'), 'id'));
		self::assertSame([], $ledger->openLines(''));
		self::assertSame(0, $ledger->remainingOf(''));

	}//end testRemainingCountsOpenLinesOnly()

	/**
	 * A budget is found by administration, programme and year; its free
	 * capacity is authorised minus realised minus outstanding.
	 *
	 * @return void
	 */
	public function testTheBudgetAndItsFreeCapacity(): void {
		$ledger = $this->ledger(['CommitmentBudget' => [self::budgetRow()]]);

		self::assertSame('bud-1', $ledger->budget('adm-1', 'P1', 2026)['id']);
		self::assertNull($ledger->budget('adm-1', 'P1', 2027));
		self::assertNull($ledger->budget('adm-2', 'P1', 2026));
		self::assertSame(55, $ledger->free(['authorised_amount' => 100, 'realised_amount' => 30, 'outstanding_commitments' => 15]));
		self::assertSame(0, $ledger->free([]));

	}//end testTheBudgetAndItsFreeCapacity()

	/**
	 * A budget is not touched for a zero change or a line without a budget.
	 *
	 * @return void
	 */
	public function testAdjustingWithoutChangeOrBudgetWritesNothing(): void {
		$ledger = $this->ledger(['CommitmentBudget' => [self::budgetRow()]]);

		$ledger->adjustBudget(self::line('l-1'), 0, 0);
		$ledger->adjustBudget(self::line('l-1', ['programme' => 'P9']), 100, 100);

		self::assertSame([], $this->saved);

	}//end testAdjustingWithoutChangeOrBudgetWritesNothing()

	/**
	 * A movement drops empty and null extra fields but keeps the rest.
	 *
	 * @return void
	 */
	public function testAMovementDropsEmptyExtras(): void {
		$ledger = $this->ledger();

		$ledger->movement(['commitmentNumber' => 'V-1'], 'invoiced', 10, ['notes' => '', 'user' => null, 'related_invoice' => 'inv-1', 'zero' => 0]);

		$movement = $this->savedOf('CommitmentMovement')[0];
		self::assertArrayNotHasKey('notes', $movement);
		self::assertArrayNotHasKey('user', $movement);
		self::assertSame('inv-1', $movement['related_invoice']);
		self::assertSame(0, $movement['zero']);
		self::assertSame('', $movement['administrationId']);

	}//end testAMovementDropsEmptyExtras()

	/**
	 * Created objects answer their id, and find answers a record with its id,
	 * or null for an empty or unknown id.
	 *
	 * @return void
	 */
	public function testCreateAndFind(): void {
		$ledger = $this->ledger();

		$id = $ledger->create('CommitmentLine', ['commitment' => 'V-1', 'amount_excl_vat' => 5]);

		self::assertSame('obj-1', $id);
		$found = $ledger->find('CommitmentLine', $id);
		self::assertSame('obj-1', $found['id']);
		self::assertSame(5, $found['amount_excl_vat']);
		self::assertNull($ledger->find('CommitmentLine', ''));
		self::assertNull($ledger->find('CommitmentLine', 'missing'));
		self::assertSame([['commitment' => 'V-1', 'amount_excl_vat' => 5, 'id' => 'obj-1']], $ledger->records('CommitmentLine', ['commitment' => 'V-1']));

	}//end testCreateAndFind()
}//end class
