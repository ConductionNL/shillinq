<?php

/**
 * Unit tests for the assignment hours budget.
 *
 * The percentage used and the remaining hours exist only for an assignment
 * with a positive estimate; a warning flag, once raised, stays raised until
 * the assignment is re-armed; a recalculation that changes nothing writes
 * nothing; and an assignment or project that is not there is skipped.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Project
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

namespace OCA\Shillinq\Tests\Unit\Service\Project;

use OCA\Shillinq\Service\Project\AssignmentHours;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Budget arithmetic and recalculation edges of AssignmentHours.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(AssignmentHours::class)]
#[UsesClass(ObjectIdentifier::class)]
final class AssignmentHoursTest extends TestCase {

	/**
	 * What the store saved.
	 *
	 * @var array<int, mixed>
	 */
	private array $saved = [];

	/**
	 * The service over the given rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $data Rows per schema.
	 *
	 * @return AssignmentHours
	 */
	private function hours(array $data = []): AssignmentHours {
		$this->saved = [];
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new AssignmentHours(new InMemoryObjectServiceStub($data, $this->saved, true), $settings);
	}//end hours()

	/**
	 * The percentage used needs a positive numeric estimate.
	 *
	 * @return void
	 */
	public function testThePercentageNeedsAPositiveEstimate(): void {
		$hours = $this->hours();

		self::assertSame(62.5, $hours->usedPercent(80, 50.0));
		self::assertSame(33.3, $hours->usedPercent('30', 10.0));
		self::assertNull($hours->usedPercent(0, 10.0));
		self::assertNull($hours->usedPercent(-5, 10.0));
		self::assertNull($hours->usedPercent('veel', 10.0));
		self::assertNull($hours->usedPercent(null, 10.0));
	}//end testThePercentageNeedsAPositiveEstimate()

	/**
	 * The budget raises each flag at its threshold and keeps a raised one.
	 *
	 * @return void
	 */
	public function testTheBudgetRaisesFlagsAtTheirThresholdAndKeepsThem(): void {
		$hours = $this->hours();

		self::assertSame(
			['loggedHours' => 79.0, 'remainingHours' => 21.0, 'hoursWarnedAt80' => false, 'hoursWarnedAt100' => false],
			$hours->budget(['estimatedHours' => 100], 79.0)
		);
		self::assertSame(
			['loggedHours' => 80.0, 'remainingHours' => 20.0, 'hoursWarnedAt80' => true, 'hoursWarnedAt100' => false],
			$hours->budget(['estimatedHours' => 100], 80.0)
		);
		self::assertSame(
			['loggedHours' => 110.5, 'remainingHours' => -10.5, 'hoursWarnedAt80' => true, 'hoursWarnedAt100' => true],
			$hours->budget(['estimatedHours' => 100], 110.5)
		);
		self::assertSame(
			['loggedHours' => 10.0, 'remainingHours' => 90.0, 'hoursWarnedAt80' => true, 'hoursWarnedAt100' => false],
			$hours->budget(['estimatedHours' => 100, 'hoursWarnedAt80' => true], 10.0),
			'a raised flag stays raised when hours go back down'
		);
		self::assertSame(
			['loggedHours' => 500.0, 'remainingHours' => null, 'hoursWarnedAt80' => false, 'hoursWarnedAt100' => false],
			$hours->budget([], 500.0),
			'without an estimate there is nothing to warn about'
		);
	}//end testTheBudgetRaisesFlagsAtTheirThresholdAndKeepsThem()

	/**
	 * An assignment that is not there is neither recalculated nor re-armed.
	 *
	 * @return void
	 */
	public function testAMissingAssignmentIsSkipped(): void {
		$hours = $this->hours(['ProjectAssignment' => []]);

		self::assertFalse($hours->recalculate('pa-gone'));
		self::assertFalse($hours->rearm('pa-gone'));
		self::assertFalse($hours->recalculate(''));
		self::assertSame([], $this->saved);
	}//end testAMissingAssignmentIsSkipped()

	/**
	 * An assignment already up to date, without a project, writes nothing.
	 *
	 * @return void
	 */
	public function testAnUpToDateAssignmentWritesNothing(): void {
		$hours = $this->hours(
			[
				'ProjectAssignment' => [
					['id' => 'pa-1', 'estimatedHours' => 40, 'loggedHours' => '12', 'remainingHours' => 28, 'hoursWarnedAt80' => false, 'hoursWarnedAt100' => false],
				],
				'UrenRegistratie' => [
					['id' => 'u-1', 'projectAssignmentId' => 'pa-1', 'hours' => 8],
					['id' => 'u-2', 'projectAssignmentId' => 'pa-1', 'hours' => 4],
				],
			]
		);

		self::assertFalse($hours->recalculate('pa-1'), 'a stored "12" equals 12.0 logged');
		self::assertSame([], $this->saved);
	}//end testAnUpToDateAssignmentWritesNothing()

	/**
	 * The ids of every assignment that has one.
	 *
	 * @return void
	 */
	public function testAssignmentIdsListsEveryIdentifiedAssignment(): void {
		$hours = $this->hours(['ProjectAssignment' => [['id' => 'pa-1'], ['name' => 'no id'], ['id' => 'pa-2']]]);

		self::assertSame(['pa-1', 'pa-2'], $hours->assignmentIds());
	}//end testAssignmentIdsListsEveryIdentifiedAssignment()
}//end class
