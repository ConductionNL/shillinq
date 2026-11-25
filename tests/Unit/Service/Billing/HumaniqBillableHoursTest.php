<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Billing;

use OCA\Shillinq\Service\Billing\BillableHoursSource;
use OCA\Shillinq\Service\Billing\HumaniqBillableHours;
use OCA\Shillinq\Service\HumaniqHoursReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The invoice generator's hours come from humaniq; absent is not zero.
 *
 * @covers \OCA\Shillinq\Service\Billing\HumaniqBillableHours
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
 */
class HumaniqBillableHoursTest extends TestCase {

	/**
	 * A source over a humaniq read double.
	 *
	 * @param array<string, array<string, mixed>>|null $entries   What the read answers.
	 * @param bool                                     $installed Whether humaniq is installed.
	 *
	 * @return HumaniqBillableHours The source.
	 */
	private function source(?array $entries, bool $installed = true): HumaniqBillableHours {
		$reader = $this->createMock(HumaniqHoursReader::class);
		$reader->method('entriesForProject')->willReturnCallback(
			function (string $projectId, string $from, string $until) use ($entries): ?array {
				$this->assertSame(['prj-1', '2026-10-01', '2026-10-31'], [$projectId, $from, $until]);
				return $entries;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('humaniq')->willReturn($installed);

		return new HumaniqBillableHours($reader, $apps);
	}//end source()

	/**
	 * One humaniq time entry as the reader normalises it.
	 *
	 * @param string     $uuid      The entry.
	 * @param float|null $hours     The hours.
	 * @param string     $date      The day.
	 * @param bool       $billable  Billable or not.
	 * @param string     $approval  The approval state.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(string $uuid, ?float $hours, string $date, bool $billable = true, string $approval = 'approved'): array {
		return [
			'uuid' => $uuid, 'personId' => 'emp-' . $uuid, 'date' => $date, 'hours' => $hours,
			'billable' => $billable, 'approvalState' => $approval, 'projectId' => 'prj-1',
			'domainObjectType' => '', 'domainObjectRef' => '', 'administrationId' => 'adm-1',
			'description' => 'Work ' . $uuid,
		];
	}//end entry()

	/**
	 * Only approved, billable entries with hours are offered, in date order.
	 *
	 * @return void
	 */
	public function testApprovedBillableHoursAreOfferedInDateOrder(): void {
		$source = $this->source(
			[
				'te-2' => $this->entry('te-2', 1.5, '2026-10-09'),
				'te-1' => $this->entry('te-1', 2.0, '2026-10-02'),
				'te-3' => $this->entry('te-3', 4.0, '2026-10-03', billable: false),
				'te-4' => $this->entry('te-4', 3.0, '2026-10-04', approval: 'submitted'),
				'te-5' => $this->entry('te-5', null, '2026-10-05'),
			]
		);

		$this->assertInstanceOf(BillableHoursSource::class, $source);
		$this->assertSame(
			[
				'available' => true,
				'reason' => '',
				'hours' => [
					['id' => 'te-1', 'person' => 'emp-te-1', 'date' => '2026-10-02', 'hours' => 2.0, 'description' => 'Work te-1'],
					['id' => 'te-2', 'person' => 'emp-te-2', 'date' => '2026-10-09', 'hours' => 1.5, 'description' => 'Work te-2'],
				],
			],
			$source->unbilled(projectId: 'prj-1', from: '2026-10-01', to: '2026-10-31')
		);
	}//end testApprovedBillableHoursAreOfferedInDateOrder()

	/**
	 * The project card reads the same entries; shillinq decides which are billed.
	 *
	 * @return void
	 */
	public function testTheProjectCardReadsTheSameEntries(): void {
		$read = $this->source(['te-1' => $this->entry('te-1', 2.0, '2026-10-02')])
			->forProject(projectId: 'prj-1', from: '2026-10-01', to: '2026-10-31');

		$this->assertTrue($read['available']);
		$this->assertSame(['te-1'], array_column($read['hours'], 'id'));
	}//end testTheProjectCardReadsTheSameEntries()

	/**
	 * Humaniq holding no hours is an empty, available list.
	 *
	 * @return void
	 */
	public function testNoHoursIsAnAvailableEmptyList(): void {
		$this->assertSame(
			['available' => true, 'reason' => '', 'hours' => []],
			$this->source([])->unbilled(projectId: 'prj-1', from: '2026-10-01', to: '2026-10-31')
		);
	}//end testNoHoursIsAnAvailableEmptyList()

	/**
	 * Humaniq not answering is unavailable, never zero hours.
	 *
	 * @return void
	 */
	public function testHumaniqNotAnsweringIsUnavailable(): void {
		$this->assertSame(
			['available' => false, 'reason' => HumaniqBillableHours::REASON_UNREADABLE, 'hours' => []],
			$this->source(null)->unbilled(projectId: 'prj-1', from: '2026-10-01', to: '2026-10-31')
		);
	}//end testHumaniqNotAnsweringIsUnavailable()

	/**
	 * Without humaniq installed nothing is read and the hours are unavailable.
	 *
	 * @return void
	 */
	public function testWithoutHumaniqNothingIsReadAndHoursAreUnavailable(): void {
		$reader = $this->createMock(HumaniqHoursReader::class);
		$reader->expects($this->never())->method('entriesForProject');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(false);

		$this->assertSame(
			['available' => false, 'reason' => HumaniqBillableHours::REASON_NOT_INSTALLED, 'hours' => []],
			(new HumaniqBillableHours($reader, $apps))->forProject(projectId: 'prj-1', from: '2026-10-01', to: '2026-10-31')
		);
	}//end testWithoutHumaniqNothingIsReadAndHoursAreUnavailable()
}//end class
