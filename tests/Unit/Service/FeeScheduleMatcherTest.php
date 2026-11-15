<?php

/**
 * Unit tests for FeeScheduleMatcher: which fee entry applies to an intake channel,
 * when two schedules overlap, and how the overlap is explained.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FeeScheduleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\FeeScheduleMatcher
 * @uses   \OCA\Shillinq\Support\DateWindow
 */
class FeeScheduleMatcherTest extends TestCase {

	/**
	 * The fee entries of one schedule.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const ENTRIES = [
		['id' => 'counter', 'intakeChannel' => 'balie', 'amount' => 45.0],
		['id' => 'default', 'amount' => 40.0],
		['id' => 'online', 'intakeChannel' => 'digitaal', 'amount' => 35.0],
		['id' => 'default-2', 'intakeChannel' => '', 'amount' => 99.0],
	];

	/**
	 * The entry for a channel is its own, else the first entry without a channel.
	 *
	 * @return void
	 */
	public function testTheChannelEntryFallsBackToTheDefault(): void {
		$matcher = new FeeScheduleMatcher();

		$this->assertSame('online', $matcher->channelEntry(entries: self::ENTRIES, intakeChannel: 'digitaal')['id']);
		$this->assertSame('default', $matcher->channelEntry(entries: self::ENTRIES, intakeChannel: 'post')['id']);
		$this->assertSame('default', $matcher->channelEntry(entries: self::ENTRIES, intakeChannel: '')['id']);
		$this->assertSame('default', $matcher->channelEntry(entries: self::ENTRIES, intakeChannel: 'Balie')['id']);
	}//end testTheChannelEntryFallsBackToTheDefault()

	/**
	 * Without a matching or default entry there is none.
	 *
	 * @return void
	 */
	public function testWithoutADefaultThereIsNoEntry(): void {
		$matcher = new FeeScheduleMatcher();
		$entries = [['id' => 'counter', 'intakeChannel' => 'balie']];

		$this->assertNull($matcher->channelEntry(entries: $entries, intakeChannel: 'post'));
		$this->assertNull($matcher->channelEntry(entries: $entries, intakeChannel: ''));
		$this->assertNull($matcher->channelEntry(entries: [], intakeChannel: 'balie'));
	}//end testWithoutADefaultThereIsNoEntry()

	/**
	 * Two schedules of the same channel overlap when their windows meet, an open end reaching forever.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: bool}>
	 */
	public static function windows(): array {
		$jan = ['validFrom' => '2026-01-01', 'validTo' => '2026-01-31'];
		return [
			'the same window' => [$jan, $jan, true],
			'touching on one day' => [$jan, ['validFrom' => '2026-01-31', 'validTo' => '2026-02-28'], true],
			'the next day' => [$jan, ['validFrom' => '2026-02-01'], false],
			'an open-ended earlier schedule' => [['validFrom' => '2025-01-01'], ['validFrom' => '2030-06-01', 'validTo' => '2030-06-30'], true],
			'both open-ended' => [['validFrom' => '2026-01-01', 'validTo' => ''], ['validFrom' => '2027-01-01'], true],
			'a later schedule ending before' => [['validFrom' => '2026-03-01'], $jan, false],
			'a schedule without a start' => [['validTo' => '2026-12-31'], $jan, false],
		];
	}//end windows()

	/**
	 * Overlap follows the validity windows.
	 *
	 * @dataProvider windows
	 *
	 * @param array<string, string> $schedule  The schedule being saved.
	 * @param array<string, string> $candidate An existing schedule.
	 * @param bool                  $overlaps  Whether they overlap.
	 *
	 * @return void
	 */
	public function testOverlapFollowsTheWindows(array $schedule, array $candidate, bool $overlaps): void {
		$this->assertSame($overlaps, (new FeeScheduleMatcher())->overlapsWith(schedule: $schedule, candidate: $candidate));
	}//end testOverlapFollowsTheWindows()

	/**
	 * A schedule never overlaps itself, nor one of another channel; without ids both are compared.
	 *
	 * @return void
	 */
	public function testItselfAndOtherChannelsNeverOverlap(): void {
		$matcher  = new FeeScheduleMatcher();
		$schedule = ['id' => 'fs-1', 'intakeChannel' => 'balie', 'validFrom' => '2026-01-01'];

		$this->assertFalse($matcher->overlapsWith(schedule: $schedule, candidate: $schedule));
		$this->assertFalse($matcher->overlapsWith(schedule: $schedule, candidate: ['id' => 'fs-2', 'intakeChannel' => 'digitaal', 'validFrom' => '2026-01-01']));
		$this->assertFalse($matcher->overlapsWith(schedule: $schedule, candidate: ['id' => 'fs-2', 'validFrom' => '2026-01-01']));
		$this->assertTrue($matcher->overlapsWith(schedule: $schedule, candidate: ['id' => 'fs-2', 'intakeChannel' => 'balie', 'validFrom' => '2026-06-01']));

		$unsaved = ['intakeChannel' => 'balie', 'validFrom' => '2026-01-01'];
		$this->assertTrue($matcher->overlapsWith(schedule: $unsaved, candidate: $unsaved + ['id' => '']));
	}//end testItselfAndOtherChannelsNeverOverlap()

	/**
	 * The overlap message names the window and the schedule, leaving out what is absent.
	 *
	 * @return void
	 */
	public function testTheOverlapMessage(): void {
		$matcher = new FeeScheduleMatcher();

		$this->assertSame(
			'This fee overlaps the schedule valid from 2026-01-01 to 2026-06-30 (fs-1); close that one before opening this.',
			$matcher->overlapMessage(candidate: ['id' => 'fs-1', 'validFrom' => '2026-01-01', 'validTo' => '2026-06-30'])
		);
		$this->assertSame(
			'This fee overlaps the schedule valid from 2026-01-01; close that one before opening this.',
			$matcher->overlapMessage(candidate: ['validFrom' => '2026-01-01', 'validTo' => ''])
		);
		$this->assertSame(
			'This fee overlaps the schedule valid from ?; close that one before opening this.',
			$matcher->overlapMessage(candidate: [])
		);
	}//end testTheOverlapMessage()
}//end class
