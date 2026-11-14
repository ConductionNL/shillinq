<?php

/**
 * Unit tests for ProjectBillableHours: one month of a project's hours with
 * their rate, tier, value and billed state, and the unbilled totals
 * (REQ-BRPH-003).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Billing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Billing;

use InvalidArgumentException;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\Billing\BillableHoursSource;
use OCA\Shillinq\Service\Billing\ProjectBillableHours;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * October 2026 on Renovatie Kade 12: Alice, Bram, Chris and Dana.
 *
 * @covers \OCA\Shillinq\Service\Billing\ProjectBillableHours
 * @uses   \OCA\Shillinq\Service\BillableRateService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ProjectBillableHoursTest extends TestCase {
	/**
	 * The administration.
	 *
	 * @var string
	 */
	private const ADMIN = 'adm-1';

	/**
	 * The project.
	 *
	 * @var string
	 */
	private const PROJECT = '6b1d4e2a-9c37-4f58-8a21-3e7c5d9b0f12';

	/**
	 * The October hours as the hours source lists them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const HOURS = [
		['id' => 'h-alice', 'person' => 'user-alice', 'date' => '2026-10-14', 'hours' => 6.0, 'description' => 'Bestek'],
		['id' => 'h-bram', 'person' => 'user-bram', 'date' => '2026-10-14', 'hours' => 4.5, 'description' => 'Tekeningen'],
		['id' => 'h-chris', 'person' => 'user-chris', 'date' => '2026-10-15', 'hours' => 2.0, 'description' => 'Overleg'],
		['id' => 'h-dana', 'person' => 'user-dana', 'date' => '2026-10-14', 'hours' => 1.0, 'description' => 'Inmeten'],
	];

	/**
	 * The service over the example register and an hours source.
	 *
	 * @param array<string, mixed>             $source   The hours source's answer.
	 * @param array<int, array<string, mixed>> $invoices BillableInvoice rows.
	 *
	 * @return ProjectBillableHours
	 */
	private function service(array $source, array $invoices = []): ProjectBillableHours {
		$schedule = static fn (string $id, string $tier, ?string $entity, float $rate): array => [
			'id' => $id, 'versionId' => 'rcv-std', 'tier' => $tier, 'entityId' => $entity, 'rate' => $rate, 'unit' => 'hourly',
			'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => self::ADMIN,
		];
		$assignment = static fn (string $person, ?string $role): array => [
			'id' => 'pa-' . $person, 'administrationId' => self::ADMIN, 'personId' => $person, 'projectId' => self::PROJECT,
			'rateCardId' => 'RCT-STD', 'billingRoleId' => $role, 'startDate' => '2026-09-01',
		];
		$store = new InMemoryObjectServiceStub(
			[
				'engagement' => [
					['id' => self::PROJECT, 'administrationId' => self::ADMIN, 'name' => 'Renovatie Kade 12', 'customerId' => 'cust-het-anker'],
					['id' => 'proj-other', 'administrationId' => 'adm-2', 'name' => 'Elders'],
				],
				'ProjectAssignment' => [$assignment('user-alice', 'role-senior'), $assignment('user-bram', 'role-senior'), $assignment('user-chris', null)],
				'RateCardTemplate' => [['id' => 'rct-std', 'templateId' => 'RCT-STD', 'administrationId' => self::ADMIN]],
				'RateCardVersion' => [
					['id' => 'rcv-std', 'templateId' => 'RCT-STD', 'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => self::ADMIN],
				],
				'RateSchedule' => [
					$schedule('rs-blended', 'blended', null, 85),
					$schedule('rs-senior', 'role', 'role-senior', 120),
					$schedule('rs-alice', 'user', 'user-alice', 130),
					$schedule('rs-het-anker', 'client', 'cust-het-anker', 95),
				],
				'BillableInvoice' => $invoices,
			]
		);
		$hours = $this->createMock(BillableHoursSource::class);
		$hours->method('forProject')->with(self::PROJECT, '2026-10-01', '2026-10-31')->willReturn($source);
		$register = $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);

		return new ProjectBillableHours($hours, new BillableRateService($store, $register, $this->createMock(ITimeFactory::class)), $store, $register);
	}//end service()

	/**
	 * October is worth EUR 1,510 over 12.5 rated hours, with 1 hour without a rate.
	 *
	 * @return void
	 */
	public function testAProjectManagerSeesWhatOctoberIsWorth(): void {
		$service = $this->service(['available' => true, 'reason' => '', 'hours' => self::HOURS]);

		$answer = $service->forMonth($service->project(self::PROJECT, self::ADMIN), '2026-10');

		self::assertTrue($answer['available']);
		self::assertSame(['hours' => 13.5, 'unbilledHours' => 12.5, 'unbilledValue' => 1510.0, 'unratedHours' => 1.0], $answer['totals']);
		self::assertCount(4, $answer['rows']);
		$byPerson = array_column($answer['rows'], null, 'person');
		self::assertSame([130.0, 'user', 780.0], [$byPerson['user-alice']['rate'], $byPerson['user-alice']['tier'], $byPerson['user-alice']['value']]);
		self::assertSame([120.0, 'role', 540.0], [$byPerson['user-bram']['rate'], $byPerson['user-bram']['tier'], $byPerson['user-bram']['value']]);
		self::assertSame([95.0, 'client', 190.0], [$byPerson['user-chris']['rate'], $byPerson['user-chris']['tier'], $byPerson['user-chris']['value']]);
		self::assertSame(
			['no-rate', null, 'no-assignment', 'user-dana is not assigned to this project'],
			[$byPerson['user-dana']['status'], $byPerson['user-dana']['value'], $byPerson['user-dana']['reason'], $byPerson['user-dana']['message']]
		);
		self::assertSame(['2026-10-14', '2026-10-14', '2026-10-14', '2026-10-15'], array_column($answer['rows'], 'date'));
	}//end testAProjectManagerSeesWhatOctoberIsWorth()

	/**
	 * A billed hour names its invoice and leaves the unbilled total; a
	 * cancelled invoice bills nothing.
	 *
	 * @return void
	 */
	public function testBilledHoursShowTheirInvoice(): void {
		$service = $this->service(
			['available' => true, 'reason' => '', 'hours' => self::HOURS],
			[
				['id' => 'inv-1', 'administrationId' => self::ADMIN, 'invoiceNumber' => '2026-T-0031', 'status' => 'posted', 'timeEntryIds' => ['h-alice']],
				['id' => 'inv-2', 'administrationId' => self::ADMIN, 'invoiceNumber' => '2026-T-0029', 'status' => 'cancelled', 'timeEntryIds' => ['h-bram']],
				['id' => 'inv-3', 'administrationId' => 'adm-2', 'invoiceNumber' => 'X-1', 'status' => 'posted', 'timeEntryIds' => ['h-chris']],
			]
		);

		$answer = $service->forMonth($service->project(self::PROJECT, self::ADMIN), '2026-10');

		$byPerson = array_column($answer['rows'], null, 'person');
		self::assertSame([true, '2026-T-0031'], [$byPerson['user-alice']['billed'], $byPerson['user-alice']['invoiceNumber']]);
		self::assertSame([false, null], [$byPerson['user-bram']['billed'], $byPerson['user-bram']['invoiceNumber']]);
		self::assertFalse($byPerson['user-chris']['billed']);
		self::assertSame(6.5, $answer['totals']['unbilledHours']);
		self::assertSame(730.0, $answer['totals']['unbilledValue']);
	}//end testBilledHoursShowTheirInvoice()

	/**
	 * Without an hours source the answer says so instead of showing zero hours.
	 *
	 * @return void
	 */
	public function testWithoutAnHoursSourceTheAnswerSaysSo(): void {
		$service = $this->service(['available' => false, 'reason' => 'no-hours-source', 'hours' => []]);

		$answer = $service->forMonth($service->project(self::PROJECT, self::ADMIN), '2026-10');

		self::assertSame([false, 'no-hours-source', []], [$answer['available'], $answer['reason'], $answer['rows']]);
		self::assertSame(['2026-10', '2026-10-01', '2026-10-31'], [$answer['month'], $answer['from'], $answer['to']]);
	}//end testWithoutAnHoursSourceTheAnswerSaysSo()

	/**
	 * A project of another administration is not found.
	 *
	 * @return void
	 */
	public function testAProjectOfAnotherAdministrationIsNotFound(): void {
		$service = $this->service(['available' => true, 'reason' => '', 'hours' => []]);

		self::assertNull($service->project('proj-other', self::ADMIN));
		self::assertNull($service->project(self::PROJECT, 'adm-2'));
		self::assertNull($service->project(self::PROJECT, ''));
	}//end testAProjectOfAnotherAdministrationIsNotFound()

	/**
	 * A month is a calendar month; anything else is refused.
	 *
	 * @return void
	 */
	public function testAMonthIsACalendarMonth(): void {
		self::assertSame(['2026-02-01', '2026-02-28'], ProjectBillableHours::monthWindow('2026-02'));
		self::assertSame(['2028-02-01', '2028-02-29'], ProjectBillableHours::monthWindow('2028-02'));

		$this->expectException(InvalidArgumentException::class);
		ProjectBillableHours::monthWindow('2026-13');
	}//end testAMonthIsACalendarMonth()
}//end class
