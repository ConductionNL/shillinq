<?php

/**
 * Unit tests for BillableRateService.
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
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTime;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BRPH-001 and REQ-BRPH-002 on the rate card Standaard 2026 of Het Anker:
 * each tier, a mid-month rate change, each "no rate" reason, a card of
 * another administration, and the RateRecord each resolution writes.
 *
 * @covers \OCA\Shillinq\Service\BillableRateService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class BillableRateServiceTest extends TestCase {
	/**
	 * Objects the service saved.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The seeded objects per schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The data.
	 */
	private function data(): array {
		$schedule = static fn (string $id, string $tier, ?string $entity, float $rate, string $from = '2026-01-01', ?string $to = null, string $unit = 'hourly', string $status = 'active', string $version = 'v-2026'): array => [
			'id' => "s-{$id}",
			'scheduleId' => $id,
			'versionId' => $version,
			'tier' => $tier,
			'entityId' => $entity,
			'rate' => $rate,
			'unit' => $unit,
			'effectiveDate' => $from,
			'expiryDate' => $to,
			'status' => $status,
			'administrationId' => 'adm-1',
		];

		return [
			'ProjectAssignment' => [
				['id' => 'pa-1', 'personId' => 'alice', 'projectId' => 'kade12', 'rateCardId' => 'standaard-2026', 'billingRoleId' => 'senior', 'startDate' => '2026-01-01', 'administrationId' => 'adm-1'],
				['id' => 'pa-2', 'personId' => 'bram', 'projectId' => 'kade12', 'rateCardId' => 'standaard-2026', 'billingRoleId' => 'senior', 'startDate' => '2026-01-01', 'administrationId' => 'adm-1'],
				['id' => 'pa-3', 'personId' => 'chris', 'projectId' => 'kade12', 'rateCardId' => 'standaard-2026', 'billingRoleId' => '', 'startDate' => '2026-01-01', 'capacityHoursPerWeek' => 32, 'administrationId' => 'adm-1'],
				['id' => 'pa-4', 'personId' => 'eva', 'projectId' => 'kade12', 'rateCardId' => '', 'startDate' => '2026-01-01', 'administrationId' => 'adm-1'],
				['id' => 'pa-5', 'personId' => 'finn', 'projectId' => 'kade12', 'rateCardId' => 'card-other', 'startDate' => '2026-01-01', 'administrationId' => 'adm-1'],
				['id' => 'pa-6', 'personId' => 'gijs', 'projectId' => 'kade12', 'rateCardId' => 'standaard-2026', 'startDate' => '2026-11-01', 'administrationId' => 'adm-1'],
				['id' => 'pa-7', 'personId' => 'hana', 'projectId' => 'kade12', 'rateCardId' => 'oud', 'startDate' => '2026-01-01', 'administrationId' => 'adm-1'],
			],
			'RateCardTemplate' => [
				['id' => 't-1', 'templateId' => 'standaard-2026', 'name' => 'Standaard 2026', 'administrationId' => 'adm-1'],
				['id' => 't-2', 'templateId' => 'oud', 'name' => 'Oud', 'administrationId' => 'adm-1'],
				['id' => 't-9', 'templateId' => 'card-other', 'name' => 'Andere administratie', 'administrationId' => 'adm-2'],
			],
			'RateCardVersion' => [
				['id' => 'cv-1', 'versionId' => 'v-2026', 'templateId' => 'standaard-2026', 'effectiveDate' => '2026-01-01', 'expiryDate' => null, 'status' => 'active', 'administrationId' => 'adm-1'],
				['id' => 'cv-2', 'versionId' => 'v-draft', 'templateId' => 'standaard-2026', 'effectiveDate' => '2026-10-01', 'status' => 'draft', 'administrationId' => 'adm-1'],
				['id' => 'cv-3', 'versionId' => 'v-oud', 'templateId' => 't-2', 'effectiveDate' => '2025-01-01', 'expiryDate' => '2025-12-31', 'status' => 'expired', 'administrationId' => 'adm-1'],
				['id' => 'cv-9', 'versionId' => 'v-other', 'templateId' => 'card-other', 'effectiveDate' => '2026-01-01', 'status' => 'active', 'administrationId' => 'adm-2'],
			],
			'RateSchedule' => [
				$schedule('alice-1', 'user', 'alice', 130.0, '2026-01-01', '2026-10-15'),
				$schedule('alice-2', 'user', 'alice', 135.0, '2026-10-16'),
				$schedule('senior', 'role', 'senior', 120.0),
				$schedule('anker', 'client', 'het-anker', 760.0, unit: 'daily'),
				$schedule('kade12-old', 'project', 'kade12', 110.0, status: 'inactive'),
				$schedule('kade12-fixed', 'project', 'kade12', 5000.0, unit: 'fixedPrice'),
				$schedule('draft-user', 'user', 'chris', 999.0, version: 'v-draft'),
				$schedule('blended', 'blended', null, 85.0, '2026-12-01'),
				['id' => 's-x', 'scheduleId' => 'other', 'versionId' => 'v-other', 'tier' => 'blended', 'entityId' => null, 'rate' => 50.0, 'unit' => 'hourly', 'effectiveDate' => '2026-01-01', 'status' => 'active', 'administrationId' => 'adm-2'],
			],
		];
	}//end data()

	/**
	 * The service over the seeded data, with the clock at 2026-11-13 10:00 UTC.
	 *
	 * @param array<string, array<int, array<string, mixed>>>|null $data Other data.
	 *
	 * @return BillableRateService The service.
	 */
	private function service(?array $data = null): BillableRateService {
		$this->saved = [];
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));

		return new BillableRateService(
			objectService: new InMemoryObjectServiceStub(data: ($data ?? $this->data()), saveSink: $this->saved),
			registerLocation: $location,
			timeFactory: $clock
		);
	}//end service()

	/**
	 * An hour on Renovatie Kade 12.
	 *
	 * @param string $person The person.
	 * @param float  $hours  The hours.
	 * @param string $date   The date.
	 * @param array<string, string> $extra Extra keys.
	 *
	 * @return array<string, mixed> The hour.
	 */
	private function hour(string $person, float $hours, string $date = '2026-10-14', array $extra = []): array {
		return $extra + ['personId' => $person, 'projectId' => 'kade12', 'date' => $date, 'hours' => $hours, 'administrationId' => 'adm-1', 'clientId' => 'het-anker'];
	}//end hour()

	/**
	 * A personal rate wins over the role rate, and the resolution is recorded.
	 *
	 * @return void
	 */
	public function testAPersonalRateWinsOverTheRoleRate(): void {
		$answer = $this->service()->resolve(hour: $this->hour(person: 'alice', hours: 6));

		$this->assertSame(BillableRateService::STATUS_RATED, $answer['status']);
		$this->assertSame([130.0, 'hourly', 'user', 'alice-1', 780.0], [$answer['rate'], $answer['unit'], $answer['tier'], $answer['scheduleId'], $answer['value']]);
		$this->assertMatchesRegularExpression('/^rr-[0-9a-f]{16}$/', $answer['recordId']);
		$this->assertCount(1, $this->saved);
		$this->assertSame('RateRecord', $this->saved[0]['schema']);
		$record = $this->saved[0]['object'];
		unset($record['id']);
		$this->assertSame(
			[
				'recordId' => $answer['recordId'],
				'lookupDate' => '2026-10-14',
				'userId' => 'alice',
				'roleId' => 'senior',
				'projectId' => 'kade12',
				'clientId' => 'het-anker',
				'resolvedTier' => 'user',
				'resolvedScheduleId' => 'alice-1',
				'resolvedRate' => 130.0,
				'resolvedUnit' => 'hourly',
				'effectiveWindowStart' => '2026-01-01',
				'effectiveWindowEnd' => '2026-10-15',
				'administrationId' => 'adm-1',
				'createdAt' => '2026-11-13T10:00:00+00:00',
			],
			$record
		);
	}//end testAPersonalRateWinsOverTheRoleRate()

	/**
	 * The role rate applies to a person without a personal rate.
	 *
	 * @return void
	 */
	public function testTheRoleRateApplies(): void {
		$answer = $this->service()->resolve(hour: $this->hour(person: 'bram', hours: 4.5));

		$this->assertSame([120.0, 'role', 'senior', 540.0], [$answer['rate'], $answer['tier'], $answer['scheduleId'], $answer['value']]);
		$this->assertNull($this->saved[0]['object']['effectiveWindowEnd']);
	}//end testTheRoleRateApplies()

	/**
	 * The client rate applies to an assignment without a role; a daily rate
	 * divides by the assignment's working day; an inactive or fixed-price
	 * project schedule and another version's schedule do not count.
	 *
	 * @return void
	 */
	public function testTheClientRateAppliesToAnAssignmentWithoutARole(): void {
		$answer = $this->service()->resolve(hour: $this->hour(person: 'chris', hours: 2));

		// 32 hours a week is 6.4 hours a day: 2 hours are 0.3125 of a 760 day.
		$this->assertSame([760.0, 'daily', 'client', 'anker', 237.5], [$answer['rate'], $answer['unit'], $answer['tier'], $answer['scheduleId'], $answer['value']]);
	}//end testTheClientRateAppliesToAnAssignmentWithoutARole()

	/**
	 * A daily rate without a capacity divides by an eight-hour day.
	 *
	 * @return void
	 */
	public function testADailyRateUsesAnEightHourDayByDefault(): void {
		$data = $this->data();
		$data['ProjectAssignment'][2]['capacityHoursPerWeek'] = null;

		$answer = $this->service(data: $data)->resolve(hour: $this->hour(person: 'chris', hours: 4));

		$this->assertSame(380.0, $answer['value']);
	}//end testADailyRateUsesAnEightHourDayByDefault()

	/**
	 * A rate change mid-month prices each hour by its own date.
	 *
	 * @return void
	 */
	public function testAMidMonthChangePricesEachHourByItsDate(): void {
		$service = $this->service();

		$this->assertSame(
			[[130.0, 'alice-1'], [135.0, 'alice-2']],
			[
				array_values(array_intersect_key($service->resolve(hour: $this->hour(person: 'alice', hours: 1, date: '2026-10-15')), ['rate' => 0, 'scheduleId' => 0])),
				array_values(array_intersect_key($service->resolve(hour: $this->hour(person: 'alice', hours: 1, date: '2026-10-16')), ['rate' => 0, 'scheduleId' => 0])),
			]
		);
	}//end testAMidMonthChangePricesEachHourByItsDate()

	/**
	 * The blended rate applies when nothing more specific does.
	 *
	 * @return void
	 */
	public function testTheBlendedRateIsTheLastTier(): void {
		$answer = $this->service()->resolve(hour: $this->hour(person: 'bram', hours: 2, date: '2026-12-02', extra: ['clientId' => '']));

		// Bram's role rate still wins in December.
		$this->assertSame('role', $answer['tier']);

		$data = $this->data();
		$data['ProjectAssignment'][1]['billingRoleId'] = '';
		$answer = $this->service(data: $data)->resolve(hour: $this->hour(person: 'bram', hours: 2, date: '2026-12-02', extra: ['clientId' => '']));
		$this->assertSame(['blended', 85.0, 170.0], [$answer['tier'], $answer['rate'], $answer['value']]);
	}//end testTheBlendedRateIsTheLastTier()

	/**
	 * Each "no rate" reason, and no record for any of them.
	 *
	 * @return void
	 */
	public function testEachNoRateReason(): void {
		$service = $this->service();
		$answers = [
			$service->resolve(hour: $this->hour(person: 'dana', hours: 1, extra: ['personName' => 'Dana'])),
			$service->resolve(hour: $this->hour(person: 'gijs', hours: 1)),
			$service->resolve(hour: $this->hour(person: 'eva', hours: 1, extra: ['personName' => 'Eva'])),
			$service->resolve(hour: $this->hour(person: 'hana', hours: 1)),
			$service->resolve(hour: $this->hour(person: 'bram', hours: 1, date: '2025-06-01')),
			$service->resolve(hour: $this->hour(person: 'chris', hours: 1, extra: ['clientId' => ''])),
		];

		$this->assertSame(
			[
				['status' => 'no-rate', 'reason' => 'no-assignment', 'message' => 'Dana is not assigned to this project'],
				['status' => 'no-rate', 'reason' => 'no-assignment', 'message' => 'gijs is not assigned to this project'],
				['status' => 'no-rate', 'reason' => 'no-rate-card', 'message' => 'The assignment of Eva names no rate card'],
				['status' => 'no-rate', 'reason' => 'no-version', 'message' => 'The rate card has no version effective on 2026-10-14'],
				['status' => 'no-rate', 'reason' => 'no-assignment', 'message' => 'bram is not assigned to this project'],
				['status' => 'no-rate', 'reason' => 'no-schedule', 'message' => 'No rate of the rate card applies to chris on 2026-10-14'],
			],
			$answers
		);
		$this->assertSame([], $this->saved);
	}//end testEachNoRateReason()

	/**
	 * A rate card of another administration never resolves, even when the
	 * assignment names it.
	 *
	 * @return void
	 */
	public function testACardOfAnotherAdministrationNeverResolves(): void {
		$answer = $this->service()->resolve(hour: $this->hour(person: 'finn', hours: 1));

		$this->assertSame(['no-rate', 'no-rate-card'], [$answer['status'], $answer['reason']]);
	}//end testACardOfAnotherAdministrationNeverResolves()

	/**
	 * An hour without an administration or a date has no assignment.
	 *
	 * @return void
	 */
	public function testAnHourWithoutScopeHasNoRate(): void {
		$service = $this->service();

		$this->assertSame('no-assignment', $service->resolve(hour: ['personId' => 'alice', 'projectId' => 'kade12', 'date' => '2026-10-14', 'hours' => 1])['reason']);
		$this->assertSame('no-assignment', $service->resolve(hour: ['personId' => 'alice', 'projectId' => 'kade12', 'hours' => 1, 'administrationId' => 'adm-1'])['reason']);
	}//end testAnHourWithoutScopeHasNoRate()
}//end class
