<?php

/**
 * Unit tests for InvoiceNumberSequenceService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use InvalidArgumentException;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-SIIC-001: the next number of a series, under a lock, never twice.
 *
 * @covers \OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceNumberPattern
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class InvoiceNumberSequenceServiceTest extends TestCase {
	/**
	 * The lock calls the service made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $lockCalls = [];

	/**
	 * A service over the given series rows.
	 *
	 * @param array<int, array<string, mixed>> $series    The InvoiceNumberSequence rows.
	 * @param InMemoryObjectServiceStub|null   $store     Receives the store, for assertions.
	 * @param int                              $busyTimes How often the lock is held by another caller first.
	 *
	 * @return InvoiceNumberSequenceService The service.
	 */
	private function service(array $series, ?InMemoryObjectServiceStub &$store = null, int $busyTimes = 0): InvoiceNumberSequenceService {
		$store = new InMemoryObjectServiceStub(data: ['InvoiceNumberSequence' => $series]);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			function (string $path, int $type) use (&$busyTimes): void {
				$this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
				$this->lockCalls[] = 'acquire ' . $path;
				if ($busyTimes > 0) {
					$busyTimes--;
					throw new LockedException($path);
				}
			}
		);
		$locks->method('releaseLock')->willReturnCallback(
			function (string $path, int $type): void {
				$this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
				$this->lockCalls[] = 'release ' . $path;
			}
		);

		return new InvoiceNumberSequenceService(objectService: $store, registerLocation: $location, locks: $locks, lockPause: 0);
	}//end service()

	/**
	 * The seed scenario: Adviesbureau Van Dijk's 2026 sales series at 41.
	 *
	 * @return array<string, mixed>
	 */
	private function vanDijk2026(): array {
		return ['id' => 'seq-1', 'administrationId' => 'adm-vd', 'kind' => 'sales', 'pattern' => '{year}-{seq:4}', 'fiscalYear' => 2026, 'lastValue' => 41];
	}//end vanDijk2026()

	/**
	 * Two issues one after the other get 2026-0042 and 2026-0043, and the counter is written.
	 *
	 * @return void
	 */
	public function testTwoIssuesGetTheNextTwoNumbers(): void {
		$service = $this->service(series: [$this->vanDijk2026()], store: $store);

		$this->assertSame('2026-0042', $service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026));
		$this->assertSame('2026-0043', $service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026));

		$this->assertSame(43, $store->saved[1]['object']['lastValue']);
		$this->assertSame('InvoiceNumberSequence', $store->saved[1]['schema']);
		$this->assertSame('seq-1', $store->saved[1]['object']['id']);
	}//end testTwoIssuesGetTheNextTwoNumbers()

	/**
	 * Every number is taken between acquiring and releasing the series' own lock.
	 *
	 * @return void
	 */
	public function testTheNumberIsTakenUnderTheSeriesLock(): void {
		$service = $this->service(series: [$this->vanDijk2026()]);

		$service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026);

		$this->assertSame(
			['acquire shillinq-invoice-seq-adm-vd-sales', 'release shillinq-invoice-seq-adm-vd-sales'],
			$this->lockCalls
		);
	}//end testTheNumberIsTakenUnderTheSeriesLock()

	/**
	 * A caller that finds the lock held waits for it, then gets the next number.
	 *
	 * @return void
	 */
	public function testASecondCallerWaitsForTheLockAndGetsTheNextNumber(): void {
		$service = $this->service(series: [$this->vanDijk2026()], busyTimes: 2);

		$this->assertSame('2026-0042', $service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026));
		$this->assertSame(3, count(array_filter($this->lockCalls, static fn (string $call): bool => str_starts_with($call, 'acquire'))));
		$this->assertSame('release shillinq-invoice-seq-adm-vd-sales', end($this->lockCalls));
	}//end testASecondCallerWaitsForTheLockAndGetsTheNextNumber()

	/**
	 * A new year starts at 1 with the pattern the kind used last year.
	 *
	 * @return void
	 */
	public function testANewYearStartsAtOneWithLastYearsPattern(): void {
		$series = [['id' => 'seq-9', 'administrationId' => 'adm-vd', 'kind' => 'sales', 'pattern' => 'VD{year}{seq:5}', 'fiscalYear' => 2026, 'lastValue' => 812]];
		$service = $this->service(series: $series, store: $store);

		$this->assertSame('VD202700001', $service->next(administrationId: 'adm-vd', kind: 'sales', year: 2027));

		$created = $store->saved[0]['object'];
		$this->assertSame(['adm-vd', 'sales', 'VD{year}{seq:5}', 2027, 1], [$created['administrationId'], $created['kind'], $created['pattern'], $created['fiscalYear'], $created['lastValue']]);
	}//end testANewYearStartsAtOneWithLastYearsPattern()

	/**
	 * A kind without any series starts from its own default pattern.
	 *
	 * @return void
	 */
	public function testAKindWithoutASeriesStartsFromItsDefaultPattern(): void {
		$service = $this->service(series: [$this->vanDijk2026()]);

		$this->assertSame('BIL-2026-0001', $service->next(administrationId: 'adm-vd', kind: 'billable', year: 2026));
		$this->assertSame('CN-2026-0001', $service->next(administrationId: 'adm-vd', kind: 'credit', year: 2026));
		$this->assertSame('2026-0001', $service->next(administrationId: 'adm-other', kind: 'sales', year: 2026));
	}//end testAKindWithoutASeriesStartsFromItsDefaultPattern()

	/**
	 * A lock that stays held refuses the number rather than handing one out unlocked.
	 *
	 * @return void
	 */
	public function testALockThatStaysHeldRefusesTheNumber(): void {
		$service = $this->service(series: [$this->vanDijk2026()], store: $store, busyTimes: 1000);

		try {
			$service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026);
			$this->fail('A held lock must refuse the number.');
		} catch (RuntimeException $e) {
			$this->assertSame('The invoice number series is busy. Try issuing the invoice again.', $e->getMessage());
		}

		$this->assertSame([], $store->saved);
		$this->assertNotContains('release shillinq-invoice-seq-adm-vd-sales', $this->lockCalls);
	}//end testALockThatStaysHeldRefusesTheNumber()

	/**
	 * An empty administration or an unknown kind is refused before any lock.
	 *
	 * @return void
	 */
	public function testAnEmptyAdministrationOrUnknownKindIsRefused(): void {
		$service = $this->service(series: []);

		try {
			$service->next(administrationId: ' ', kind: 'sales', year: 2026);
			$this->fail('An empty administration must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('An invoice number needs an administration.', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Unknown invoice number series 'purchase'.");
		try {
			$service->next(administrationId: 'adm-vd', kind: 'purchase', year: 2026);
		} finally {
			$this->assertSame([], $this->lockCalls);
		}
	}//end testAnEmptyAdministrationOrUnknownKindIsRefused()

	/**
	 * Seeding raises a series but never lowers it, and creates a missing one.
	 *
	 * @return void
	 */
	public function testSeedingRaisesButNeverLowersASeries(): void {
		$service = $this->service(series: [$this->vanDijk2026()], store: $store);

		$this->assertFalse($service->seed(administrationId: 'adm-vd', kind: 'sales', year: 2026, lastValue: 12));
		$this->assertSame([], $store->saved);

		$this->assertTrue($service->seed(administrationId: 'adm-vd', kind: 'sales', year: 2026, lastValue: 57));
		$this->assertSame(57, $store->saved[0]['object']['lastValue']);

		$this->assertTrue($service->seed(administrationId: 'adm-vd', kind: 'billable', year: 2026, lastValue: 3));
		$this->assertSame(['billable', 'BIL-{year}-{seq:4}', 3], [$store->saved[1]['object']['kind'], $store->saved[1]['object']['pattern'], $store->saved[1]['object']['lastValue']]);

		$this->assertSame('2026-0058', $service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026));
	}//end testSeedingRaisesButNeverLowersASeries()

	/**
	 * A row of another administration or kind is never taken for the series.
	 *
	 * @return void
	 */
	public function testARowOfAnotherAdministrationIsNeverTaken(): void {
		$other = ['id' => 'seq-x', 'administrationId' => 'adm-x', 'kind' => 'sales', 'pattern' => 'X-{year}-{seq:4}', 'fiscalYear' => 2026, 'lastValue' => 900];
		$service = $this->service(series: [$other]);

		$this->assertSame('{year}-{seq:4}', $service->patternOf(administrationId: 'adm-vd', kind: 'sales'));
		$this->assertSame('2026-0001', $service->next(administrationId: 'adm-vd', kind: 'sales', year: 2026));
	}//end testARowOfAnotherAdministrationIsNeverTaken()
}//end class
