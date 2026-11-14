<?php

/**
 * Unit tests for DunningStageSelector: days overdue, the highest stage reached,
 * a stage by number, the stages already sent, and the stage the next tick sends.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use DateTimeImmutable;
use OCA\Shillinq\Service\Dunning\DunningStageSelector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\Dunning\DunningStageSelector
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DunningStageSelectorTest extends TestCase {

	/**
	 * A three-stage ladder, deliberately out of order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const LADDER = [
		['nr' => 3, 'daysAfterExpiryDate' => 30],
		['nr' => 1, 'daysAfterExpiryDate' => 0],
		['nr' => 2, 'daysAfterExpiryDate' => 14],
	];

	/**
	 * A fixed now.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-13T09:00:00+00:00');
	}//end now()

	/**
	 * Days overdue count from the due date to now.
	 *
	 * @return void
	 */
	public function testDaysOverdueCountsFromTheDueDate(): void {
		$selector = new DunningStageSelector();

		self::assertSame(43, $selector->daysOverdue(invoice: ['dueDate' => '2026-10-01T09:00:00+00:00'], now: $this->now()));
		self::assertSame(0, $selector->daysOverdue(invoice: ['dueDate' => '2026-11-13T09:00:00+00:00'], now: $this->now()));
	}//end testDaysOverdueCountsFromTheDueDate()

	/**
	 * An invoice not yet due, or without a due date, is not overdue.
	 *
	 * @return void
	 */
	public function testNotDueOrUndatedIsNotOverdue(): void {
		$selector = new DunningStageSelector();

		self::assertNull($selector->daysOverdue(invoice: ['dueDate' => '2026-12-01T00:00:00+00:00'], now: $this->now()));
		self::assertNull($selector->daysOverdue(invoice: [], now: $this->now()));
		self::assertNull($selector->daysOverdue(invoice: ['dueDate' => ''], now: $this->now()));
	}//end testNotDueOrUndatedIsNotOverdue()

	/**
	 * A malformed due date is logged and treated as not overdue.
	 *
	 * @return void
	 */
	public function testAMalformedDueDateIsLoggedAndNotOverdue(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Shillinq: tickInvoice malformed dueDate: not-a-date');

		$selector = new DunningStageSelector(logger: $logger);

		self::assertNull($selector->daysOverdue(invoice: ['dueDate' => 'not-a-date'], now: $this->now()));
	}//end testAMalformedDueDateIsLoggedAndNotOverdue()

	/**
	 * The highest stage whose threshold is reached wins, whatever the input order.
	 *
	 * @return void
	 */
	public function testHighestReachedPicksTheLastThresholdReached(): void {
		$selector = new DunningStageSelector();

		self::assertSame(1, $selector->highestReached(stages: self::LADDER, daysInArrears: 0)['nr']);
		self::assertSame(1, $selector->highestReached(stages: self::LADDER, daysInArrears: 13)['nr']);
		self::assertSame(2, $selector->highestReached(stages: self::LADDER, daysInArrears: 14)['nr']);
		self::assertSame(3, $selector->highestReached(stages: self::LADDER, daysInArrears: 90)['nr']);
	}//end testHighestReachedPicksTheLastThresholdReached()

	/**
	 * Negative arrears, an empty ladder or an unreached first threshold pick nothing.
	 *
	 * @return void
	 */
	public function testHighestReachedPicksNothingWhenNoThresholdIsReached(): void {
		$selector = new DunningStageSelector();

		self::assertNull($selector->highestReached(stages: self::LADDER, daysInArrears: -1));
		self::assertNull($selector->highestReached(stages: [], daysInArrears: 10));
		self::assertNull($selector->highestReached(stages: [['nr' => 1, 'daysAfterExpiryDate' => 7]], daysInArrears: 6));
	}//end testHighestReachedPicksNothingWhenNoThresholdIsReached()

	/**
	 * A stage is found by its number; an unknown number finds nothing.
	 *
	 * @return void
	 */
	public function testDefinitionFindsAStageByNumber(): void {
		$selector = new DunningStageSelector();

		self::assertSame(['nr' => 2, 'daysAfterExpiryDate' => 14], $selector->definition(stages: self::LADDER, stageNr: 2));
		self::assertNull($selector->definition(stages: self::LADDER, stageNr: 4));
	}//end testDefinitionFindsAStageByNumber()

	/**
	 * Every run but a failed one counts as sent, with its latest date.
	 *
	 * @return void
	 */
	public function testSentOnKeepsTheLatestDateAndSkipsFailedRuns(): void {
		$selector = new DunningStageSelector();

		$sent = $selector->sentOn(
			runs: [
				['stageNr' => 1, 'executedOn' => '2026-10-01', 'deliveryStatus' => 'DELIVERED'],
				['stageNr' => 1, 'executedOn' => '2026-10-05', 'deliveryStatus' => 'PENDING'],
				['stageNr' => 1, 'executedOn' => '2026-10-03'],
				['stageNr' => 2, 'executedOn' => '2026-10-20', 'deliveryStatus' => 'failed'],
				['stageNr' => 3],
			]
		);

		self::assertSame([1 => '2026-10-05', 3 => ''], $sent);
	}//end testSentOnKeepsTheLatestDateAndSkipsFailedRuns()

	/**
	 * With nothing sent, the first stage goes once its threshold is reached.
	 *
	 * @return void
	 */
	public function testNextIsTheFirstStageWhenNothingWasSent(): void {
		$selector = new DunningStageSelector();

		self::assertSame(1, $selector->next(stages: self::LADDER, runs: [], daysInArrears: 0, now: $this->now())['nr']);
		self::assertNull(
			$selector->next(stages: [['nr' => 1, 'daysAfterExpiryDate' => 7], 'not-a-stage'], runs: [], daysInArrears: 3, now: $this->now())
		);
	}//end testNextIsTheFirstStageWhenNothingWasSent()

	/**
	 * The next stage waits for its threshold and for the gap since the previous stage went out.
	 *
	 * @return void
	 */
	public function testNextWaitsForThresholdAndGap(): void {
		$selector = new DunningStageSelector();
		$runs     = [['stageNr' => 1, 'executedOn' => '2026-11-03T09:00:00+00:00']];

		// Threshold of stage 2 (14) not reached.
		self::assertNull($selector->next(stages: self::LADDER, runs: $runs, daysInArrears: 13, now: $this->now()));
		// Threshold reached but only 10 of the 14 gap days have passed since stage 1.
		self::assertNull($selector->next(stages: self::LADDER, runs: $runs, daysInArrears: 20, now: $this->now()));

		$earlier = [['stageNr' => 1, 'executedOn' => '2026-10-30T09:00:00+00:00']];
		self::assertSame(2, $selector->next(stages: self::LADDER, runs: $earlier, daysInArrears: 20, now: $this->now())['nr']);
	}//end testNextWaitsForThresholdAndGap()

	/**
	 * A previous run without a readable date does not hold the next stage back.
	 *
	 * @return void
	 */
	public function testAnUndatedOrUnreadablePreviousRunDoesNotHoldBack(): void {
		$selector = new DunningStageSelector();

		$undated = [['stageNr' => 1]];
		self::assertSame(2, $selector->next(stages: self::LADDER, runs: $undated, daysInArrears: 14, now: $this->now())['nr']);

		$unreadable = [['stageNr' => 1, 'executedOn' => 'yesterday-ish']];
		self::assertSame(2, $selector->next(stages: self::LADDER, runs: $unreadable, daysInArrears: 14, now: $this->now())['nr']);
	}//end testAnUndatedOrUnreadablePreviousRunDoesNotHoldBack()

	/**
	 * A previous run dated after now holds the next stage back.
	 *
	 * @return void
	 */
	public function testAFutureDatedPreviousRunHoldsBack(): void {
		$selector = new DunningStageSelector();
		$runs     = [['stageNr' => 1, 'executedOn' => '2027-01-01T00:00:00+00:00']];

		self::assertNull($selector->next(stages: self::LADDER, runs: $runs, daysInArrears: 60, now: $this->now()));
	}//end testAFutureDatedPreviousRunHoldsBack()

	/**
	 * Once every stage went out, nothing is next; a failed run is sent again.
	 *
	 * @return void
	 */
	public function testNothingIsNextAfterTheLastStageButAFailedOneRepeats(): void {
		$selector = new DunningStageSelector();
		$all      = [
			['stageNr' => 1, 'executedOn' => '2026-09-01'],
			['stageNr' => 2, 'executedOn' => '2026-09-20'],
			['stageNr' => 3, 'executedOn' => '2026-10-20'],
		];

		self::assertNull($selector->next(stages: self::LADDER, runs: $all, daysInArrears: 90, now: $this->now()));

		$all[2]['deliveryStatus'] = 'FAILED';
		self::assertSame(3, $selector->next(stages: self::LADDER, runs: $all, daysInArrears: 90, now: $this->now())['nr']);
	}//end testNothingIsNextAfterTheLastStageButAFailedOneRepeats()
}//end class
