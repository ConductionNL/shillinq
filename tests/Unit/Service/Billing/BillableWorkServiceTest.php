<?php

/**
 * BillableWorkServiceTest
 *
 * @category  Tests
 * @package   OCA\Shillinq\Tests\Unit\Service\Billing
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Billing;

use OCA\Shillinq\Service\Billing\BillableHoursSource;
use OCA\Shillinq\Service\Billing\BillableWorkService;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unbilled hours of a customer's project and unbilled pass-through items of its claims.
 */
#[CoversClass(BillableWorkService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class BillableWorkServiceTest extends TestCase {

	/**
	 * The hours the source reports for project p-kade in October.
	 *
	 * @var list<array<string,mixed>>
	 */
	private const HOURS = [
		['id' => 'h-1', 'person' => 'S. de Vries', 'date' => '2026-10-05', 'hours' => 7.5, 'description' => 'Inspectie', 'resourceType' => 'senior_consultant'],
		['id' => 'h-2', 'person' => 'S. de Vries', 'date' => '2026-10-06', 'hours' => 5.0, 'description' => 'Rapport', 'resourceType' => 'senior_consultant'],
	];

	/**
	 * The ids a draft or posted invoice already references, by kind.
	 *
	 * @var array{timeEntryIds: list<string>, expenseIds: list<string>}
	 */
	private array $billed = ['timeEntryIds' => [], 'expenseIds' => []];

	/**
	 * The hours source.
	 *
	 * @var BillableHoursSource&MockObject
	 */
	private BillableHoursSource&MockObject $hours;

	/**
	 * The rate card resolver.
	 *
	 * @var RateCardResolver&MockObject
	 */
	private RateCardResolver&MockObject $rates;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->hours = $this->createMock(BillableHoursSource::class);
		$this->hours->method('unbilled')->willReturn(['available' => true, 'reason' => '', 'hours' => self::HOURS]);
		$this->rates = $this->createMock(RateCardResolver::class);
	}//end setUp()

	/**
	 * The service over a store with customer anker, its project and claims, and a second administration.
	 *
	 * @return BillableWorkService
	 */
	private function service(): BillableWorkService {
		$store = new InMemoryObjectServiceStub(
			data: [
				'engagement' => [
					['id' => 'p-kade', 'administrationId' => 'adm-1', 'customerId' => 'anker'],
					['id' => 'p-other', 'administrationId' => 'adm-1', 'customerId' => 'other'],
				],
				'ExpenseClaimEntry' => [
					['id' => 'c-posted', 'administrationId' => 'adm-1', 'state' => 'posted', 'claimNumber' => 'DEC-7'],
					['id' => 'c-draft', 'administrationId' => 'adm-1', 'state' => 'draft'],
					['id' => 'c-foreign', 'administrationId' => 'adm-2', 'state' => 'posted'],
				],
				'Receipt' => [
					['id' => 'r-train', 'administrationId' => 'adm-1', 'claimId' => 'c-posted', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 42.80, 'receiptDate' => '2026-10-03', 'markupRateApplied' => 10, 'markupAmountCalculated' => 4.28],
					['id' => 'r-parking', 'administrationId' => 'adm-1', 'claimId' => 'c-posted', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 12.50, 'receiptDate' => '2026-10-03'],
					['id' => 'r-own', 'administrationId' => 'adm-1', 'claimId' => 'c-posted', 'settlementMode' => 'reimbursable', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 8.00],
					['id' => 'r-draft-claim', 'administrationId' => 'adm-1', 'claimId' => 'c-draft', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 20.00],
					['id' => 'r-other-customer', 'administrationId' => 'adm-1', 'claimId' => 'c-posted', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'other',
						'amountInBaseCurrency' => 9.00],
					['id' => 'r-foreign', 'administrationId' => 'adm-2', 'claimId' => 'c-foreign', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 99.00],
				],
				'MileageEntry' => [
					['id' => 'm-1', 'administrationId' => 'adm-1', 'claimId' => 'c-posted', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'totalAmount' => 23.10, 'journeyDate' => '2026-10-04'],
				],
				'PerDiem' => [],
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$dedup = $this->createMock(InvoiceDeduplicationService::class);
		$dedup->method('deduplicateSourceIds')->willReturnCallback(
			function (string $administrationId, array $timeEntryIds, array $expenseIds): array {
				$time = array_values(array_intersect($timeEntryIds, $this->billed['timeEntryIds']));
				$exp = array_values(array_intersect($expenseIds, $this->billed['expenseIds']));
				if ($time === [] && $exp === []) {
					return ['hasConflicts' => false, 'conflicts' => []];
				}

				return ['hasConflicts' => true, 'conflicts' => [['invoiceId' => 'inv-31', 'invoiceNumber' => '2026-T-0031', 'status' => 'draft', 'timeEntryIds' => $time, 'expenseIds' => $exp]]];
			}
		);

		return new BillableWorkService(objectService: $store, appConfig: $appConfig, hoursSource: $this->hours, dedup: $dedup, rates: $this->rates);
	}//end service()

	/**
	 * A month of hours priced on the rate card totals EUR 1,187.50 (the spec's scenario).
	 *
	 * @return void
	 */
	public function testAMonthOfHoursIsListedWithItsRateAndAmount(): void {
		$this->rates->method('resolveRate')->with('rc-2026', 'senior_consultant', $this->anything(), 'adm-1')
			->willReturn(['rateCents' => 9500, 'currency' => 'EUR', 'rateCardVersion' => '1', 'effectiveDate' => '2026-01-01']);

		$work = $this->service()->billableWork(administrationId: 'adm-1', customerId: 'anker', projectId: 'p-kade', from: '2026-10-01', to: '2026-10-31', rateCardId: 'rc-2026');

		$this->assertTrue($work['hours']['available']);
		$this->assertSame(['h-1', 'h-2'], array_column($work['hours']['entries'], 'id'));
		$this->assertSame([712.5, 475.0], array_column($work['hours']['entries'], 'amount'));
		$this->assertSame(1187.5, array_sum(array_column($work['hours']['entries'], 'amount')));
		$this->assertSame(95.0, $work['hours']['entries'][0]['rate']);
	}//end testAMonthOfHoursIsListedWithItsRateAndAmount()

	/**
	 * Hours and items a draft or posted invoice references are left out.
	 *
	 * @return void
	 */
	public function testBilledHoursAndItemsAreLeftOut(): void {
		$this->billed = ['timeEntryIds' => ['h-1', 'h-2'], 'expenseIds' => ['r-train']];

		$work = $this->service()->billableWork(administrationId: 'adm-1', customerId: 'anker', projectId: 'p-kade', from: '2026-10-01', to: '2026-10-31');

		$this->assertSame([], $work['hours']['entries']);
		$this->assertTrue($work['hours']['available'], 'Every hour billed is an empty list, not unavailable hours.');
		$this->assertSame(['r-parking', 'm-1'], array_column($work['expenses'], 'id'));
	}//end testBilledHoursAndItemsAreLeftOut()

	/**
	 * Only pass-through items of the customer on approved or posted claims of the administration are listed,
	 * each with cost, locked markup and amount.
	 *
	 * @return void
	 */
	public function testOnlyTheCustomersPassThroughItemsOfBillableClaimsAreListed(): void {
		$work = $this->service()->billableWork(administrationId: 'adm-1', customerId: 'anker', projectId: '', from: '', to: '');

		$this->assertSame(['r-train', 'r-parking', 'm-1'], array_column($work['expenses'], 'id'));
		$train = $work['expenses'][0];
		$this->assertSame(['Receipt', 'c-posted', 'DEC-7', 42.8, 10.0, 4.28, 47.08], [
			$train['kind'], $train['claimId'], $train['claimNumber'], $train['cost'], $train['markupRate'], $train['markup'], $train['amount'],
		]);
		$this->assertSame(12.5, $work['expenses'][1]['amount'], 'Without a markup rule the item is billed at cost.');
		$this->assertSame([], $work['hours']['entries'], 'No project, no hours.');
	}//end testOnlyTheCustomersPassThroughItemsOfBillableClaimsAreListed()

	/**
	 * A project of another customer offers no hours, and the source is not asked.
	 *
	 * @return void
	 */
	public function testAProjectOfAnotherCustomerOffersNoHours(): void {
		$this->hours = $this->createMock(BillableHoursSource::class);
		$this->hours->expects($this->never())->method('unbilled');

		$work = $this->service()->billableWork(administrationId: 'adm-1', customerId: 'anker', projectId: 'p-other', from: '2026-10-01', to: '2026-10-31');

		$this->assertSame([], $work['hours']['entries']);
	}//end testAProjectOfAnotherCustomerOffersNoHours()

	/**
	 * Without an hours source the hours are unavailable with the reason, not zero hours.
	 *
	 * @return void
	 */
	public function testWithoutAnHoursSourceTheHoursAreUnavailable(): void {
		$this->hours = $this->createMock(BillableHoursSource::class);
		$this->hours->method('unbilled')->willReturn(['available' => false, 'reason' => 'The hours app is not connected.', 'hours' => []]);

		$work = $this->service()->billableWork(administrationId: 'adm-1', customerId: 'anker', projectId: 'p-kade', from: '2026-10-01', to: '2026-10-31');

		$this->assertSame(['available' => false, 'reason' => 'The hours app is not connected.', 'entries' => []], $work['hours']);
	}//end testWithoutAnHoursSourceTheHoursAreUnavailable()

	/**
	 * Without an administration or a customer nothing is read.
	 *
	 * @return void
	 */
	public function testNoAdministrationOrCustomerListsNothing(): void {
		$this->hours = $this->createMock(BillableHoursSource::class);
		$this->hours->expects($this->never())->method('unbilled');
		$service = $this->service();

		$this->assertSame([], $service->billableWork(administrationId: '', customerId: 'anker', projectId: 'p-kade', from: '', to: '')['expenses']);
		$this->assertSame([], $service->billableWork(administrationId: 'adm-1', customerId: '', projectId: 'p-kade', from: '', to: '')['expenses']);
	}//end testNoAdministrationOrCustomerListsNothing()
}//end class
