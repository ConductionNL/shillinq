<?php

/**
 * Reserve mutations, the multi-year overview and the interest allocation
 * (public-sector-reserves-and-interest, REQ-PSRI-001 to REQ-PSRI-003), with
 * the seed figures from the design and every payload checked against the
 * real register schema.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use DomainException;
use OCA\Shillinq\Lifecycle\Action\InterestAllocationAction;
use OCA\Shillinq\Lifecycle\Action\RealiseReserveMutationAction;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

class PublicSectorReservesAndInterestTest extends TestCase {
	use PublicSectorFixture;

	private function withdrawal(): array {
		return ['id' => $this->ids['withdrawal'], 'administrationId' => 'adm-gov-1', 'reserveId' => $this->ids['maintenance'], 'year' => 2026, 'kind' => 'withdrawal', 'amount' => 250000, 'programme' => '5', 'councilResolution' => '2026-088', 'status' => 'planned'];

	}//end withdrawal()

	private function plannedAdditions(): array {
		$rows = [];
		foreach ([2027, 2028, 2029, 2030] as $year) {
			$rows[] = ['id' => 'plan-' . $year, 'administrationId' => 'adm-gov-1', 'reserveId' => $this->ids['maintenance'], 'year' => $year, 'kind' => 'addition', 'amount' => 150000, 'councilResolution' => '2026-042', 'status' => 'planned'];
		}

		return $rows;

	}//end plannedAdditions()

	/**
	 * The register declares the executors the transitions need.
	 */
	public function testTheTransitionsDeclareTheirExecutors(): void {
		$mutation = RegisterSchema::schema('ReserveMutation')['x-openregister-lifecycle']['transitions'];
		$this->assertSame(RealiseReserveMutationAction::class, $mutation['realise']['actions'][0]['action']);

		$run = RegisterSchema::schema('InterestAllocationRun')['x-openregister-lifecycle']['transitions'];
		$this->assertSame(InterestAllocationAction::class, $run['calculate']['actions'][0]['action']);
		$this->assertSame('calculate', $run['calculate']['actions'][0]['actionParameters']['step']);
		$this->assertSame(InterestAllocationAction::class, $run['post']['actions'][0]['action']);
		$this->assertSame('post', $run['post']['actions'][0]['actionParameters']['step']);

		$this->assertArrayHasKey('openingBalanceYear', RegisterSchema::schema('Reserve')['properties']);
		$this->assertArrayHasKey('taskFieldCode', RegisterSchema::schema('Investering')['properties']);

	}//end testTheTransitionsDeclareTheirExecutors()

	/**
	 * Scenario: a controller records a withdrawal of EUR 250,000 for 2026 with
	 * council resolution 2026-088 and realises it.
	 */
	public function testARealisedWithdrawalIsPostedAndCountsInItsYear(): void {
		$this->seed([$this->withdrawal()]);
		$this->assertSame([], $this->registerErrors('ReserveMutation', $this->withdrawal()));

		$this->runner()->run(objectId: $this->ids['withdrawal'], action: 'realise');

		$mutation = $this->get('ReserveMutation', $this->ids['withdrawal']);
		$this->assertSame('realised', $mutation['status']);
		$this->assertNotEmpty($mutation['journalEntryId']);
		$this->assertSame([], $this->registerErrors('ReserveMutation', $mutation));

		$journal = $this->get('JournalEntry', $mutation['journalEntryId']);
		$this->assertSame('posted', $journal['state'], 'the journal entry went through postDirect');
		$this->assertSame([], $this->registerErrors('JournalEntry', array_merge($journal, ['state' => 'draft'])));
		$this->assertSame(
			[['0520', 'debit', 250000.0], ['8990', 'credit', 250000.0]],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], (float)$line['amount']], array_reverse($journal['lines']))
		);
		$this->assertStringContainsString('2026-088', $journal['description']);

		$row = $this->yearOf($this->balances()->overview('adm-gov-1', 2026)['rows'], $this->ids['maintenance'], 2026);
		$this->assertSame(250000.0, (float)$row['withdrawals']);
		$this->assertSame(550000.0, (float)$row['closing']);
		$this->assertFalse($row['planned']);

	}//end testARealisedWithdrawalIsPostedAndCountsInItsYear()

	/**
	 * A mutation whose reserve has no accounts is not realised.
	 */
	public function testAReserveWithoutAccountsRefusesTheRealisation(): void {
		$this->seed([$this->withdrawal()]);
		$reserve = $this->get('Reserve', $this->ids['maintenance']);
		unset($reserve['balanceAccountNumber']);
		$this->store->setSchema('Reserve')->saveObject($reserve);

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Enter the reserve account and the result account');
		$this->runner()->run(objectId: $this->ids['withdrawal'], action: 'realise');

	}//end testAReserveWithoutAccountsRefusesTheRealisation()

	/**
	 * Scenario: planned additions of EUR 150,000 a year for 2027 to 2030 grow
	 * each year's closing balance by EUR 150,000, marked planned.
	 */
	public function testTheOverviewShowsThePlan(): void {
		$withdrawal = array_merge($this->withdrawal(), ['status' => 'realised', 'journalEntryId' => 'je-1']);
		$this->seed(array_merge([$withdrawal], $this->plannedAdditions()));

		$overview = $this->balances()->overview('adm-gov-1', 2026);
		$this->assertSame(2026, $overview['fromYear']);
		$closing = [];
		foreach ([2026, 2027, 2028, 2029, 2030] as $year) {
			$row = $this->yearOf($overview['rows'], $this->ids['maintenance'], $year);
			$closing[$year] = (float)$row['closing'];
			if ($year > 2026) {
				$this->assertTrue($row['planned'], $year . ' counts planned figures');
				$this->assertSame($closing[$year - 1], (float)$row['opening'], $year . ' opens where the year before closed');
				$this->assertSame(150000.0, (float)$row['additions']);
			}
		}

		$this->assertSame([2026 => 550000.0, 2027 => 700000.0, 2028 => 850000.0, 2029 => 1000000.0, 2030 => 1150000.0], $closing);
		$this->assertCount(10, $overview['rows'], 'two reserves, five years each');
		$this->assertSame(12000000.0, (float)$this->yearOf($overview['rows'], $this->ids['general'], 2030)['closing']);

	}//end testTheOverviewShowsThePlan()

	/**
	 * A closing balance under the floor or over the ceiling is flagged.
	 */
	public function testTheOverviewFlagsFloorAndCeiling(): void {
		$big = ['id' => 'big', 'administrationId' => 'adm-gov-1', 'reserveId' => $this->ids['maintenance'], 'year' => 2027, 'kind' => 'withdrawal', 'amount' => 750000, 'status' => 'planned'];
		$huge = ['id' => 'huge', 'administrationId' => 'adm-gov-1', 'reserveId' => $this->ids['maintenance'], 'year' => 2028, 'kind' => 'addition', 'amount' => 2000000, 'status' => 'planned'];
		$this->seed([$big, $huge]);

		$rows = $this->balances()->overview('adm-gov-1', 2026)['rows'];
		$this->assertFalse($this->yearOf($rows, $this->ids['maintenance'], 2026)['belowFloor']);
		$this->assertTrue($this->yearOf($rows, $this->ids['maintenance'], 2027)['belowFloor'], '50,000 is under the floor of 100,000');
		$this->assertTrue($this->yearOf($rows, $this->ids['maintenance'], 2028)['aboveCeiling'], '2,050,000 is over the ceiling of 1,500,000');

	}//end testTheOverviewFlagsFloorAndCeiling()

	/**
	 * Scenario: the 2026 interest run. Omslagrente 1.2 percent, Sporthal De
	 * Wielewaal at EUR 4,500,000 on task field 5.2, the maintenance reserve at
	 * EUR 800,000 marked for interest.
	 */
	public function testThe2026InterestRunIsCalculatedAndPosted(): void {
		$this->seed();

		$this->runner()->run(objectId: $this->ids['run'], action: 'calculate');
		$run = $this->get('InterestAllocationRun', $this->ids['run']);
		$this->assertSame('calculated', $run['state']);
		$this->assertSame([], $this->registerErrors('InterestAllocationRun', $run));
		$this->assertSame(4500000.0, (float)$run['lines'][0]['bookValue']);
		$this->assertSame('5.2', $run['lines'][0]['taskFieldCode']);
		$this->assertSame(54000.0, (float)$run['lines'][0]['interest']);
		$this->assertCount(1, $run['reserveLines'], 'only the reserve marked for interest');
		$this->assertSame(800000.0, (float)$run['reserveLines'][0]['balance']);
		$this->assertSame(9600.0, (float)$run['reserveLines'][0]['interest']);

		$this->runner()->run(objectId: $this->ids['run'], action: 'post');
		$run = $this->get('InterestAllocationRun', $this->ids['run']);
		$this->assertSame('posted', $run['state']);
		$this->assertSame([], $this->registerErrors('InterestAllocationRun', $run));

		$journal = $this->get('JournalEntry', $run['journalEntryId']);
		$this->assertSame('posted', $journal['state']);
		$this->assertSame([], $this->registerErrors('JournalEntry', array_merge($journal, ['state' => 'draft'])));
		$this->assertSame(
			[['4810', 'debit', 54000.0], ['8050', 'credit', 54000.0], ['8990', 'debit', 9600.0], ['0520', 'credit', 9600.0]],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], (float)$line['amount']], $journal['lines'])
		);
		$this->assertStringContainsString('taakveld 5.2', $journal['lines'][0]['description']);
		$this->assertStringContainsString('taakveld 0.5', $journal['lines'][1]['description']);

		$additions = $this->all('ReserveMutation');
		$this->assertCount(1, $additions);
		$this->assertSame(9600.0, (float)$additions[0]['amount']);
		$this->assertSame('realised', $additions[0]['status']);
		$this->assertSame($run['journalEntryId'], $additions[0]['journalEntryId']);
		$this->assertSame([], $this->registerErrors('ReserveMutation', $additions[0]));

		$this->assertSame(809600.0, $this->balances()->balanceOnFirstJanuary($this->get('Reserve', $this->ids['maintenance']), 2027), 'the interest is in next year\'s opening balance');

	}//end testThe2026InterestRunIsCalculatedAndPosted()

	/**
	 * An investment without a book value or a task field is listed with the
	 * reason and left out of the charge.
	 */
	public function testAnInvestmentWithoutBookValueOrTaskFieldIsLeftOut(): void {
		$this->seed();
		$this->store->setSchema('Investering')->saveObject(['id' => 'no-gross', 'administrationId' => 'adm-gov-1', 'programmeId' => '1', 'description' => 'Kazerne', 'coverage' => 'lening', 'depreciationTerm' => 20, 'firstDepreciationYear' => 2027, 'taskFieldCode' => '1.1', 'capitalChargesSchedule' => []]);
		$this->store->setSchema('Investering')->saveObject(['id' => 'no-field', 'administrationId' => 'adm-gov-1', 'programmeId' => '2', 'description' => 'Fietspad', 'gross' => 900000, 'coverage' => 'lening', 'depreciationTerm' => 30, 'firstDepreciationYear' => 2025, 'capitalChargesSchedule' => []]);

		$run = $this->interest()->calculate($this->get('InterestAllocationRun', $this->ids['run']));
		$byId = array_column($run['lines'], null, 'investmentId');
		$this->assertStringContainsString('No book value', $byId['no-gross']['excludedReason']);
		$this->assertSame(0.0, $byId['no-gross']['interest']);
		$this->assertStringContainsString('No task field', $byId['no-field']['excludedReason']);
		$this->assertSame(870000.0, $byId['no-field']['bookValue'], 'straight line without a stored schedule: 900,000 less one year of 30,000');
		$this->assertSame(54000.0, $run['investmentInterestTotal']);

	}//end testAnInvestmentWithoutBookValueOrTaskFieldIsLeftOut()

	/**
	 * A run without a rate cannot be calculated.
	 */
	public function testARunWithoutARateIsRefused(): void {
		$this->seed();
		$this->expectException(DomainException::class);
		$this->interest()->calculate(['administrationId' => 'adm-gov-1', 'year' => 2026, 'omslagrentePercentage' => 0]);

	}//end testARunWithoutARateIsRefused()

	private function yearOf(array $rows, string $reserveId, int $year): array {
		foreach ($rows as $row) {
			if ($row['reserveId'] === $reserveId && $row['year'] === $year) {
				return $row;
			}
		}

		$this->fail('no row for ' . $reserveId . ' ' . $year);

	}//end yearOf()
}//end class
